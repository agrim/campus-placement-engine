<?php

declare(strict_types=1);

namespace App\Core\Backup;

use App\Core\Http\UserVisibleException;
use App\Infrastructure\Persistence\PostgresCommandConnectionSpec;
use App\Infrastructure\Persistence\PostgresConnectionPolicy;
use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Support\Database;
use PDO;
use RuntimeException;

final class DatabaseBackupService
{
    public function __construct(
        private ?PDO $pdo = null,
        private readonly ?string $postgresUrl = null,
    )
    {
        $this->pdo ??= Database::connection();
    }

    public function create(string $prefix, ?string $directory = null): BackupArtifact
    {
        if (preg_match('/^[a-z0-9_-]+$/', $prefix) !== 1) {
            throw new RuntimeException('Backup prefix must use lowercase letters, numbers, hyphens, or underscores.');
        }
        $directory ??= self::configuredDirectory();
        self::prepareDirectory($directory);

        $driver = Database::driver();
        if ($driver === 'pgsql') {
            return $this->createPostgresBackup($prefix, $directory);
        }
        if ($driver !== 'sqlite') {
            throw new RuntimeException('Database backup is not configured for driver: ' . $driver);
        }
        $target = rtrim($directory, '/') . '/' . $prefix . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
        $source = Database::path();
        if (!is_file($source)) {
            throw new RuntimeException('No SQLite database found to back up.');
        }
        $backupConnection = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $backupConnection->exec('PRAGMA busy_timeout = 5000');
        $backupConnection->exec('VACUUM INTO ' . $backupConnection->quote($target));
        $backupConnection = null;
        if (!is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('SQLite backup did not produce a readable database.');
        }
        $checksum = hash_file('sha256', $target);
        if ($checksum === false) {
            throw new RuntimeException('Could not checksum database backup.');
        }
        $snapshot = new PDO('sqlite:' . $target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            return $this->writeArtifactSidecars($target, $checksum, $driver, $snapshot, true);
        } finally {
            $snapshot = null;
        }
    }

    public static function configuredDirectory(): string
    {
        return (string) (getenv('CPE_BACKUP_DIR') ?: cpe_data_path('backups'));
    }

    public static function directoryIsSafe(string $directory): bool
    {
        $stat = @lstat($directory);
        return $stat !== false
            && (($stat['mode'] ?? 0) & 0170000) === 0040000
            && !is_link($directory);
    }

    private static function prepareDirectory(string $directory): void
    {
        if (!file_exists($directory) && !is_link($directory) && !@mkdir($directory, 0775, true)) {
            throw new UserVisibleException(
                'DATABASE_BACKUP_STORAGE_UNAVAILABLE',
                'Configured backup storage is unavailable or unsafe.',
            );
        }
        if (!self::directoryIsSafe($directory) || !is_readable($directory) || !is_writable($directory)) {
            throw new UserVisibleException(
                'DATABASE_BACKUP_STORAGE_UNAVAILABLE',
                'Configured backup storage is unavailable or unsafe.',
            );
        }
    }

