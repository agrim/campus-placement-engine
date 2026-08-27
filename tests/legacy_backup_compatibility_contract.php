<?php

declare(strict_types=1);

$legacyRoot = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
    . '/cpe-legacy-backup-' . bin2hex(random_bytes(6));
if (!mkdir($legacyRoot, 0700)) {
    throw new RuntimeException('Could not create legacy backup contract directory.');
}
$legacyDatabase = $legacyRoot . '/upgrade-target.sqlite';
$legacySource = $legacyRoot . '/app-20260827-120000-abcdef.sqlite';
$legacyUpgradeBackups = $legacyRoot . '/upgrade-backups';
$legacyConverted = $legacyRoot . '/converted';
$legacyImports = $legacyRoot . '/imports';
foreach ([$legacyUpgradeBackups, $legacyConverted, $legacyImports] as $directory) {
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Could not create legacy backup contract storage.');
    }
}
putenv('CPE_DB_DRIVER=sqlite');
putenv('CPE_DB_PATH=' . $legacyDatabase);
putenv('CPE_BACKUP_DIR=' . $legacyUpgradeBackups);
putenv('CPE_IMPORT_ROLLBACK_DIR=' . $legacyImports);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Backup\BackupMetadata;
use App\Core\Backup\DatabaseRestoreService;
use App\Core\Backup\LegacySqliteBackupConverter;
use App\Core\Http\UserVisibleException;
use App\Core\Persistence\DatabaseOwnership;
use App\Import\ImportRollbackService;
use App\Install\Installer;
use App\Support\Database;

function legacy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{int, string, string} */
function legacy_cli(array $arguments, array $environment = []): array
{
    $processEnvironment = getenv();
    if (!is_array($processEnvironment)) {
        $processEnvironment = [];
    }
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../placement', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        array_merge($processEnvironment, $environment),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start legacy compatibility CLI process.');
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function legacy_checksum(string $archive): void
{
    $hash = hash_file('sha256', $archive);
    legacy_assert(is_string($hash), 'Could not hash legacy backup fixture.');
    file_put_contents($archive . '.sha256', $hash . '  ' . basename($archive) . "\n");
}

