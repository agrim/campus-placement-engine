<?php

declare(strict_types=1);

namespace App\Core\Backup;

use App\Core\Http\UserVisibleException;
use App\Support\Database;
use App\Support\IncidentReporter;
use PDO;
use RuntimeException;

final class DatabaseRestoreService
{
    private ?int $remainingVerificationBytes;

    public function __construct(
        private readonly ?string $postgresUrl = null,
        ?int $verificationBudgetBytes = null,
    ) {
        if ($verificationBudgetBytes !== null && $verificationBudgetBytes < 0) {
            throw new \InvalidArgumentException('Backup verification budget cannot be negative.');
        }
        $this->remainingVerificationBytes = $verificationBudgetBytes;
    }

    public function restore(string $backupPath, ?string $safetyDirectory = null): array
    {
        $backupPath = trim($backupPath);
        if ($backupPath === '' || is_link($backupPath) || !is_file($backupPath)) {
            throw new UserVisibleException('DATABASE_BACKUP_PATH_INVALID', 'Provide a readable database backup path.');
        }
        [$stagingDirectory, $stagedBackupPath] = $this->stageBackupSet($backupPath);
        try {
            $driver = Database::driver();
            $targetConnection = Database::connection();
            if ($targetConnection->inTransaction()) {
                throw new RuntimeException('Database restore cannot run inside an active transaction.');
            }
            try {
                $targetIdentity = BackupMetadata::databaseIdentity($targetConnection, $driver);
            } catch (\Throwable $e) {
                throw new UserVisibleException(
                    'DATABASE_RESTORE_TARGET_INVALID',
                    'The installed restore target has invalid identity or ownership evidence.',
                    $e,
                );
            }
            $this->verifiedCandidate($stagedBackupPath, $driver, $targetIdentity);

            $safety = (new DatabaseBackupService($targetConnection, $this->postgresUrl))
                ->create('restore-safety', $safetyDirectory);
            if ($driver === 'sqlite') {
                $this->restoreSqlite($stagedBackupPath);
            } elseif ($driver === 'pgsql') {
                $this->restorePostgres($stagedBackupPath);
            } else {
                throw new RuntimeException('Database restore is not configured for driver: ' . $driver);
            }
            Database::reset();
            try {
                $restoredIdentity = BackupMetadata::databaseIdentity(Database::connection(), $driver);
            } catch (\Throwable) {
                $restoredIdentity = null;
            }
            if (!Database::isInstalled() || $restoredIdentity !== $targetIdentity) {
                throw new UserVisibleException(
                    'DATABASE_RESTORE_VERIFICATION_FAILED',
                    'Restore verification failed. Use the safety backup created before restore.',
                );
            }
            return [
                'backup_reference' => 'backup_' . substr(hash_file('sha256', $stagedBackupPath) ?: hash('sha256', basename($stagedBackupPath)), 0, 24),
                'driver' => $driver,
                'safety_reference' => $safety->reference(),
            ];
        } finally {
            $this->removeStagingDirectory($stagingDirectory);
        }
    }

    public function verifyChecksum(string $path): void
    {
        $this->verifyBackupSet($path);
    }

    /** @return array<string, int|string> */
    public function verifiedMetadata(string $path, ?int $maxArchiveBytes = null): array
    {
        return $this->verifyBackupSet($path, $maxArchiveBytes)['metadata'];
    }

    /**
     * @param array{owner_kind: string, owner_contract_version: int, institution_public_id: string} $targetIdentity
     * @return array{metadata: array<string, int|string>, archive_bytes: int}
     */
    public function verifiedCandidate(string $path, string $driver, array $targetIdentity): array
    {
        $this->assertFormat($path, $driver);
        $verified = $this->verifyBackupSet($path);
        $metadata = $verified['metadata'];
        $this->assertMetadataMatchesTarget($metadata, $driver, $targetIdentity);
        if ($driver === 'sqlite') {
            $this->inspectSqliteBackup($path, $metadata);
        } elseif ($driver === 'pgsql') {
            $this->inspectPostgresBackup($path);
        } else {
            throw new UserVisibleException('DATABASE_BACKUP_FORMAT_INVALID', 'The database backup format is invalid.');
        }
        $this->assertStableRegularFile($path, $verified['archive_stat']);
        return [
            'metadata' => $metadata,
            'archive_bytes' => (int) $verified['archive_stat']['size'],
        ];
    }

