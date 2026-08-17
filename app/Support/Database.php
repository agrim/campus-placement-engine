<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Install\PortalKernelSynchronizer;
use App\Core\Persistence\ConnectionProvider;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Modules\Placement\Install\LegacyDomainSynchronizer;
use App\Modules\Placement\Workflow\WorkflowPublisher;
use PDO;
use RuntimeException;

final class Database
{
    private static ?ConnectionProvider $provider = null;

    public static function path(): string
    {
        return self::provider()->identifier();
    }

    public static function driver(): string
    {
        return self::provider()->driver();
    }

    public static function useProvider(ConnectionProvider $provider): void
    {
        self::$provider?->disconnect();
        self::$provider = $provider;
        if (class_exists(\App\Core\Portal::class, false)) {
            \App\Core\Portal::reset();
        }
    }

    public static function provider(): ConnectionProvider
    {
        if (self::$provider === null) {
            $driver = strtolower((string) (getenv('CPE_DB_DRIVER') ?: ''));
            $databaseUrl = trim((string) (getenv('CPE_DATABASE_URL') ?: ''));
            self::$provider = $driver === 'pgsql' || $driver === 'postgresql' || $databaseUrl !== ''
                ? PostgresConnectionProvider::fromEnvironment()
                : new SqliteConnectionProvider((string) (getenv('CPE_DB_PATH') ?: cpe_config('database.path')));
        }
        return self::$provider;
    }

    public static function connection(): PDO
    {
        return self::provider()->connection();
    }

    public static function reset(): void
    {
        self::$provider?->disconnect();
        self::$provider = null;
        if (class_exists(\App\Core\Portal::class, false)) {
            \App\Core\Portal::reset();
        }
    }

    public static function migrate(): void
    {
        $pdo = self::connection();
        $pdo->exec(self::driver() === 'pgsql'
            ? 'CREATE TABLE IF NOT EXISTS migrations (id BIGSERIAL PRIMARY KEY, migration TEXT NOT NULL UNIQUE, applied_at TEXT NOT NULL)'
            : 'CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT NOT NULL)');
        $migrationDirectory = self::driver() === 'pgsql' ? 'database/migrations/pgsql' : 'database/migrations';
        $files = glob(cpe_path($migrationDirectory . '/*.sql')) ?: [];
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            $exists = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
            $exists->execute([$name]);
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Unable to read migration: ' . $name);
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $stmt = $pdo->prepare('INSERT INTO migrations (migration, applied_at) VALUES (?, ?)');
                $stmt->execute([$name, cpe_now()]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        (new PortalKernelSynchronizer())->synchronize($pdo);
        (new LegacyDomainSynchronizer())->synchronize($pdo);
        (new WorkflowPublisher($pdo))->synchronize();
    }

    public static function isInstalled(): bool
    {
        if (self::driver() === 'sqlite' && !is_file(self::path())) {
            return false;
        }
        try {
            $pdo = self::connection();
            $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'installed_at'");
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function lastInsertId(PDO $pdo): int
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            return (int) $pdo->query('SELECT LASTVAL()')->fetchColumn();
        }
        return (int) $pdo->lastInsertId();
    }

    public static function groupConcat(string $expression): string
    {
        return self::driver() === 'pgsql'
            ? "STRING_AGG(CAST({$expression} AS TEXT), ', ')"
            : "GROUP_CONCAT({$expression}, ', ')";
    }

    public static function exactNumericPattern(string $expression, string $prefix, int $digits): string
    {
        if (self::driver() === 'pgsql') {
            return $expression . ' ~ ' . self::connection()->quote('^' . preg_quote($prefix, '/') . '[0-9]{' . $digits . '}$');
        }
        return $expression . ' GLOB ' . self::connection()->quote($prefix . str_repeat('[0-9]', $digits));
    }

    public static function serverVersion(): string
    {
        return self::driver() === 'pgsql'
            ? (string) self::connection()->query('SHOW server_version')->fetchColumn()
            : (string) self::connection()->query('SELECT sqlite_version()')->fetchColumn();
    }

    public static function pendingMigrations(): array
    {
        $directory = self::driver() === 'pgsql' ? 'database/migrations/pgsql' : 'database/migrations';
        $files = array_map('basename', glob(cpe_path($directory . '/*.sql')) ?: []);
        sort($files);
        try {
            $applied = self::connection()->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return $files;
        }
        return array_values(array_diff($files, array_map('strval', $applied)));
    }
}
