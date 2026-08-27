<?php

declare(strict_types=1);

$preflightRoot = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
    . '/cpe-hosted-install-preflight-' . bin2hex(random_bytes(6));
if (!mkdir($preflightRoot, 0700)) {
    throw new RuntimeException('Could not create hosted install preflight fixture directory.');
}
$preflightDatabase = $preflightRoot . '/installed-legacy.sqlite';
putenv('CPE_DATABASE_URL');
putenv('CPE_DB_DRIVER=sqlite');
putenv('CPE_DB_PATH=' . $preflightDatabase);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Install\Installer;
use App\Support\Database;

function hosted_preflight_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function hosted_preflight_input(): array
{
    return [
        'college_name' => 'Preflight Mutation Attempt',
        'timezone' => 'UTC',
        'admin_name' => 'Preflight Mutation Administrator',
        'admin_email' => 'preflight-mutation@example.test',
        'admin_password' => 'preflight-mutation-password-123',
        'seed_demo' => '1',
    ];
}

/** @return array<string, mixed> */
function hosted_preflight_snapshot(PDO $pdo): array
{
    $schema = $pdo->query(
        "SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name",
    )->fetchAll(PDO::FETCH_ASSOC);
    $tables = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
    )->fetchAll(PDO::FETCH_COLUMN);
    $rows = [];
    foreach (array_map('strval', $tables) as $table) {
        hosted_preflight_assert(preg_match('/\A[a-z_]+\z/D', $table) === 1, 'Unexpected fixture table identifier.');
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

function hosted_preflight_apply_legacy_schema(PDO $pdo): void
{
    $files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
    sort($files);
    foreach ($files as $file) {
        $name = basename($file);
        $number = (int) substr($name, 0, 3);
        if ($number > 41) {
            continue;
        }
        $sql = file_get_contents($file);
        hosted_preflight_assert(is_string($sql), 'Could not read legacy migration fixture ' . $name . '.');
        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $record = $pdo->prepare(
                'INSERT INTO migrations (migration, applied_at) VALUES (?, ?) ON CONFLICT(migration) DO NOTHING',
            );
            $record->execute([$name, '2026-01-01 00:00:00']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

function hosted_preflight_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new DirectoryIterator($directory) as $item) {
        if (!$item->isDot()) {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

try {
    $pdo = Database::connection();
    hosted_preflight_apply_legacy_schema($pdo);
    $sameTenant = 'tenant_' . str_repeat('e', 32);
    $wrongTenant = 'tenant_' . str_repeat('f', 32);
    $setting = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    foreach ([
        'installed_at' => '2026-01-01 00:00:00',
        'college_name' => 'Installed Legacy College',
        'timezone' => 'UTC',
    ] as $key => $value) {
        $setting->execute([$key, $value]);
    }
    $identity = $pdo->prepare("UPDATE institutions SET public_id = ? WHERE slug = 'default'");
    $identity->execute([$sameTenant]);
    $now = '2026-01-01 00:00:00';
    $pdo->prepare(
        'INSERT INTO companies (code, name, slot, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
    )->execute(['LEGACY', 'Legacy Company', 'Legacy Slot', $now, $now]);
    $companyId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO candidates (external_id, name, program, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
    )->execute(['LEGACY001', 'Legacy Candidate', 'Legacy Program', $now, $now]);
    $candidateId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO applications (candidate_id, company_id, current_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
    )->execute([$candidateId, $companyId, 'idle', $now, $now]);

    hosted_preflight_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM migrations WHERE CAST(substr(migration, 1, 3) AS INTEGER) > 41")->fetchColumn() === 0,
        'Legacy fixture unexpectedly contains current migrations.',
    );
    hosted_preflight_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cpe_database_ownership'")->fetchColumn() === 0,
        'Legacy fixture unexpectedly contains database ownership state.',
    );
    $baseline = hosted_preflight_snapshot($pdo);
    foreach ([$sameTenant, $wrongTenant] as $retryTenant) {
        try {
            (new Installer())->installHosted(hosted_preflight_input(), $retryTenant);
            throw new RuntimeException('Installed pre-current database accepted direct installHosted retry.');
        } catch (RuntimeException $e) {
            hosted_preflight_assert(
                str_contains($e->getMessage(), Installer::ERROR_ALREADY_INSTALLED),
                'Installed pre-current retry did not return the stable installed conflict.',
            );
        }
        hosted_preflight_assert(
            hosted_preflight_snapshot($pdo) === $baseline,
            'Installed pre-current retry mutated schema, migrations, ownership, identity, settings, or product rows.',
        );
    }

    $installerSource = (string) file_get_contents(__DIR__ . '/../app/Install/Installer.php');
    $preflightPosition = strpos($installerSource, 'Database::hasInstalledMarkerStrict()');
    $migrationPosition = strpos($installerSource, 'Database::migrate(false)');
    hosted_preflight_assert(
        $preflightPosition !== false
            && $migrationPosition !== false
            && $preflightPosition < $migrationPosition,
        'Strict installed-marker preflight must run before migration or ownership work.',
    );

    Database::reset();
    $malformedDatabase = $preflightRoot . '/malformed-probe.sqlite';
    putenv('CPE_DB_PATH=' . $malformedDatabase);
    $malformedPdo = Database::connection();
    $malformedPdo->exec('CREATE TABLE settings (unexpected TEXT NOT NULL)');
    $malformedBaseline = hosted_preflight_snapshot($malformedPdo);
    try {
        (new Installer())->installHosted(hosted_preflight_input(), $sameTenant);
        throw new RuntimeException('Malformed installed-marker probe unexpectedly proceeded.');
    } catch (Throwable $e) {
        hosted_preflight_assert(
            !str_contains($e->getMessage(), Installer::ERROR_ALREADY_INSTALLED),
            'Malformed probe was silently reclassified as an installed database.',
        );
    }
    hosted_preflight_assert(
        hosted_preflight_snapshot($malformedPdo) === $malformedBaseline,
        'Unexpected installed-marker probe failure mutated the database.',
    );

    echo "PASS hosted install strict preflight preserves installed pre-current databases\n";
} finally {
    Database::reset();
    putenv('CPE_DB_PATH');
    putenv('CPE_DB_DRIVER');
    hosted_preflight_remove_tree($preflightRoot);
}
