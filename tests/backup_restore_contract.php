<?php

declare(strict_types=1);

$backupContractRoot = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
    . '/cpe-backup-restore-' . bin2hex(random_bytes(6));
if (!mkdir($backupContractRoot, 0700)) {
    throw new RuntimeException('Could not create backup/restore contract directory.');
}
$backupContractSqlite = null;
if (trim((string) (getenv('CPE_DATABASE_URL') ?: '')) === ''
    && !in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true)) {
    $backupContractSqlite = $backupContractRoot . '/target.sqlite';
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DB_PATH=' . $backupContractSqlite);
}
$backupContractDirectory = $backupContractRoot . '/backups';
putenv('CPE_BACKUP_DIR=' . $backupContractDirectory);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Backup\BackupMetadata;
use App\Core\Backup\DatabaseBackupService;
use App\Core\Backup\DatabaseRestoreService;
use App\Core\Http\UserVisibleException;
use App\Install\Installer;
use App\Support\Database;

function backup_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function backup_contract_tables(PDO $pdo): array
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $tables = $driver === 'pgsql'
        ? $pdo->query('SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename')->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('strval', $tables);
}

/** @return array<string, mixed> */
function backup_contract_snapshot(PDO $pdo): array
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $schema = $driver === 'pgsql'
        ? [
            'columns' => $pdo->query(
                'SELECT table_name, column_name, ordinal_position, column_default, is_nullable, data_type, udt_name '
                . 'FROM information_schema.columns WHERE table_schema = current_schema() ORDER BY table_name, ordinal_position',
            )->fetchAll(PDO::FETCH_ASSOC),
            'indexes' => $pdo->query(
                'SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() ORDER BY tablename, indexname',
            )->fetchAll(PDO::FETCH_ASSOC),
            'sequences' => $pdo->query(
                'SELECT sequencename, last_value FROM pg_sequences WHERE schemaname = current_schema() ORDER BY sequencename',
            )->fetchAll(PDO::FETCH_ASSOC),
        ]
        : $pdo->query(
            "SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name",
        )->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach (backup_contract_tables($pdo) as $table) {
        backup_contract_assert(preg_match('/\A[a-z_]+\z/D', $table) === 1, 'Unexpected backup contract table identifier.');
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

function backup_contract_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

/** @param array<string, mixed> $metadata */
function backup_contract_write_metadata_set(string $archive, array $metadata): void
{
    $metadataPath = $archive . BackupMetadata::SUFFIX;
    file_put_contents(
        $metadataPath,
        json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
    $archiveHash = hash_file('sha256', $archive);
    $metadataHash = hash_file('sha256', $metadataPath);
    backup_contract_assert(is_string($archiveHash) && is_string($metadataHash), 'Could not hash backup contract fixture.');
    file_put_contents(
        $archive . '.sha256',
        $archiveHash . '  ' . basename($archive) . "\n"
        . $metadataHash . '  ' . basename($metadataPath) . "\n",
    );
}

try {
    $pdo = Database::connection();
    $driver = Database::driver();
    if ($driver === 'pgsql') {
        backup_contract_assert(
            (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()',
            )->fetchColumn() === 0,
            'PostgreSQL backup/restore contract requires a fresh dedicated database.',
        );
    }
    (new Installer())->install([
        'college_name' => 'Backup Restore Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'Backup Restore Administrator',
        'admin_email' => 'backup-restore-admin@example.test',
        'admin_password' => 'backup-restore-password-123',
        'seed_demo' => '1',
    ]);
    $pdo = Database::connection();
    $identity = (string) $pdo->query(
        "SELECT public_id FROM institutions WHERE slug = 'default'",
    )->fetchColumn();
    $candidateCount = (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn();
    backup_contract_assert($candidateCount > 0, 'Backup contract installation must include durable product rows.');

    $artifact = (new DatabaseBackupService($pdo))->create('contract', $backupContractDirectory);
    $archive = $artifact->internalPath();
    $metadataPath = $artifact->internalMetadataPath();
    $checksumPath = $artifact->internalChecksumPath();
    backup_contract_assert(is_file($archive), 'Backup archive is missing.');
    backup_contract_assert(is_file($metadataPath), 'Backup metadata sidecar is missing.');
    backup_contract_assert(is_file($checksumPath), 'Backup checksum sidecar is missing.');
    $metadata = BackupMetadata::read($metadataPath);
    backup_contract_assert($metadata['schema'] === BackupMetadata::SCHEMA, 'Backup metadata schema changed.');
    backup_contract_assert($metadata['driver'] === $driver, 'Backup metadata driver is wrong.');
    backup_contract_assert($metadata['institution_public_id'] === $identity, 'Backup metadata identity is wrong.');
    backup_contract_assert($metadata['archive_sha256'] === hash_file('sha256', $archive), 'Backup metadata is not bound to its archive.');
    backup_contract_assert(count(file($checksumPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) === 2, 'Checksum sidecar must bind archive and metadata.');

    $restore = new DatabaseRestoreService();
    $baseline = backup_contract_snapshot($pdo);
    $safetyBefore = glob($backupContractDirectory . '/restore-safety-*.{' . ($driver === 'pgsql' ? 'pgdump' : 'sqlite') . '}', GLOB_BRACE) ?: [];
    $originalMetadata = (string) file_get_contents($metadataPath);
    $originalChecksum = (string) file_get_contents($checksumPath);

    $tamperedMetadata = $metadata;
    $tamperedMetadata['engine_version'] = 'tampered.1';
    file_put_contents(
        $metadataPath,
        json_encode($tamperedMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
    try {
        $restore->restore($archive, $backupContractDirectory);
        throw new RuntimeException('Tampered backup metadata was accepted.');
    } catch (UserVisibleException $e) {
        backup_contract_assert($e->publicCode() === 'DATABASE_BACKUP_CHECKSUM_MISMATCH', 'Metadata tamper used the wrong fixed failure.');
    }
    backup_contract_assert(backup_contract_snapshot($pdo) === $baseline, 'Metadata tamper rejection mutated the target database.');
    backup_contract_assert((glob($backupContractDirectory . '/restore-safety-*') ?: []) === $safetyBefore, 'Metadata tamper created a safety backup before rejection.');

    file_put_contents($metadataPath, $originalMetadata);
    file_put_contents($checksumPath, $originalChecksum);
    $wrongIdentityMetadata = $metadata;
    $wrongIdentityMetadata['institution_public_id'] = 'inst_' . str_repeat('f', 32);
    backup_contract_write_metadata_set($archive, $wrongIdentityMetadata);
    try {
        $restore->restore($archive, $backupContractDirectory);
        throw new RuntimeException('Wrong-identity backup metadata was accepted.');
    } catch (UserVisibleException $e) {
        backup_contract_assert($e->publicCode() === 'DATABASE_BACKUP_IDENTITY_MISMATCH', 'Wrong identity used the wrong fixed failure.');
    }
    backup_contract_assert(backup_contract_snapshot($pdo) === $baseline, 'Wrong-identity rejection mutated the target database.');
    backup_contract_assert((glob($backupContractDirectory . '/restore-safety-*') ?: []) === $safetyBefore, 'Wrong-identity rejection created a safety backup.');

    if ($driver === 'sqlite') {
        $forgedArchive = $backupContractRoot . '/forged.sqlite';
        copy($archive, $forgedArchive);
        $forgedDatabase = new PDO('sqlite:' . $forgedArchive);
        $forgedDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $forgedDatabase->prepare(
            "UPDATE institutions SET public_id = ? WHERE slug = 'default'",
        )->execute(['inst_' . str_repeat('e', 32)]);
        $forgedDatabase = null;
        $forgedMetadata = $metadata;
        $forgedHash = hash_file('sha256', $forgedArchive);
        backup_contract_assert(is_string($forgedHash), 'Could not hash forged SQLite contract backup.');
        $forgedMetadata['archive_sha256'] = $forgedHash;
        backup_contract_write_metadata_set($forgedArchive, $forgedMetadata);
        try {
            $restore->restore($forgedArchive, $backupContractDirectory);
            throw new RuntimeException('Relabeled SQLite archive with matching-looking metadata was accepted.');
        } catch (UserVisibleException $e) {
            backup_contract_assert(
                $e->publicCode() === 'DATABASE_BACKUP_IDENTITY_MISMATCH',
                'Relabeled SQLite archive used the wrong fixed failure.',
            );
        }
        backup_contract_assert(backup_contract_snapshot($pdo) === $baseline, 'Relabeled SQLite archive rejection mutated the target.');
        backup_contract_assert((glob($backupContractDirectory . '/restore-safety-*') ?: []) === $safetyBefore, 'Relabeled SQLite archive created a safety backup.');
    }

    file_put_contents($metadataPath, $originalMetadata);
    file_put_contents($checksumPath, $originalChecksum);
    $checksumLines = file($checksumPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $checksumLines[0] = str_repeat('0', 64) . '  ' . basename($archive);
    file_put_contents($checksumPath, implode("\n", $checksumLines) . "\n");
    try {
        $restore->restore($archive, $backupContractDirectory);
        throw new RuntimeException('Wrong archive checksum was accepted.');
    } catch (UserVisibleException $e) {
        backup_contract_assert($e->publicCode() === 'DATABASE_BACKUP_CHECKSUM_MISMATCH', 'Checksum tamper used the wrong fixed failure.');
    }
    backup_contract_assert(backup_contract_snapshot($pdo) === $baseline, 'Checksum rejection mutated the target database.');

    file_put_contents($metadataPath, $originalMetadata);
    file_put_contents($checksumPath, $originalChecksum);
    $pdo->exec('DELETE FROM candidates');
    backup_contract_assert((int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn() === 0, 'Restore fixture mutation failed.');
    $result = $restore->restore($archive, $backupContractDirectory);
    backup_contract_assert($result['driver'] === $driver, 'Restore returned the wrong driver.');
    $pdo = Database::connection();
    backup_contract_assert((int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn() === $candidateCount, 'Same-identity restore did not restore product rows.');
    backup_contract_assert(
        (string) $pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn() === $identity,
        'Same-identity restore changed the installed identity.',
    );
    $safetyArchives = glob(
        $backupContractDirectory . '/restore-safety-*.' . ($driver === 'pgsql' ? 'pgdump' : 'sqlite'),
    ) ?: [];
    backup_contract_assert(count($safetyArchives) === 1, 'Successful restore must create exactly one safety archive.');
    backup_contract_assert(is_file($safetyArchives[0] . '.sha256'), 'Restore safety archive lacks a checksum sidecar.');
    backup_contract_assert(is_file($safetyArchives[0] . BackupMetadata::SUFFIX), 'Restore safety archive lacks identity metadata.');

    echo 'PASS checksum-bound identity-safe backup/restore contract (' . Database::driver() . ' ' . Database::serverVersion() . ")\n";
} finally {
    Database::reset();
    putenv('CPE_BACKUP_DIR');
    if ($backupContractSqlite !== null) {
        putenv('CPE_DB_PATH');
        putenv('CPE_DB_DRIVER');
    }
    backup_contract_remove_tree($backupContractRoot);
}
