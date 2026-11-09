<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Backup\BackupMetadata;
use App\Core\Backup\DatabaseBackupService;
use App\Core\Backup\DatabaseRestoreService;
use App\Infrastructure\Persistence\PostgresConnectionPolicy;
use App\Install\Installer;
use App\Support\Database;

function audit_backup_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function audit_backup_schema(PDO $pdo): array
{
    return [
        'owner' => $pdo->query(
            'SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = current_schema()',
        )->fetchColumn(),
        'grants' => $pdo->query(
            "SELECT a.grantee::text, a.privilege_type, a.is_grantable
             FROM pg_namespace n,
                  LATERAL aclexplode(COALESCE(n.nspacl, acldefault('n', n.nspowner))) a
             WHERE n.nspname = current_schema()
             ORDER BY a.grantee, a.privilege_type, a.is_grantable",
        )->fetchAll(PDO::FETCH_ASSOC),
    ];
}

$environmentNames = [
    'CPE_DB_DRIVER', 'CPE_DATABASE_URL', 'CPE_PG_HOST', 'CPE_PG_PORT', 'CPE_PG_DATABASE',
    'CPE_PG_USER', 'CPE_PG_PASSWORD', 'CPE_PG_SSLMODE', 'CPE_PG_SSLROOTCERT',
    'CPE_PG_CONNECT_TIMEOUT', 'CPE_POSTGRES_POOL_MODE', 'CPE_POSTGRES_ALLOW_INSECURE_LOOPBACK',
    'CPE_BACKUP_DIR',
];
$savedEnvironment = [];
foreach ($environmentNames as $name) {
    $savedEnvironment[$name] = getenv($name);
}
$postgresConfigured = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
$restoreEnvironment = static function () use ($savedEnvironment): void {
    foreach ($savedEnvironment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
};
$root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/cpe-audit-backups-' . bin2hex(random_bytes(6));
audit_backup_assert(mkdir($root, 0700), 'Could not create backup regression storage.');

try {
    foreach ($environmentNames as $name) {
        putenv($name);
    }
    foreach ([
        'CPE_DB_DRIVER' => 'pgsql', 'CPE_PG_HOST' => '127.0.0.1', 'CPE_PG_PORT' => '5432',
        'CPE_PG_DATABASE' => 'component_backup_regression', 'CPE_PG_USER' => 'component_user',
        'CPE_PG_PASSWORD' => 'synthetic-component-password', 'CPE_PG_SSLMODE' => 'disable',
        'CPE_POSTGRES_ALLOW_INSECURE_LOOPBACK' => '1',
    ] as $name => $value) {
        putenv($name . '=' . $value);
    }
    foreach ([new DatabaseBackupService(new PDO('sqlite::memory:')), new DatabaseRestoreService()] as $service) {
        $connection = (new ReflectionMethod($service, 'postgresCommandConnection'))->invoke($service);
        audit_backup_assert(
            $connection->safeUri() === 'postgresql://component_user@127.0.0.1:5432/component_backup_regression',
            'Backup or restore refused supported component-only PostgreSQL configuration.',
        );
    }
    $restoreEnvironment();
    echo "PASS component-only PostgreSQL backup and restore configuration\n";
    if (!$postgresConfigured) {
        echo "SKIP PostgreSQL exact restore regression: configure a fresh dedicated PostgreSQL database.\n";
        return;
    }

    $pdo = Database::connection();
    audit_backup_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()')->fetchColumn() === 0,
        'Backup regressions require a fresh dedicated PostgreSQL database.',
    );
    putenv('CPE_BACKUP_DIR=' . $root . '/backups');
    (new Installer())->install([
        'college_name' => 'Backup Regression College', 'timezone' => 'UTC',
        'admin_name' => 'Backup Regression Admin', 'admin_email' => 'backup-regression@example.test',
        'admin_password' => 'synthetic-backup-regression-password',
    ]);
    $pdo = Database::connection();
    $identity = BackupMetadata::databaseIdentity($pdo, 'pgsql');
    $schema = (string) $pdo->query('SELECT current_schema()')->fetchColumn();
    $quotedSchema = '"' . str_replace('"', '""', $schema) . '"';
    $pdo->exec('REVOKE ALL ON SCHEMA ' . $quotedSchema . ' FROM PUBLIC');
    $pdo->exec('GRANT USAGE ON SCHEMA ' . $quotedSchema . ' TO pg_read_all_data');
    $schemaBefore = audit_backup_schema($pdo);
    $pdo->exec("INSERT INTO settings(key, value) VALUES ('audit_restore_marker', 'before-backup')");

    // Preserve the exact resolved endpoint/TLS settings while changing from URL
    // to the other documented runtime configuration shape.
    $spec = PostgresConnectionPolicy::commandConnectionFromEnvironment();
    $parts = parse_url($spec->safeUri());
    audit_backup_assert(is_array($parts), 'Could not resolve the regression connection.');
    $child = $spec->childEnvironment([]);
    putenv('CPE_DATABASE_URL');
    foreach ([
        'CPE_DB_DRIVER' => 'pgsql', 'CPE_PG_HOST' => $parts['host'], 'CPE_PG_PORT' => (string) $parts['port'],
        'CPE_PG_DATABASE' => rawurldecode(ltrim($parts['path'], '/')),
        'CPE_PG_USER' => rawurldecode($parts['user']), 'CPE_PG_PASSWORD' => $child['PGPASSWORD'],
        'CPE_PG_SSLMODE' => $child['PGSSLMODE'], 'CPE_PG_CONNECT_TIMEOUT' => $child['PGCONNECT_TIMEOUT'],
    ] as $name => $value) {
        putenv($name . '=' . $value);
    }
    isset($child['PGSSLROOTCERT']) ? putenv('CPE_PG_SSLROOTCERT=' . $child['PGSSLROOTCERT']) : putenv('CPE_PG_SSLROOTCERT');
    Database::reset();
    $pdo = Database::connection();
    $backup = (new DatabaseBackupService())->create('components');
    $pdo->exec("UPDATE settings SET value = 'after-backup' WHERE key = 'audit_restore_marker'");
    $pdo->exec('CREATE TABLE audit_later_standalone(id INTEGER PRIMARY KEY, marker TEXT)');
    $pdo->exec("INSERT INTO audit_later_standalone VALUES (1, 'not-in-backup')");
    $pdo->exec('CREATE TABLE audit_later_foreign_key(id INTEGER PRIMARY KEY, institution_id BIGINT REFERENCES institutions(id))');
    $pdo->exec('INSERT INTO audit_later_foreign_key SELECT 1, id FROM institutions LIMIT 1');
    (new DatabaseRestoreService())->restore($backup->internalPath());
    $pdo = Database::connection();
    audit_backup_assert($pdo->query("SELECT value FROM settings WHERE key = 'audit_restore_marker'")->fetchColumn() === 'before-backup', 'Restore did not restore the backed-up data.');
    audit_backup_assert($pdo->query("SELECT to_regclass('audit_later_standalone')")->fetchColumn() === null, 'Restore retained a table absent from the backup.');
    audit_backup_assert($pdo->query("SELECT to_regclass('audit_later_foreign_key')")->fetchColumn() === null, 'Restore retained later foreign-key dependencies.');
    audit_backup_assert(BackupMetadata::databaseIdentity($pdo, 'pgsql') === $identity, 'Restore changed the installed database identity.');
    audit_backup_assert(audit_backup_schema($pdo) === $schemaBefore, 'Restore changed schema ownership or privileges.');
    echo "PASS exact PostgreSQL restore after later tables and foreign keys, with component-only configuration\n";

    // Metadata can be relabelled without proving the actual archive identity.
    // Reject that archive before committing, retaining the complete live schema.
    $foreignId = 'inst_' . bin2hex(random_bytes(16));
    $changeIdentity = $pdo->prepare("UPDATE institutions SET public_id = ? WHERE slug = 'default'");
    $changeIdentity->execute([$foreignId]);
    try {
        $forged = (new DatabaseBackupService())->create('foreign-identity');
    } finally {
        $changeIdentity->execute([$identity['institution_public_id']]);
    }
    $metadata = BackupMetadata::read($forged->internalMetadataPath());
    $metadata['institution_public_id'] = $identity['institution_public_id'];
    file_put_contents($forged->internalMetadataPath(), json_encode($metadata, JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($forged->internalChecksumPath(),
        hash_file('sha256', $forged->internalPath()) . '  ' . $forged->fileName() . "\n"
        . hash_file('sha256', $forged->internalMetadataPath()) . '  ' . basename($forged->internalMetadataPath()) . "\n",
    );
    $pdo->exec("UPDATE settings SET value = 'live-state' WHERE key = 'audit_restore_marker'");
    $pdo->exec('CREATE TABLE audit_live_dependency(id INTEGER PRIMARY KEY, institution_id BIGINT REFERENCES institutions(id))');
    $pdo->exec('INSERT INTO audit_live_dependency SELECT 1, id FROM institutions LIMIT 1');
    try {
        (new DatabaseRestoreService())->restore($forged->internalPath());
        throw new RuntimeException('A relabelled PostgreSQL archive was accepted.');
    } catch (RuntimeException $e) {
        audit_backup_assert($e->getMessage() === 'PostgreSQL restore process failed.', 'Relabelled archive did not fail inside the atomic restore.');
    }
    $pdo = Database::connection();
    audit_backup_assert($pdo->query("SELECT value FROM settings WHERE key = 'audit_restore_marker'")->fetchColumn() === 'live-state', 'Failed restore committed archive data.');
    audit_backup_assert((int) $pdo->query('SELECT COUNT(*) FROM audit_live_dependency')->fetchColumn() === 1, 'Failed restore lost a live table or its foreign key.');
    audit_backup_assert(BackupMetadata::databaseIdentity($pdo, 'pgsql') === $identity, 'Failed restore changed the installed identity.');
    audit_backup_assert(audit_backup_schema($pdo) === $schemaBefore, 'Failed restore changed schema privileges.');
    echo "PASS PostgreSQL restore identity verification rolls back all schema and data changes\n";
} finally {
    Database::reset();
    $restoreEnvironment();
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