    public function sealExistingSqliteArchive(string $path): BackupArtifact
    {
        if (is_link($path)
            || !is_file($path)
            || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'sqlite'
            || file_exists($path . '.sha256')
            || file_exists($path . BackupMetadata::SUFFIX)) {
            throw new RuntimeException('SQLite backup sealing requires a new unsealed archive.');
        }
        $driver = strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver !== 'sqlite') {
            throw new RuntimeException('SQLite backup sealing requires a SQLite connection.');
        }
        $mainPaths = [];
        foreach ($this->pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database) {
            if (($database['name'] ?? null) === 'main') {
                $mainPaths[] = (string) ($database['file'] ?? '');
            }
        }
        $archiveRealPath = realpath($path);
        $connectionRealPath = count($mainPaths) === 1 ? realpath($mainPaths[0]) : false;
        if ($archiveRealPath === false
            || $connectionRealPath === false
            || !hash_equals($archiveRealPath, $connectionRealPath)) {
            throw new RuntimeException('SQLite backup sealing connection does not match the archive.');
        }
        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException('Could not checksum SQLite backup archive.');
        }
        return $this->writeArtifactSidecars($path, $checksum, 'sqlite', $this->pdo, false);
    }

    private function createPostgresBackup(string $prefix, string $directory): BackupArtifact
    {
        $connection = $this->postgresCommandConnection();
        $binary = $this->pgDumpBinary();
        $target = rtrim($directory, '/') . '/' . $prefix . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.pgdump';
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment = $connection->childEnvironment($environment);
        $process = proc_open(
            [$binary, '--format=custom', '--no-owner', '--no-privileges', '--file=' . $target, '--dbname=' . $connection->safeUri()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start pg_dump.');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || !is_file($target) || filesize($target) === 0) {
            if (is_file($target)) {
                unlink($target);
            }
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException('pg_dump failed' . ($detail === '' ? '.' : ': ' . $detail));
        }
        $checksum = hash_file('sha256', $target);
        if ($checksum === false) {
            throw new RuntimeException('Could not checksum PostgreSQL backup.');
        }
        return $this->writeArtifactSidecars($target, $checksum, 'pgsql', $this->pdo, true);
    }

    private function writeArtifactSidecars(
        string $target,
        string $archiveSha256,
        string $driver,
        PDO $identityDatabase,
        bool $removeArchiveOnFailure,
    ): BackupArtifact {
        $metadataPath = $target . BackupMetadata::SUFFIX;
        $checksumPath = $target . '.sha256';
        $createdSidecars = [];
        try {
            $metadata = BackupMetadata::create($identityDatabase, $driver, $archiveSha256);
            $json = json_encode(
                $metadata,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n";
            $this->writeExclusiveSidecar($metadataPath, $json, 'metadata');
            $createdSidecars[] = $metadataPath;
            $metadataSha256 = hash_file('sha256', $metadataPath);
            if ($metadataSha256 === false) {
                throw new RuntimeException('Could not checksum database backup metadata.');
            }
            $checksumContents = $archiveSha256 . '  ' . basename($target) . "\n"
                . $metadataSha256 . '  ' . basename($metadataPath) . "\n";
            $this->writeExclusiveSidecar($checksumPath, $checksumContents, 'checksum');
            $createdSidecars[] = $checksumPath;
            return new BackupArtifact(
                $target,
                $checksumPath,
                $metadataPath,
                $archiveSha256,
                $driver,
            );
        } catch (\Throwable $e) {
            $cleanupPaths = $createdSidecars;
            if ($removeArchiveOnFailure) {
                $cleanupPaths[] = $target;
            }
            foreach ($cleanupPaths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            throw $e;
        }
    }

    private function writeExclusiveSidecar(string $path, string $contents, string $label): void
    {
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not create database backup ' . $label . '.');
        }
        $written = 0;
        $length = strlen($contents);
        try {
            while ($written < $length) {
                $chunk = fwrite($handle, substr($contents, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new RuntimeException('Could not write database backup ' . $label . '.');
                }
                $written += $chunk;
            }
            if (!fflush($handle) || !@chmod($path, 0600)) {
                throw new RuntimeException('Could not secure database backup ' . $label . '.');
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);
            throw $e;
        }
        fclose($handle);
    }

    private function pgDumpBinary(): string
    {
        $configured = trim((string) (getenv('CPE_PG_DUMP_BINARY') ?: ''));
        $candidates = array_filter([
            $configured,
            '/opt/homebrew/opt/libpq/bin/pg_dump',
            '/usr/local/opt/libpq/bin/pg_dump',
            '/usr/bin/pg_dump',
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('PostgreSQL backups require pg_dump. Set CPE_PG_DUMP_BINARY to its absolute path.');
    }

    private function postgresCommandConnection(): PostgresCommandConnectionSpec
    {
        if ($this->postgresUrl !== null) {
            $url = trim($this->postgresUrl);
            if ($url === '') {
                throw new RuntimeException('PostgreSQL backups require CPE_DATABASE_URL.');
            }
            return PostgresConnectionProvider::fromUrl($url, 'PostgreSQL backup URL')->commandConnectionSpec();
        }
        if (trim((string) (getenv('CPE_DATABASE_URL') ?: '')) === '') {
            throw new RuntimeException('PostgreSQL backups require CPE_DATABASE_URL.');
        }
        return PostgresConnectionPolicy::commandConnectionFromEnvironment();
    }
}
