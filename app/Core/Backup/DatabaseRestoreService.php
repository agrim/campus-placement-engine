<?php

declare(strict_types=1);

namespace App\Core\Backup;

use App\Support\Database;
use RuntimeException;

final class DatabaseRestoreService
{
    public function __construct(private readonly ?string $postgresUrl = null)
    {
    }

    public function restore(string $backupPath, ?string $safetyDirectory = null): array
    {
        $backupPath = trim($backupPath);
        if ($backupPath === '' || !is_file($backupPath)) {
            throw new RuntimeException('Provide a readable database backup path.');
        }
        $this->verifyChecksum($backupPath);
        if (Database::connection()->inTransaction()) {
            throw new RuntimeException('Database restore cannot run inside an active transaction.');
        }
        $safety = (new DatabaseBackupService(Database::connection(), $this->postgresUrl))
            ->create('restore-safety', $safetyDirectory);
        $driver = Database::driver();
        if ($driver === 'sqlite') {
            $this->restoreSqlite($backupPath);
        } elseif ($driver === 'pgsql') {
            $this->restorePostgres($backupPath);
        } else {
            throw new RuntimeException('Database restore is not configured for driver: ' . $driver);
        }
        Database::reset();
        if (!Database::isInstalled()) {
            throw new RuntimeException('Restore completed, but the restored database does not pass the installation check. Safety backup: ' . $safety['path']);
        }
        return [
            'path' => $backupPath,
            'driver' => $driver,
            'safety_path' => $safety['path'],
            'safety_checksum_path' => $safety['checksum_path'],
        ];
    }

    public function verifyChecksum(string $path): void
    {
        $checksumPath = $path . '.sha256';
        if (!is_file($checksumPath)) {
            throw new RuntimeException('Backup checksum file is required: ' . $checksumPath);
        }
        $contents = trim((string) file_get_contents($checksumPath));
        $expected = strtolower(strtok($contents, " \t"));
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            throw new RuntimeException('Backup checksum file is invalid: ' . $checksumPath);
        }
        $actual = hash_file('sha256', $path);
        if ($actual === false || !hash_equals($expected, strtolower($actual))) {
            throw new RuntimeException('Backup checksum verification failed for: ' . $path);
        }
    }

    private function restoreSqlite(string $backupPath): void
    {
        if (strtolower(pathinfo($backupPath, PATHINFO_EXTENSION)) !== 'sqlite') {
            throw new RuntimeException('SQLite restore requires a .sqlite backup.');
        }
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

    private function restorePostgres(string $backupPath): void
    {
        if (strtolower(pathinfo($backupPath, PATHINFO_EXTENSION)) !== 'pgdump') {
            throw new RuntimeException('PostgreSQL restore requires a .pgdump custom-format backup.');
        }
        $url = trim((string) ($this->postgresUrl ?? getenv('CPE_DATABASE_URL') ?: ''));
        if ($url === '') {
            throw new RuntimeException('PostgreSQL restores require CPE_DATABASE_URL.');
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
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException('pg_restore failed' . ($detail === '' ? '.' : ': ' . $detail));
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
        throw new RuntimeException('PostgreSQL restores require pg_restore. Set CPE_PG_RESTORE_BINARY to its absolute path.');
    }

    private function safePostgresUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['postgres', 'postgresql'], true)) {
            throw new RuntimeException('PostgreSQL restore URL must use postgresql://.');
        }
        $user = rawurldecode((string) ($parts['user'] ?? ''));
        $password = rawurldecode((string) ($parts['pass'] ?? ''));
        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (int) ($parts['port'] ?? 5432);
        $database = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
        if ($user === '' || $database === '') {
            throw new RuntimeException('PostgreSQL restore URL must include a username and database.');
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return [
            sprintf('postgresql://%s@%s:%d/%s%s', rawurlencode($user), $host, $port, rawurlencode($database), $query),
            $password,
        ];
    }
}