    /**
     * Reads only bounded sidecars so readiness can prioritize retained sets before archive hashing.
     *
     * @param array{owner_kind: string, owner_contract_version: int, institution_public_id: string} $targetIdentity
     */
    public function candidateCreatedAt(string $path, string $driver, array $targetIdentity): int
    {
        $this->assertFormat($path, $driver);
        $indexed = $this->readBackupSetIndex($path);
        $this->assertMetadataMatchesTarget($indexed['metadata'], $driver, $targetIdentity);
        return BackupMetadata::createdAtTimestamp($indexed['metadata']);
    }

    /** @return array{metadata: array<string, int|string>, archive_stat: array<string|int, mixed>} */
    private function verifyBackupSet(string $path, ?int $maxArchiveBytes = null): array
    {
        $indexed = $this->readBackupSetIndex($path);
        $archiveStat = @lstat($path);
        if ($archiveStat === false
            || (($archiveStat['mode'] ?? 0) & 0170000) !== 0100000
            || (int) ($archiveStat['size'] ?? 0) <= 0
            || ($maxArchiveBytes !== null && (int) ($archiveStat['size'] ?? 0) > $maxArchiveBytes)) {
            throw new UserVisibleException('DATABASE_BACKUP_ARCHIVE_INVALID', 'The backup archive is invalid.');
        }
        $archiveBytes = (int) $archiveStat['size'];
        if ($this->remainingVerificationBytes !== null) {
            if ($archiveBytes > $this->remainingVerificationBytes) {
                throw new UserVisibleException(
                    'DATABASE_BACKUP_VERIFICATION_LIMIT',
                    'Backup verification exceeded the bounded inspection limit.',
                );
            }
            $this->remainingVerificationBytes -= $archiveBytes;
        }
        $archiveHash = $this->hashStableArchive($path, $archiveStat);
        if (!hash_equals($indexed['expected_archive_hash'], strtolower($archiveHash))) {
            throw new UserVisibleException('DATABASE_BACKUP_CHECKSUM_MISMATCH', 'Backup checksum verification failed.');
        }
        if (!hash_equals((string) $indexed['metadata']['archive_sha256'], strtolower($archiveHash))) {
            throw new UserVisibleException('DATABASE_BACKUP_METADATA_MISMATCH', 'Backup metadata does not match the archive.');
        }
        foreach ([
            [$path . '.sha256', $indexed['checksum_stat']],
            [$path . BackupMetadata::SUFFIX, $indexed['metadata_stat']],
        ] as [$verifiedPath, $before]) {
            $this->assertStableRegularFile($verifiedPath, $before);
        }
        return ['metadata' => $indexed['metadata'], 'archive_stat' => $archiveStat];
    }