/** @return array<string, int|string> */
function legacy_product_snapshot(PDO $pdo): array
{
    $snapshot = [
        'identity' => (string) $pdo->query(
            "SELECT public_id FROM institutions WHERE slug = 'default'",
        )->fetchColumn(),
    ];
    $queries = [
        'institution_rows' => 'SELECT id, public_id, slug, name, timezone, created_at FROM institutions ORDER BY id',
        'user_rows' => 'SELECT id, name, email, password_hash, role, active, created_at, scope_type, scope_value FROM users ORDER BY id',
        'candidate_rows' => 'SELECT id, external_id, name, program, current_location, placed_company_id, created_at, opted_out, accommodation_notes, anonymized_at, tags, custom_fields_json, public_id FROM candidates ORDER BY id',
        'company_rows' => 'SELECT id, code, name, slot, created_at, offer_tier, process_type, room, tracker_name, max_active, process_notes, deadline_day, deadline_at, tags, custom_fields_json, public_id FROM companies ORDER BY id',
        'application_rows' => 'SELECT id, candidate_id, company_id, current_status, previous_company_id, next_company_id, created_at, waitlist_rank, public_id, participant_id, opportunity_id, workflow_version_id FROM applications ORDER BY id',
    ];
    foreach ($queries as $key => $sql) {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $snapshot[$key . '_count'] = count($rows);
        $snapshot[$key . '_sha256'] = hash(
            'sha256',
            json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
    return $snapshot;
}

/** @return UserVisibleException */
function legacy_expect_user_failure(callable $operation, string $code, string $message): UserVisibleException
{
    try {
        $operation();
    } catch (UserVisibleException $e) {
        legacy_assert($e->publicCode() === $code, $message . ' used the wrong fixed code.');
        return $e;
    }
    throw new RuntimeException($message);
}

/** @param array<int, array<string, mixed>> $rows
 *  @return array<string, mixed>
 */
function legacy_row(array $rows, string $id): array
{
    foreach ($rows as $row) {
        if (($row['id'] ?? null) === $id) {
            return $row;
        }
    }
    throw new RuntimeException('Legacy rollback row was not found: ' . $id);
}

function legacy_remove_tree(string $directory): void
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

try {
    (new Installer())->install([
        'college_name' => 'Synthetic Legacy Upgrade College',
        'timezone' => 'UTC',
        'admin_name' => 'Synthetic Legacy Administrator',
        'admin_email' => 'legacy-upgrade@example.test',
        'admin_password' => 'legacy-upgrade-password-123',
        'seed_demo' => '1',
    ]);
    $pdo = Database::connection();
    $baseline = legacy_product_snapshot($pdo);
    legacy_assert(
        $baseline['candidate_rows_count'] > 0 && $baseline['application_rows_count'] > 0,
        'Legacy fixture lacks product data.',
    );

    $pdo->exec('DROP TABLE cpe_database_ownership');
    legacy_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cpe_database_ownership'",
        )->fetchColumn() === 0,
        'Synthetic legacy fixture retained ownership evidence.',
    );
    $pdo->exec('VACUUM INTO ' . $pdo->quote($legacySource));
    legacy_checksum($legacySource);
    $sourceHash = hash_file('sha256', $legacySource);
    $sourceChecksumHash = hash_file('sha256', $legacySource . '.sha256');
    Database::reset();

    [$upgradeCode, $upgradeOut, $upgradeErr] = legacy_cli(['upgrade'], [
        'CPE_DB_DRIVER' => 'sqlite',
        'CPE_DB_PATH' => $legacyDatabase,
        'CPE_BACKUP_DIR' => $legacyUpgradeBackups,
    ]);
    legacy_assert($upgradeCode === 0, 'Legacy ownership-adoption upgrade failed: ' . $upgradeErr);
    legacy_assert(str_contains($upgradeOut, 'Upgrade complete.'), 'Legacy upgrade did not report completion.');
    $upgraded = new PDO('sqlite:' . $legacyDatabase, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    legacy_assert(legacy_product_snapshot($upgraded) === $baseline, 'Legacy upgrade changed identity or product rows.');
    legacy_assert(
        $upgraded->query('SELECT owner_kind FROM cpe_database_ownership')->fetchColumn()
            === DatabaseOwnership::OWNER_ENGINE_INSTITUTION,
        'Legacy upgrade did not permanently adopt Engine ownership.',
    );
    $upgradeArchives = glob($legacyUpgradeBackups . '/upgrade-*.sqlite') ?: [];
    legacy_assert(count($upgradeArchives) === 1, 'Legacy upgrade did not create exactly one pre-migration backup.');
    $upgradeArchive = $upgradeArchives[0];
    legacy_assert(is_file($upgradeArchive . '.sha256'), 'Legacy upgrade backup lacks checksum evidence.');
    legacy_assert(is_file($upgradeArchive . BackupMetadata::SUFFIX), 'Legacy upgrade backup lacks metadata evidence.');
    legacy_assert(
        count(file($upgradeArchive . '.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) === 2,
        'Legacy upgrade backup does not bind archive and metadata.',
    );
    $upgradeMetadata = BackupMetadata::read($upgradeArchive . BackupMetadata::SUFFIX);
    legacy_assert($upgradeMetadata['institution_public_id'] === $baseline['identity'], 'Upgrade backup metadata changed identity.');
    $upgradeBackup = new PDO('sqlite:' . $upgradeArchive);
    legacy_assert(
        legacy_product_snapshot($upgradeBackup) === $baseline,
        'Upgrade backup changed institution or product rows.',
    );
    legacy_assert(
        $upgradeBackup->query('SELECT owner_kind FROM cpe_database_ownership')->fetchColumn()
            === DatabaseOwnership::OWNER_ENGINE_INSTITUTION,
        'Upgrade backup was created before ownership adoption.',
    );
    $upgradeBackup = null;

    [$convertCode, $convertOut, $convertErr] = legacy_cli([
        'convert-legacy-backup',
        $legacySource,
        '--confirm=CONVERT',
        '--target-dir=' . $legacyConverted,
    ]);
    legacy_assert($convertCode === 0, 'Legacy SQLite conversion failed: ' . $convertErr);
    legacy_assert(!str_contains($convertOut, $legacyRoot), 'Legacy conversion output exposed an absolute path.');
    legacy_assert(hash_file('sha256', $legacySource) === $sourceHash, 'Legacy conversion modified the original archive.');
    legacy_assert(
        hash_file('sha256', $legacySource . '.sha256') === $sourceChecksumHash,
        'Legacy conversion modified the original checksum.',
    );
    $convertedArchives = glob($legacyConverted . '/*-converted-*.sqlite') ?: [];
    legacy_assert(count($convertedArchives) === 1, 'Legacy conversion did not create exactly one new archive.');
    $convertedArchive = $convertedArchives[0];
    legacy_assert($convertedArchive !== $legacySource, 'Legacy conversion overwrote the original archive.');
    legacy_assert(is_file($convertedArchive . BackupMetadata::SUFFIX), 'Converted archive lacks metadata.');
    legacy_assert(
        count(file($convertedArchive . '.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) === 2,
        'Converted archive lacks the current two-entry checksum.',
    );
    $convertedPdo = new PDO('sqlite:' . $convertedArchive);
    legacy_assert(legacy_product_snapshot($convertedPdo) === $baseline, 'Converted archive changed identity or product rows.');
    legacy_assert(
        $convertedPdo->query('SELECT owner_kind FROM cpe_database_ownership')->fetchColumn()
            === DatabaseOwnership::OWNER_ENGINE_INSTITUTION,
        'Converted archive lacks permanent Engine ownership.',
    );
    $convertedPdo = null;
    $firstConvertedHashes = [
        hash_file('sha256', $convertedArchive),
        hash_file('sha256', $convertedArchive . '.sha256'),
        hash_file('sha256', $convertedArchive . BackupMetadata::SUFFIX),
    ];
    $secondArtifact = (new LegacySqliteBackupConverter())->convert($legacySource, $legacyConverted);
    legacy_assert($secondArtifact->internalPath() !== $convertedArchive, 'A second conversion reused an existing archive name.');
    legacy_assert(
        $firstConvertedHashes === [
            hash_file('sha256', $convertedArchive),
            hash_file('sha256', $convertedArchive . '.sha256'),
            hash_file('sha256', $convertedArchive . BackupMetadata::SUFFIX),
        ],
        'A second conversion overwrote an existing converted backup set.',
    );

    $oversizedChecksum = $legacyRoot . '/oversized-checksum.sqlite';
    copy($legacySource, $oversizedChecksum);
    file_put_contents($oversizedChecksum . '.sha256', str_repeat('x', 513));
    legacy_expect_user_failure(
        static fn () => (new LegacySqliteBackupConverter())->convert($oversizedChecksum, $legacyConverted),
        'LEGACY_SQLITE_BACKUP_CHECKSUM_INVALID',
        'Oversized legacy checksum sidecar was accepted.',
    );

    $tampered = $legacyRoot . '/tampered.sqlite';
    copy($legacySource, $tampered);
    $untamperedHash = hash_file('sha256', $tampered);
    legacy_assert(is_string($untamperedHash), 'Could not hash tamper fixture.');
    file_put_contents($tampered . '.sha256', $untamperedHash . '  ' . basename($tampered) . "\n");
    file_put_contents($tampered, "tamper\n", FILE_APPEND);
    legacy_expect_user_failure(
        static fn () => (new LegacySqliteBackupConverter())->convert($tampered, $legacyConverted),
        'LEGACY_SQLITE_BACKUP_CHECKSUM_MISMATCH',
        'Legacy archive tampering was accepted.',
    );

    $wrongIdentity = $legacyRoot . '/wrong-identity.sqlite';
    copy($legacySource, $wrongIdentity);
    $wrongIdentityPdo = new PDO('sqlite:' . $wrongIdentity);
    $wrongIdentityPdo->exec("UPDATE institutions SET public_id = 'invalid_identity' WHERE slug = 'default'");
    $wrongIdentityPdo = null;
    legacy_checksum($wrongIdentity);
    legacy_expect_user_failure(
        static fn () => (new LegacySqliteBackupConverter())->convert($wrongIdentity, $legacyConverted),
        'LEGACY_SQLITE_BACKUP_SIGNATURE_INVALID',
        'Invalid legacy institution identity was accepted.',
    );

    $wrongSchema = $legacyRoot . '/wrong-schema.sqlite';
    $wrongSchemaPdo = new PDO('sqlite:' . $wrongSchema);
    $wrongSchemaPdo->exec('CREATE TABLE settings (key TEXT, value TEXT)');
    $wrongSchemaPdo->exec('CREATE TABLE institutions (public_id TEXT, slug TEXT)');
    $wrongSchemaPdo->exec("INSERT INTO settings VALUES ('installed_at', '2026-01-01 00:00:00')");
    $wrongSchemaPdo->exec("INSERT INTO institutions VALUES ('inst_11111111111111111111111111111111', 'default')");
    $wrongSchemaPdo = null;
    legacy_checksum($wrongSchema);
    legacy_expect_user_failure(
        static fn () => (new LegacySqliteBackupConverter())->convert($wrongSchema, $legacyConverted),
        'LEGACY_SQLITE_BACKUP_SIGNATURE_INVALID',
        'Partial legacy Engine schema was accepted.',
    );

    $fakePostgres = $legacyRoot . '/legacy.pgdump';
    file_put_contents($fakePostgres, 'synthetic');
    legacy_expect_user_failure(
        static fn () => (new LegacySqliteBackupConverter())->convert($fakePostgres, $legacyConverted),
        'LEGACY_POSTGRES_BACKUP_CONVERSION_UNSUPPORTED',
        'Legacy PostgreSQL conversion was not refused explicitly.',
    );

    if (function_exists('symlink')) {
        $symlink = $legacyRoot . '/legacy-link.sqlite';
        if (@symlink($legacySource, $symlink)) {
            legacy_expect_user_failure(
                static fn () => (new LegacySqliteBackupConverter())->convert($symlink, $legacyConverted),
                'LEGACY_SQLITE_BACKUP_INVALID',
                'Symlinked legacy archive was accepted.',
            );
        }
    }

    $rollbackId = 'import-20260827-143000-abcdef';
    $rollbackFile = $rollbackId . '-20260827-143001-123abc.sqlite';
    copy($legacySource, $legacyImports . '/' . $rollbackFile);
    legacy_checksum($legacyImports . '/' . $rollbackFile);
    $rollbackArchiveHash = hash_file('sha256', $legacyImports . '/' . $rollbackFile);
    $rollbackChecksumHash = hash_file('sha256', $legacyImports . '/' . $rollbackFile . '.sha256');
    file_put_contents(
        $legacyImports . '/' . $rollbackId . '.json',
        json_encode([
            'id' => $rollbackId,
            'type' => 'candidates',
            'actor_user_id' => 1,
            'created_at' => '2026-08-27 14:30:00',
            'rows' => 1,
            'creates' => 1,
            'updates' => 0,
            'warnings' => 0,
            'backup_path' => '/private/sentinel/' . $rollbackFile,
            'backup_driver' => 'sqlite',
            'backup_sha256' => $rollbackArchiveHash,
            'database_path' => '/private/sentinel/live.sqlite',
            'restored_at' => '',
            'restore_safety_path' => '/private/sentinel/safety.sqlite',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
    Database::reset();
    $rollbackService = new ImportRollbackService();
    $recent = $rollbackService->recent(5);
    legacy_assert(count($recent) === 1, 'Legacy import rollback manifest was not discovered.');
    legacy_assert(($recent[0]['legacy_conversion_required'] ?? false) === true, 'Legacy rollback was not marked for conversion.');
    foreach (['backup_path', 'database_path', 'restore_safety_path'] as $unsafeField) {
        legacy_assert(!array_key_exists($unsafeField, $recent[0]), 'Legacy absolute path was re-exposed: ' . $unsafeField);
    }
    legacy_assert($recent[0]['legacy_backup_file'] === $rollbackFile, 'Legacy rollback did not retain a safe basename.');
    legacy_expect_user_failure(
        static fn () => $rollbackService->restore($rollbackId),
        'IMPORT_ROLLBACK_LEGACY_CONVERSION_REQUIRED',
        'Legacy rollback restored without explicit conversion.',
    );

    [$rollbackConvertCode, $rollbackConvertOut, $rollbackConvertErr] = legacy_cli([
        'rollback-import',
        $rollbackId,
        '--convert-legacy',
        '--confirm=CONVERT',
    ], [
        'CPE_DB_DRIVER' => 'sqlite',
        'CPE_DB_PATH' => $legacyDatabase,
        'CPE_IMPORT_ROLLBACK_DIR' => $legacyImports,
    ]);
    legacy_assert($rollbackConvertCode === 0, 'Legacy import rollback conversion failed: ' . $rollbackConvertErr);
    legacy_assert(!str_contains($rollbackConvertOut, '/private/sentinel'), 'Legacy manifest path leaked through CLI conversion.');
    legacy_assert(
        hash_file('sha256', $legacyImports . '/' . $rollbackFile) === $rollbackArchiveHash
            && hash_file('sha256', $legacyImports . '/' . $rollbackFile . '.sha256') === $rollbackChecksumHash,
        'Legacy import rollback conversion modified the original artifact.',
    );
    $convertedManifest = json_decode(
        (string) file_get_contents($legacyImports . '/' . $rollbackId . '.json'),
        true,
        32,
        JSON_THROW_ON_ERROR,
    );
    legacy_assert(is_array($convertedManifest), 'Converted rollback manifest is invalid.');
    foreach (['backup_path', 'database_path', 'restore_safety_path'] as $unsafeField) {
        legacy_assert(!array_key_exists($unsafeField, $convertedManifest), 'Converted manifest retained ' . $unsafeField);
    }
    legacy_assert($convertedManifest['legacy_backup_file'] === $rollbackFile, 'Converted manifest lost safe legacy evidence.');
    legacy_assert(
        preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} /', (string) $convertedManifest['legacy_converted_at']) === 1,
        'Converted manifest lacks an audit timestamp.',
    );
    $convertedRollbackPath = $legacyImports . '/' . (string) $convertedManifest['backup_file'];
    legacy_assert(is_file($convertedRollbackPath . BackupMetadata::SUFFIX), 'Converted rollback metadata is missing.');

    $restoredManifest = $rollbackService->restore($rollbackId);
    legacy_assert((string) $restoredManifest['restored_at'] !== '', 'Converted legacy rollback did not restore.');
    legacy_assert(
        preg_match('/\Abackup_[a-f0-9]{24}\z/D', (string) $restoredManifest['restore_safety_reference']) === 1,
        'Converted rollback did not retain an opaque safety reference.',
    );

    $legacyPostgresId = 'import-20260827-144000-fedcba';
    $legacyPostgresFile = $legacyPostgresId . '-20260827-144001-654321.pgdump';
    $legacyPostgresPath = $legacyImports . '/' . $legacyPostgresFile;
    file_put_contents($legacyPostgresPath, 'synthetic legacy PostgreSQL archive');
    legacy_checksum($legacyPostgresPath);
    $legacyPostgresHashes = [
        hash_file('sha256', $legacyPostgresPath),
        hash_file('sha256', $legacyPostgresPath . '.sha256'),
    ];
    file_put_contents(
        $legacyImports . '/' . $legacyPostgresId . '.json',
        json_encode([
            'id' => $legacyPostgresId,
            'type' => 'companies',
            'actor_user_id' => 1,
            'created_at' => '2026-08-27 14:40:00',
            'rows' => 1,
            'creates' => 0,
            'updates' => 1,
            'warnings' => 0,
            'backup_path' => '/private/sentinel/postgres/' . $legacyPostgresFile,
            'backup_driver' => 'pgsql',
            'restored_at' => '',
            'restore_safety_path' => '/private/sentinel/postgres-safety.pgdump',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
    $postgresRow = legacy_row($rollbackService->recent(20), $legacyPostgresId);
    legacy_assert($postgresRow['legacy_backup_file'] === $legacyPostgresFile, 'Legacy PostgreSQL basename was not recognized safely.');
    legacy_assert($postgresRow['legacy_backup_kind'] === 'pgsql', 'Legacy PostgreSQL kind was not normalized.');
    legacy_assert($postgresRow['legacy_archive_exists'] === true, 'Legacy PostgreSQL in-directory archive was not found.');
    legacy_assert($postgresRow['legacy_conversion_required'] === false, 'Legacy PostgreSQL archive was offered SQLite conversion.');
    legacy_assert($postgresRow['legacy_recovery_required'] === true, 'Legacy PostgreSQL recovery requirement was not surfaced.');
    legacy_assert(
        $postgresRow['legacy_recovery_requirement'] === ImportRollbackService::LEGACY_POSTGRES_REQUIREMENT,
        'Legacy PostgreSQL recovery requirement used the wrong fixed value.',
    );
    foreach (['backup_path', 'database_path', 'restore_safety_path'] as $unsafeField) {
        legacy_assert(!array_key_exists($unsafeField, $postgresRow), 'Legacy PostgreSQL row exposed ' . $unsafeField);
    }
    legacy_assert(
        !str_contains(json_encode($postgresRow, JSON_THROW_ON_ERROR), '/private/sentinel'),
        'Legacy PostgreSQL structured result exposed an absolute path.',
    );
    $postgresRestoreFailure = legacy_expect_user_failure(
        static fn () => $rollbackService->restore($legacyPostgresId),
        ImportRollbackService::LEGACY_POSTGRES_ERROR,
        'Legacy PostgreSQL rollback restored without isolated validation.',
    );
    legacy_assert(
        str_contains($postgresRestoreFailure->publicMessage(), 'manual isolated restore validation'),
        'Legacy PostgreSQL restore refusal did not provide fixed recovery guidance.',
    );
    legacy_expect_user_failure(
        static fn () => $rollbackService->convertLegacy($legacyPostgresId),
        ImportRollbackService::LEGACY_POSTGRES_ERROR,
        'Legacy PostgreSQL rollback was offered direct conversion.',
    );
    [$postgresListCode, $postgresListOut, $postgresListErr] = legacy_cli([
        'rollback-import',
        '--list',
    ], [
        'CPE_DB_DRIVER' => 'sqlite',
        'CPE_DB_PATH' => $legacyDatabase,
        'CPE_IMPORT_ROLLBACK_DIR' => $legacyImports,
    ]);
    legacy_assert($postgresListCode === 0, 'Legacy PostgreSQL rollback listing failed: ' . $postgresListErr);
    legacy_assert(
        str_contains($postgresListOut, $legacyPostgresId)
            && str_contains($postgresListOut, 'legacy_postgres_isolated_validation_required'),
        'Legacy PostgreSQL rollback listing omitted its explicit recovery status.',
    );
    legacy_assert(!str_contains($postgresListOut . $postgresListErr, '/private/sentinel'), 'Legacy PostgreSQL CLI listing exposed an absolute path.');
    [$postgresRestoreCode, $postgresRestoreOut, $postgresRestoreErr] = legacy_cli([
        'rollback-import',
        $legacyPostgresId,
    ], [
        'CPE_DB_DRIVER' => 'sqlite',
        'CPE_DB_PATH' => $legacyDatabase,
        'CPE_IMPORT_ROLLBACK_DIR' => $legacyImports,
    ]);
    legacy_assert($postgresRestoreCode === 1 && $postgresRestoreOut === '', 'Legacy PostgreSQL CLI restore was not refused cleanly.');
    legacy_assert(
        str_contains($postgresRestoreErr, 'manual isolated restore validation')
            && !str_contains($postgresRestoreErr, '/private/sentinel'),
        'Legacy PostgreSQL CLI restore refusal was not fixed and path-safe.',
    );
    [$postgresConvertCode, $postgresConvertOut, $postgresConvertErr] = legacy_cli([
        'rollback-import',
        $legacyPostgresId,
        '--convert-legacy',
        '--confirm=CONVERT',
    ], [
        'CPE_DB_DRIVER' => 'sqlite',
        'CPE_DB_PATH' => $legacyDatabase,
        'CPE_IMPORT_ROLLBACK_DIR' => $legacyImports,
    ]);
    legacy_assert($postgresConvertCode === 1 && $postgresConvertOut === '', 'Legacy PostgreSQL CLI conversion was not refused cleanly.');
    legacy_assert(
        str_contains($postgresConvertErr, 'manual isolated restore validation')
            && !str_contains($postgresConvertErr, '/private/sentinel'),
        'Legacy PostgreSQL CLI refusal was not fixed and path-safe.',
    );
    legacy_assert(
        $legacyPostgresHashes === [
            hash_file('sha256', $legacyPostgresPath),
            hash_file('sha256', $legacyPostgresPath . '.sha256'),
        ],
        'Legacy PostgreSQL refusal modified the original archive or checksum.',
    );

    $traversalId = 'import-20260827-144100-def456';
    $traversalFile = $traversalId . '-20260827-144101-abcdef.pgdump';
    file_put_contents($legacyImports . '/' . $traversalFile, 'must not be selected through traversal');
    file_put_contents(
        $legacyImports . '/' . $traversalId . '.json',
        json_encode([
            'id' => $traversalId,
            'type' => 'candidates',
            'created_at' => '2026-08-27 14:41:00',
            'rows' => 1,
            'backup_path' => '/private/sentinel/../../outside/' . $traversalFile,
            'backup_driver' => 'pgsql',
            'restored_at' => '',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
    $traversalRow = legacy_row($rollbackService->recent(20), $traversalId);
    legacy_assert($traversalRow['legacy_backup_file'] === '', 'Traversal legacy path was reinterpreted as an in-directory archive.');
    legacy_assert($traversalRow['legacy_recovery_required'] === false, 'Traversal legacy path produced a recovery-ready status.');
    legacy_assert(
        !str_contains(json_encode($traversalRow, JSON_THROW_ON_ERROR), '/private/sentinel')
            && !str_contains(json_encode($traversalRow, JSON_THROW_ON_ERROR), '../'),
        'Traversal legacy path was re-exposed.',
    );
    legacy_expect_user_failure(
        static fn () => $rollbackService->convertLegacy($traversalId),
        'IMPORT_ROLLBACK_LEGACY_INVALID',
        'Traversal legacy path reached PostgreSQL recovery handling.',
    );

    $cleanupLogDirectory = $legacyRoot . '/logs';
    mkdir($cleanupLogDirectory, 0700);
    $cleanupLog = $cleanupLogDirectory . '/structured.jsonl';
    putenv('CPE_LOG_PATH=' . $cleanupLog);
    $cleanupDirectory = $legacyRoot . '/cleanup-failure';
    mkdir($cleanupDirectory . '/nested', 0700, true);
    $cleanupMethod = new ReflectionMethod(DatabaseRestoreService::class, 'removeStagingDirectory');
    $cleanupMethod->invoke(new DatabaseRestoreService(), $cleanupDirectory);
    $cleanupRecords = array_values(array_filter(array_map(
        static fn (string $line): mixed => json_decode($line, true),
        file($cleanupLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    ), 'is_array'));
    $cleanupRecord = end($cleanupRecords);
    legacy_assert(is_array($cleanupRecord), 'Staging cleanup failure did not reach the protected incident log.');
    legacy_assert(
        ($cleanupRecord['context']['diagnostic_code'] ?? null) === 'CPE_RESTORE_STAGING_CLEANUP_FAILED',
        'Staging cleanup used the wrong fixed diagnostic code.',
    );
    legacy_assert(
        ($cleanupRecord['context']['safe_context']['operation'] ?? null) === 'database_restore.cleanup',
        'Staging cleanup incident lost its fixed operation.',
    );
    legacy_assert(!str_contains((string) file_get_contents($cleanupLog), $cleanupDirectory), 'Staging cleanup incident exposed a path.');
    rmdir($cleanupDirectory . '/nested');
    rmdir($cleanupDirectory);

    echo "PASS legacy upgrade, backup conversion, rollback compatibility, and staging hygiene contract\n";
} finally {
    Database::reset();
    foreach (['CPE_DB_DRIVER', 'CPE_DB_PATH', 'CPE_BACKUP_DIR', 'CPE_IMPORT_ROLLBACK_DIR', 'CPE_LOG_PATH'] as $key) {
        putenv($key);
    }
    legacy_remove_tree($legacyRoot);
}
