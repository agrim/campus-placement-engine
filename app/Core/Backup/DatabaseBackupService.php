<?php

declare(strict_types=1);

namespace App\Core\Backup;

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

    public function create(string $prefix, ?string $directory = null): array
    {
        if (preg_match('/^[a-z0-9_-]+$/', $prefix) !== 1) {
            throw new RuntimeException('Backup prefix must use lowercase letters, numbers, hyphens, or underscores.');
        }
        $directory ??= (string) (getenv('CPE_BACKUP_DIR') ?: cpe_data_path('backups'));
        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new RuntimeException('Could not create backup directory: ' . $directory);
        }

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
        $checksumPath = $target . '.sha256';
        if (file_put_contents($checksumPath, $checksum . '  ' . basename($target) . "\n") === false) {
            throw new RuntimeException('Could not write database backup checksum.');
        }
        return [
            'path' => $target,
            'checksum_path' => $checksumPath,
            'sha256' => $checksum,
            'driver' => $driver,
        ];
    }

    private function createPostgresBackup(string $prefix, string $directory): array
    {
        $url = trim((string) ($this->postgresUrl ?? getenv('CPE_DATABASE_URL') ?: ''));
        if ($url === '') {
            throw new RuntimeException('PostgreSQL backups require CPE_DATABASE_URL.');
        }
        $binary = $this->pgDumpBinary();
        $target = rtrim($directory, '/') . '/' . $prefix . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.pgdump';
        [$safeUrl, $password] = $this->safePostgresUrl($url);
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['PGPASSWORD'] = $password;
        $process = proc_open(
            [$binary, '--format=custom', '--no-owner', '--no-privileges', '--file=' . $target, '--dbname=' . $safeUrl],
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
        $checksumPath = $target . '.sha256';
        if (file_put_contents($checksumPath, $checksum . '  ' . basename($target) . "\n") === false) {
            throw new RuntimeException('Could not write PostgreSQL backup checksum.');
        }
        return [
            'path' => $target,
            'checksum_path' => $checksumPath,
            'sha256' => $checksum,
            'driver' => 'pgsql',
        ];
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

    private function safePostgresUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['postgres', 'postgresql'], true)) {
            throw new RuntimeException('CPE_DATABASE_URL must be a postgresql:// URL for backups.');
        }
        $user = rawurldecode((string) ($parts['user'] ?? ''));
        $password = rawurldecode((string) ($parts['pass'] ?? ''));
        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (int) ($parts['port'] ?? 5432);
        $database = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $safe = sprintf(
            'postgresql://%s@%s:%d/%s%s',
            rawurlencode($user),
            $host,
            $port,
            rawurlencode($database),
            $query,
        );
        return [$safe, $password];
    }
}