    /**
     * @return array{
     *   metadata: array<string, int|string>,
     *   expected_archive_hash: string,
     *   checksum_stat: array<string|int, mixed>,
     *   metadata_stat: array<string|int, mixed>
     * }
     */
    private function readBackupSetIndex(string $path): array
    {
        $checksumPath = $path . '.sha256';
        $metadataPath = $path . BackupMetadata::SUFFIX;
        $archiveStat = @lstat($path);
        if ($archiveStat === false
            || (($archiveStat['mode'] ?? 0) & 0170000) !== 0100000
            || (int) ($archiveStat['size'] ?? 0) <= 0) {
            throw new UserVisibleException('DATABASE_BACKUP_ARCHIVE_INVALID', 'The backup archive is invalid.');
        }
        $checksumStat = @lstat($checksumPath);
        if ($checksumStat === false || (($checksumStat['mode'] ?? 0) & 0170000) !== 0100000) {
            throw new UserVisibleException('DATABASE_BACKUP_CHECKSUM_REQUIRED', 'A backup checksum file is required.');
        }
        if ((int) ($checksumStat['size'] ?? 0) < 1 || (int) ($checksumStat['size'] ?? 0) > 4096) {
            throw new UserVisibleException('DATABASE_BACKUP_CHECKSUM_INVALID', 'The backup checksum file is invalid.');
        }
        $metadataStat = @lstat($metadataPath);
        if ($metadataStat === false
            || (($metadataStat['mode'] ?? 0) & 0170000) !== 0100000) {
            throw new UserVisibleException('DATABASE_BACKUP_METADATA_REQUIRED', 'A backup metadata file is required.');
        }
        if ((int) ($metadataStat['size'] ?? 0) < 2
            || (int) ($metadataStat['size'] ?? 0) > BackupMetadata::MAX_BYTES) {
            throw new UserVisibleException('DATABASE_BACKUP_METADATA_INVALID', 'The backup metadata file is invalid.');
        }
        $lines = @file($checksumPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) !== 2) {
            throw new UserVisibleException('DATABASE_BACKUP_CHECKSUM_INVALID', 'The backup checksum file is invalid.');
        }
        $expectedNames = [basename($path), basename($metadataPath)];
        $expectedHashes = [];
        foreach ($lines as $line) {
            if (preg_match('/\A([a-f0-9]{64}) {2}([A-Za-z0-9._-]+)\z/D', $line, $matches) !== 1
                || !in_array($matches[2], $expectedNames, true)
                || isset($expectedHashes[$matches[2]])) {
                throw new UserVisibleException('DATABASE_BACKUP_CHECKSUM_INVALID', 'The backup checksum file is invalid.');
            }
            $expectedHashes[$matches[2]] = $matches[1];
        }
        if (array_keys($expectedHashes) !== $expectedNames) {
            throw new UserVisibleException('DATABASE_BACKUP_CHECKSUM_INVALID', 'The backup checksum file is invalid.');
        }
        $metadataHash = @hash_file('sha256', $metadataPath);
        if ($metadataHash === false
            || !hash_equals($expectedHashes[basename($metadataPath)], strtolower($metadataHash))) {
            throw new UserVisibleException('DATABASE_BACKUP_CHECKSUM_MISMATCH', 'Backup checksum verification failed.');
        }
        $metadata = BackupMetadata::read($metadataPath);
        foreach ([[$path, $archiveStat], [$checksumPath, $checksumStat], [$metadataPath, $metadataStat]] as [$verifiedPath, $before]) {
            $this->assertStableRegularFile($verifiedPath, $before);
        }
        return [
            'metadata' => $metadata,
            'expected_archive_hash' => $expectedHashes[basename($path)],
            'checksum_stat' => $checksumStat,
            'metadata_stat' => $metadataStat,
        ];
    }

    /** @param array<string|int, mixed> $before */
    private function hashStableArchive(string $path, array $before): string
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new UserVisibleException('DATABASE_BACKUP_ARCHIVE_INVALID', 'The backup archive is invalid.');
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || !$this->sameRegularFile($before, $opened)) {
                throw new UserVisibleException('DATABASE_BACKUP_SET_CHANGED', 'The backup set changed during validation.');
            }
            $bytes = (int) $before['size'];
            $hash = hash_init('sha256');
            $hashedBytes = hash_update_stream($hash, $handle, $bytes);
            if ($hashedBytes !== $bytes) {
                throw new UserVisibleException('DATABASE_BACKUP_SET_CHANGED', 'The backup set changed during validation.');
            }
            $digest = hash_final($hash);
            $finished = fstat($handle);
            if (!is_array($finished) || !$this->sameRegularFile($before, $finished)) {
                throw new UserVisibleException('DATABASE_BACKUP_SET_CHANGED', 'The backup set changed during validation.');
            }
            return $digest;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string|int, mixed> $before */
    private function assertStableRegularFile(string $path, array $before): void
    {
        $after = @lstat($path);
        if ($after === false || !$this->sameRegularFile($before, $after)) {
            throw new UserVisibleException('DATABASE_BACKUP_SET_CHANGED', 'The backup set changed during validation.');
        }
    }

    /**
     * @param array<string|int, mixed> $left
     * @param array<string|int, mixed> $right
     */
    private function sameRegularFile(array $left, array $right): bool
    {
        return (($right['mode'] ?? 0) & 0170000) === 0100000
            && ($right['dev'] ?? null) === ($left['dev'] ?? null)
            && ($right['ino'] ?? null) === ($left['ino'] ?? null)
            && ($right['size'] ?? null) === ($left['size'] ?? null)
            && ($right['mtime'] ?? null) === ($left['mtime'] ?? null)
            && ($right['ctime'] ?? null) === ($left['ctime'] ?? null);
    }

    private function restoreSqlite(string $backupPath): void
    {
        $target = Database::path();
        Database::reset();
        $temporary = $target . '.restoring-' . bin2hex(random_bytes(4));
        if (!copy($backupPath, $temporary)) {
            throw new RuntimeException('Could not stage the SQLite restore.');
        }
        if (!rename($temporary, $target)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new RuntimeException('Could not atomically replace the SQLite database.');
        }
    }

    /** @return array{string, string} */
    private function stageBackupSet(string $backupPath): array
    {
        $sourcePaths = [
            $backupPath,
            $backupPath . '.sha256',
            $backupPath . BackupMetadata::SUFFIX,
        ];
        foreach ($sourcePaths as $index => $sourcePath) {
            if (is_link($sourcePath) || !is_file($sourcePath)) {
                if ($index === 2) {
                    throw new UserVisibleException(
                        'DATABASE_BACKUP_METADATA_REQUIRED',
                        'A backup metadata file is required.',
                    );
                }
                if ($index === 1) {
                    throw new UserVisibleException(
                        'DATABASE_BACKUP_CHECKSUM_REQUIRED',
                        'A backup checksum file is required.',
                    );
                }
                throw new UserVisibleException('DATABASE_BACKUP_PATH_INVALID', 'Provide a readable database backup path.');
            }
        }
        $stagingRoot = cpe_data_path('restore-staging');
        if (is_link($stagingRoot)
            || (!is_dir($stagingRoot) && !@mkdir($stagingRoot, 0700, true))
            || !@chmod($stagingRoot, 0700)) {
            throw new RuntimeException('Could not prepare private restore staging storage.');
        }
        $directory = $stagingRoot . '/restore-' . bin2hex(random_bytes(12));
        if (!@mkdir($directory, 0700) || !@chmod($directory, 0700)) {
            throw new RuntimeException('Could not create private restore staging storage.');
        }
        $stagedBackupPath = $directory . '/' . basename($backupPath);
        try {
            foreach ($sourcePaths as $sourcePath) {
                $targetPath = $directory . '/' . basename($sourcePath);
                if (!@copy($sourcePath, $targetPath) || !@chmod($targetPath, 0600)) {
                    throw new RuntimeException('Could not stage the database backup set.');
                }
            }
            return [$directory, $stagedBackupPath];
        } catch (\Throwable $e) {
            $this->removeStagingDirectory($directory);
            throw $e;
        }
    }

    private function removeStagingDirectory(string $directory): void
    {
        $cleanupFailed = false;
        foreach (glob($directory . '/*') ?: [] as $path) {
            if ((is_file($path) || is_link($path)) && !@unlink($path)) {
                $cleanupFailed = true;
            } elseif (is_dir($path)) {
                $cleanupFailed = true;
            }
        }
        if (is_dir($directory) && !@rmdir($directory)) {
            $cleanupFailed = true;
        }
        if ($cleanupFailed) {
            IncidentReporter::report(
                new RuntimeException('Restore staging cleanup failed.'),
                'CPE_RESTORE_STAGING_CLEANUP_FAILED',
                'persistence',
                ['operation' => 'database_restore.cleanup', 'status' => 'failed'],
            );
        }
    }

    private function restorePostgres(string $backupPath): void
    {
        $url = trim((string) ($this->postgresUrl ?? getenv('CPE_DATABASE_URL') ?: ''));
        if ($url === '') {
            throw new UserVisibleException('DATABASE_RESTORE_CONFIGURATION_REQUIRED', 'PostgreSQL restore configuration is incomplete.');
        }
        [$safeUrl, $password] = $this->safePostgresUrl($url);
        $binary = $this->pgRestoreBinary();
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['PGPASSWORD'] = $password;
        Database::reset();
        $process = proc_open(
            [
                $binary,
                '--clean',
                '--if-exists',
                '--no-owner',
                '--no-privileges',
                '--single-transaction',
                '--dbname=' . $safeUrl,
                $backupPath,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start pg_restore.');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('PostgreSQL restore process failed.');
        }
    }

    private function assertFormat(string $backupPath, string $driver): void
    {
        $extension = strtolower(pathinfo($backupPath, PATHINFO_EXTENSION));
        if (!in_array($driver, ['sqlite', 'pgsql'], true)
            || ($driver === 'sqlite' && $extension !== 'sqlite')
            || ($driver === 'pgsql' && $extension !== 'pgdump')) {
            throw new UserVisibleException(
                'DATABASE_BACKUP_FORMAT_INVALID',
                $driver === 'sqlite'
                    ? 'SQLite restore requires a .sqlite backup.'
                    : ($driver === 'pgsql'
                        ? 'PostgreSQL restore requires a .pgdump custom-format backup.'
                        : 'The database backup format is invalid.'),
            );
        }
    }

    /** @param array<string, int|string> $metadata
     *  @param array{owner_kind: string, owner_contract_version: int, institution_public_id: string} $targetIdentity
     */
    private function assertMetadataMatchesTarget(array $metadata, string $driver, array $targetIdentity): void
    {
        if (($metadata['driver'] ?? null) !== $driver
            || ($metadata['owner_kind'] ?? null) !== $targetIdentity['owner_kind']
            || ($metadata['owner_contract_version'] ?? null) !== $targetIdentity['owner_contract_version']
            || !hash_equals(
                $targetIdentity['institution_public_id'],
                (string) ($metadata['institution_public_id'] ?? ''),
            )) {
            throw new UserVisibleException(
                'DATABASE_BACKUP_IDENTITY_MISMATCH',
                'The backup does not belong to this installed database identity.',
            );
        }
    }

    /** @param array<string, int|string> $metadata */
    private function inspectSqliteBackup(string $backupPath, array $metadata): void
    {
        $realPath = realpath($backupPath);
        if ($realPath === false) {
            throw new UserVisibleException('DATABASE_BACKUP_FORMAT_INVALID', 'SQLite backup validation failed.');
        }
        $uriPath = str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $realPath);
        try {
            $backup = new PDO('sqlite:file:' . $uriPath . '?mode=ro&immutable=1');
            $backup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $backup->exec('PRAGMA query_only = ON');
            $integrity = $backup->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
            $backupIdentity = BackupMetadata::databaseIdentity($backup, 'sqlite');
        } catch (\Throwable $e) {
            throw new UserVisibleException(
                'DATABASE_BACKUP_STRUCTURE_INVALID',
                'SQLite backup validation failed.',
                $e,
            );
        }
        if ($integrity !== ['ok']) {
            throw new UserVisibleException('DATABASE_BACKUP_STRUCTURE_INVALID', 'SQLite backup validation failed.');
        }
        $this->assertMetadataMatchesTarget($metadata, 'sqlite', $backupIdentity);
    }

    private function inspectPostgresBackup(string $backupPath): void
    {
        $process = proc_open(
            [$this->pgRestoreBinary(), '--list', $backupPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new UserVisibleException('DATABASE_BACKUP_STRUCTURE_INVALID', 'PostgreSQL backup validation failed.');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        foreach (['cpe_database_ownership', 'institutions', 'settings'] as $requiredRelation) {
            if ($exitCode !== 0 || !str_contains($stdout, $requiredRelation)) {
                throw new UserVisibleException(
                    'DATABASE_BACKUP_STRUCTURE_INVALID',
                    'PostgreSQL backup validation failed.',
                );
            }
        }
    }

    private function pgRestoreBinary(): string
    {
        $configured = trim((string) (getenv('CPE_PG_RESTORE_BINARY') ?: ''));
        $candidates = array_filter([
            $configured,
            '/opt/homebrew/opt/libpq/bin/pg_restore',
            '/usr/local/opt/libpq/bin/pg_restore',
            '/usr/bin/pg_restore',
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        throw new UserVisibleException(
            'DATABASE_RESTORE_TOOL_REQUIRED',
            'PostgreSQL restore requires the pg_restore command-line tool.',
        );
    }

    private function safePostgresUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['postgres', 'postgresql'], true)) {
            throw new UserVisibleException('DATABASE_RESTORE_CONFIGURATION_INVALID', 'PostgreSQL restore configuration is invalid.');
        }
        $user = rawurldecode((string) ($parts['user'] ?? ''));
        $password = rawurldecode((string) ($parts['pass'] ?? ''));
        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (int) ($parts['port'] ?? 5432);
        $database = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
        if ($user === '' || $database === '') {
            throw new UserVisibleException('DATABASE_RESTORE_CONFIGURATION_INVALID', 'PostgreSQL restore configuration is invalid.');
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return [
            sprintf('postgresql://%s@%s:%d/%s%s', rawurlencode($user), $host, $port, rawurlencode($database), $query),
            $password,
        ];
    }
}
