<?php

declare(strict_types=1);

$temporarySqlite = null;
if (trim((string) (getenv('CPE_DATABASE_URL') ?: '')) === ''
    && !in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true)) {
    $temporarySqlite = sys_get_temp_dir() . '/cpe-hosted-install-' . bin2hex(random_bytes(4)) . '.sqlite';
    putenv('CPE_DB_PATH=' . $temporarySqlite);
}

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Hosted\HostedBootstrap;
use App\Hosted\Tenant\HostedResolutionException;
use App\Install\Installer;
use App\Support\Database;

function hosted_install_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function hosted_install_tables(PDO $pdo): array
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'pgsql') {
        $tables = $pdo->query(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename',
        )->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        )->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_map('strval', $tables);
}

/** @return array<string, mixed> */
function hosted_install_snapshot(PDO $pdo): array
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'pgsql') {
        $schema = [
            'relations' => $pdo->query(
                "SELECT c.relkind, c.relname
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = current_schema()
                   AND c.relkind IN ('r', 'p', 'v', 'm', 'S')
                 ORDER BY c.relkind, c.relname",
            )->fetchAll(PDO::FETCH_ASSOC),
            'columns' => $pdo->query(
                "SELECT table_name, column_name, ordinal_position, column_default, is_nullable, data_type, udt_name
                 FROM information_schema.columns
                 WHERE table_schema = current_schema()
                 ORDER BY table_name, ordinal_position",
            )->fetchAll(PDO::FETCH_ASSOC),
            'indexes' => $pdo->query(
                'SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() ORDER BY tablename, indexname',
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
    } else {
        $schema = $pdo->query(
            "SELECT type, name, tbl_name, sql
             FROM sqlite_master
             WHERE name NOT LIKE 'sqlite_%'
             ORDER BY type, name",
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $rows = [];
    foreach (hosted_install_tables($pdo) as $table) {
        hosted_install_assert(preg_match('/\A[a-z_]+\z/D', $table) === 1, 'Unexpected table identifier.');
        $tableRows = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tableRows as &$row) {
            ksort($row);
        }
        unset($row);
        usort($tableRows, static fn (array $left, array $right): int => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR),
            json_encode($right, JSON_THROW_ON_ERROR),
        ));
        $rows[$table] = $tableRows;
    }

    return ['schema' => $schema, 'rows' => $rows];
}

/** @return array<string, mixed> */
function hosted_install_input(): array
{
    return [
        'college_name' => 'Self-hosted Identity Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'Self-hosted Contract Administrator',
        'admin_email' => 'self-hosted-contract-admin@example.test',
        'admin_password' => 'hosted-contract-password-123',
        'seed_demo' => '1',
    ];
}

/** @return list<string> */
function hosted_install_production_files(): array
{
    $files = [__DIR__ . '/../placement'];
    foreach ([__DIR__ . '/../app', __DIR__ . '/../public'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    return $files;
}

try {
    hosted_install_assert(HostedBootstrap::CONTRACT_VERSION === 2, 'Unexpected managed-hosting contract version.');
    hosted_install_assert(
        !(new ReflectionClass(HostedBootstrap::class))->hasMethod('bindInstalledDataPlane'),
        'Managed-hosting contract v2 still exposes the legacy public identity-rebinding method.',
    );
    foreach (hosted_install_production_files() as $file) {
        $source = file_get_contents($file);
        hosted_install_assert(is_string($source), 'Could not inspect a production source file.');
        hosted_install_assert(
            !str_contains($source, 'bindInstalledDataPlane')
                && !str_contains($source, 'IDENTITY_BINDING'),
            'Production source still references the removed hosted identity-rebinding path.',
        );
    }

    hosted_install_assert(!Database::isInstalled(), 'Hosted identity contract requires an empty database.');
    Database::migrate();
    $installer = new Installer();
    $installer->install(hosted_install_input());
    hosted_install_assert(Database::isInstalled(), 'Self-hosted installation did not commit its installation marker.');

    $pdo = Database::connection();
    $originalIdentity = (string) $pdo
        ->query("SELECT public_id FROM institutions WHERE slug = 'default'")
        ->fetchColumn();
    hosted_install_assert(
        preg_match('/\Ainst_[a-f0-9]{32}\z/D', $originalIdentity) === 1,
        'Self-hosted install did not create the expected immutable institution identity.',
    );
    $installedState = hosted_install_snapshot($pdo);

    $intendedTenantPublicId = 'tenant_' . str_repeat('a', 32);
    $wrongTenantPublicId = 'tenant_' . str_repeat('b', 32);
    $malformedTenantPublicId = 'tenant_' . str_repeat('A', 32);
    foreach ([$intendedTenantPublicId, $wrongTenantPublicId, $malformedTenantPublicId] as $tenantPublicId) {
        try {
            HostedBootstrap::assertDataPlaneIdentity($tenantPublicId);
            throw new RuntimeException('An installed self-hosted database was accepted as a hosted tenant.');
        } catch (HostedResolutionException $e) {
            hosted_install_assert(
                str_contains($e->getMessage(), 'does not match'),
                'Hosted identity verification returned an unexpected fixed failure.',
            );
        }
        hosted_install_assert(
            hosted_install_snapshot($pdo) === $installedState,
            'Hosted identity verification mutated schema, migrations, ownership, identity, or product rows.',
        );
    }

    foreach ([$intendedTenantPublicId, $wrongTenantPublicId, $malformedTenantPublicId] as $tenantPublicId) {
        try {
            $installer->installHosted(hosted_install_input(), $tenantPublicId);
            throw new RuntimeException('Direct installHosted relabeled an installed self-hosted database.');
        } catch (RuntimeException $e) {
            if ($tenantPublicId === $malformedTenantPublicId) {
                hosted_install_assert(
                    str_contains($e->getMessage(), 'invalid'),
                    'Malformed hosted identity returned an unexpected fixed failure.',
                );
            } else {
                hosted_install_assert(
                    str_contains($e->getMessage(), Installer::ERROR_ALREADY_INSTALLED),
                    'Installed hosted retry did not return the stable installed conflict.',
                );
            }
        }
        hosted_install_assert(
            hosted_install_snapshot($pdo) === $installedState,
            'Direct installHosted retry mutated schema, migrations, ownership, identity, or product rows.',
        );
    }

    hosted_install_assert(
        (string) $pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn()
            === $originalIdentity,
        'An installed self-hosted institution identity was relabeled.',
    );

    echo 'PASS hosted identity immutability contract (' . Database::driver() . ' ' . Database::serverVersion() . ")\n";
} finally {
    Database::reset();
    if ($temporarySqlite !== null && is_file($temporarySqlite)) {
        unlink($temporarySqlite);
    }
    if ($temporarySqlite !== null) {
        putenv('CPE_DB_PATH');
    }
}
