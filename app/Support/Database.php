<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Install\PortalKernelSynchronizer;
use App\Core\Persistence\DatabaseOwnership;
use App\Core\Persistence\DatabaseLockException;
use App\Core\Persistence\DatabaseConnectionInvalidException;
use App\Core\Persistence\SqlMigrationRunner;
use App\Core\Persistence\ConnectionProvider;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Modules\Placement\Install\LegacyDomainSynchronizer;
use App\Modules\Placement\Workflow\WorkflowPublisher;
use PDO;
use Throwable;

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

    public static function migrate(bool $synchronize = true): void
    {
        try {
            $pdo = self::connection();
            DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
            $migrationDirectory = self::driver() === 'pgsql' ? 'database/migrations/pgsql' : 'database/migrations';
            $afterMigrations = $synchronize
                ? static function (PDO $pdo): void {
                    (new PortalKernelSynchronizer())->synchronize($pdo);
                    (new LegacyDomainSynchronizer())->synchronize($pdo);
                    (new WorkflowPublisher($pdo))->synchronize();
                }
                : null;
            (new SqlMigrationRunner(
                $pdo,
                'migrations',
                cpe_path($migrationDirectory),
                'cpe.engine-migrations',
            ))->run($afterMigrations);
        } catch (Throwable $e) {
            if (self::failureRequiresConnectionReset($e)) {
                self::reset();
            }
            throw $e;
        }
    }

    public static function adoptInstalledEngineOwnershipForUpgrade(): string
    {
        try {
            $pdo = self::connection();
            $before = DatabaseOwnership::strictInstalledEngineIdentity($pdo);
            DatabaseOwnership::claimOrVerifyInstalledEngine($pdo, $before);
            $after = DatabaseOwnership::strictInstalledEngineIdentity($pdo);
            if (!hash_equals($before, $after)) {
                throw new \RuntimeException(
                    DatabaseOwnership::ERROR_CORRUPT
                    . ': installed institution identity changed during ownership adoption.',
                );
            }
            return $after;
        } catch (Throwable $e) {
            if (self::failureRequiresConnectionReset($e)) {
                self::reset();
            }
            throw $e;
        }
    }

    private static function failureRequiresConnectionReset(Throwable $e): bool
    {
        $lockFailure = DatabaseLockException::find($e);
        if ($lockFailure !== null && $lockFailure->requiresConnectionReset()) {
            return true;
        }
        $cleanupFailure = DatabaseConnectionInvalidException::find($e);
        return $cleanupFailure !== null && $cleanupFailure->requiresConnectionReset();
    }

    /**
     * Strict read-only probe used before installation may claim ownership or
     * apply migrations. A missing settings relation means an install may
     * proceed; every other probe failure is allowed to escape and fail closed.
     */
    public static function hasInstalledMarkerStrict(): bool
    {
        $driver = self::driver();
        if ($driver === 'sqlite') {
            $identifier = self::path();
            if ($identifier !== ':memory:'
                && !str_starts_with($identifier, 'file:')
                && !is_file($identifier)) {
                return false;
            }
            $pdo = self::connection();
            $relations = $pdo->query(
                "SELECT type FROM sqlite_master WHERE name = 'settings' ORDER BY type",
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($relations === []) {
                return false;
            }
            if (count($relations) !== 1 || !in_array($relations[0], ['table', 'view'], true)) {
                throw new \RuntimeException('SQLite settings relation is invalid for installation preflight.');
            }
            return $pdo->query("SELECT 1 FROM settings WHERE key = 'installed_at' LIMIT 1")->fetchColumn() !== false;
        }
        if ($driver === 'pgsql') {
            $pdo = self::connection();
            $schema = trim((string) $pdo->query('SELECT current_schema()')->fetchColumn());
            if ($schema === '' || in_array(strtolower($schema), ['pg_catalog', 'information_schema'], true)) {
                throw new \RuntimeException('PostgreSQL application schema is unavailable for installation preflight.');
            }
            $relation = $pdo->prepare(
                "SELECT c.relkind
                 FROM pg_catalog.pg_class c
                 JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = CAST(? AS TEXT) AND c.relname = 'settings'",
            );
            $relation->execute([$schema]);
            $relations = $relation->fetchAll(PDO::FETCH_COLUMN);
            if ($relations === []) {
                return false;
            }
            if (count($relations) !== 1) {
                throw new \RuntimeException('PostgreSQL settings relation is ambiguous for installation preflight.');
            }
            $qualifiedSettings = '"' . str_replace('"', '""', $schema) . '"."settings"';
            return $pdo->query(
                "SELECT 1 FROM {$qualifiedSettings} WHERE key = 'installed_at' LIMIT 1",
            )->fetchColumn() !== false;
        }
        throw new \RuntimeException('Unsupported database driver for installation preflight.');
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
