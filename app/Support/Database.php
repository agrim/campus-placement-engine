<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Install\InstallationState;
use App\Core\Install\InstallationStateUnavailable;
use App\Core\Install\PortalKernelSynchronizer;
use App\Core\Persistence\DatabaseOwnership;
use App\Core\Persistence\DatabaseLockException;
use App\Core\Persistence\DatabaseConnectionInvalidException;
use App\Core\Persistence\SqlMigrationRunner;
use App\Core\Persistence\ConnectionProvider;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use App\Security\SetupRecoveryAuthority;
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
     * apply migrations. Only an absent or empty target is fresh; ambiguous or
     * damaged schema state is allowed to escape and fail closed.
     */
    public static function hasInstalledMarkerStrict(): bool
    {
        return self::installationStateStrict() === InstallationState::INSTALLED;
    }

    public static function installationStateStrict(): string
    {
        return self::installationState(false);
    }

    /**
     * Authorized setup may recover only an exact Engine-owned target whose
     * installation marker was never committed. Callers must establish setup
     * authorization (or an explicit local CLI setup boundary) first.
     */
    public static function installationStateForAuthorizedSetupStrict(
        SetupRecoveryAuthority $authority,
    ): string
    {
        $authority->assertCurrentTarget();
        $state = self::installationState(true);
        if ($state !== InstallationState::RECOVERABLE) {
            throw InstallationStateUnavailable::state();
        }
        return $state;
    }

    /**
     * Internal continuation check for one Installer call that began against a
     * genuinely empty target. This grants no recovery capability and is not an
     * entry classification: Installer must first have observed FRESH locally.
     *
     * @internal
     */
    public static function freshInstallContinuationStateStrict(): string
    {
        $state = self::installationState(true);
        if (!in_array($state, [InstallationState::RECOVERABLE, InstallationState::INSTALLED], true)) {
            throw InstallationStateUnavailable::state();
        }
        return $state;
    }

    /**
     * Pre-session setup routing probe. It exposes no recovery capability: an
     * exact Engine-owned markerless target is merely allowed to remain
     * concealed until authorization, while every ambiguous target fails.
     */
    public static function assertSetupEntryTargetSafeStrict(): void
    {
        try {
            self::installationState(false);
            return;
        } catch (InstallationStateUnavailable) {
            $state = self::installationState(true);
            if ($state !== InstallationState::RECOVERABLE) {
                throw InstallationStateUnavailable::state();
            }
        }
    }

    private static function installationState(bool $allowOwnedRecovery): string
    {
        try {
            $driver = self::driver();
            if ($driver === 'sqlite') {
                $identifier = self::path();
                if ($identifier !== ':memory:'
                    && !str_starts_with($identifier, 'file:')
                    && !is_file($identifier)) {
                    return InstallationState::FRESH;
                }
            }
            $pdo = self::connection();
            if (DatabaseOwnership::targetIsEmptyStrict($pdo)) {
                return InstallationState::FRESH;
            }

            $installed = match ($driver) {
                'sqlite' => self::sqliteInstalledMarker($pdo),
                'pgsql' => self::postgresInstalledMarker($pdo),
                default => throw new \RuntimeException('Unsupported database driver for installation preflight.'),
            };
            if ($installed === true) {
                DatabaseOwnership::assertOwnedByReadOnlyStrict(
                    $pdo,
                    DatabaseOwnership::OWNER_ENGINE_INSTITUTION,
                );
                DatabaseOwnership::strictInstalledEngineIdentity($pdo);
                return InstallationState::INSTALLED;
            }
            if ($installed === false && $allowOwnedRecovery) {
                DatabaseOwnership::assertOwnedByReadOnlyStrict(
                    $pdo,
                    DatabaseOwnership::OWNER_ENGINE_INSTITUTION,
                );
                return InstallationState::RECOVERABLE;
            }
            throw InstallationStateUnavailable::state();
        } catch (InstallationStateUnavailable $failure) {
            throw $failure;
        } catch (Throwable) {
            throw InstallationStateUnavailable::state();
        }
    }

    /** null means the required settings relation itself is absent. */
    private static function sqliteInstalledMarker(PDO $pdo): ?bool
    {
        $relations = $pdo->query(
            "SELECT type FROM sqlite_master WHERE name = 'settings' ORDER BY type",
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($relations === []) {
            return null;
        }
        if (count($relations) !== 1 || !in_array($relations[0], ['table', 'view'], true)) {
            throw new \RuntimeException('SQLite settings relation is invalid for installation preflight.');
        }
        return self::hasCanonicalInstalledMarker($pdo, 'settings');
    }

    /** null means the required settings relation itself is absent. */
    private static function postgresInstalledMarker(PDO $pdo): ?bool
    {
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
            return null;
        }
        if (count($relations) !== 1 || !in_array($relations[0], ['r', 'p', 'v'], true)) {
            throw new \RuntimeException('PostgreSQL settings relation is invalid for installation preflight.');
        }
        $qualifiedSettings = '"' . str_replace('"', '""', $schema) . '"."settings"';
        return self::hasCanonicalInstalledMarker($pdo, $qualifiedSettings);
    }

    private static function hasCanonicalInstalledMarker(PDO $pdo, string $settingsRelation): bool
    {
        $values = $pdo->query(
            "SELECT value FROM {$settingsRelation} WHERE key = 'installed_at'",
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($values === []) {
            return false;
        }
        if (count($values) !== 1 || !is_string($values[0])) {
            throw new \RuntimeException('Installation marker is invalid.');
        }
        $value = $values[0];
        $timestamp = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1
            ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'))
            : false;
        if ($timestamp === false || $timestamp->format('Y-m-d H:i:s') !== $value) {
            throw new \RuntimeException('Installation marker is invalid.');
        }
        return true;
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
