<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Persistence\DatabaseLock;
use App\Core\Persistence\DatabaseOwnership;
use App\Core\Persistence\SqlMigrationRunner;
use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use App\Support\Database;

$mode = (string) (getenv('CPE_MIGRATION_TEST_MODE') ?: 'fixture_migrate');
$sqlitePath = (string) getenv('CPE_MIGRATION_TEST_SQLITE_PATH');
$databaseUrl = (string) getenv('CPE_MIGRATION_TEST_DATABASE_URL');
$schema = (string) getenv('CPE_MIGRATION_TEST_SCHEMA');
$registry = (string) (getenv('CPE_MIGRATION_TEST_REGISTRY') ?: 'fixture_migrations');
$directory = (string) getenv('CPE_MIGRATION_TEST_DIRECTORY');
$namespace = (string) (getenv('CPE_MIGRATION_TEST_NAMESPACE') ?: 'cpe.engine-migrations');
$timeout = (int) (getenv('CPE_MIGRATION_TEST_TIMEOUT_MS') ?: '5000');
$ready = (string) getenv('CPE_MIGRATION_TEST_READY');
$start = (string) getenv('CPE_MIGRATION_TEST_START');
$signal = (string) getenv('CPE_MIGRATION_TEST_SIGNAL');
$release = (string) getenv('CPE_MIGRATION_TEST_RELEASE');
$workingDirectory = (string) getenv('CPE_MIGRATION_TEST_CWD');
$applicationName = (string) getenv('CPE_MIGRATION_TEST_APPLICATION_NAME');

function migration_worker_wait(string $path, string $label, int $timeoutMilliseconds = 15000): void
{
    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    while (!is_file($path)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Migration worker ' . $label . ' timed out.');
        }
        usleep(1000);
    }
}

try {
    if ($workingDirectory !== '' && !chdir($workingDirectory)) {
        throw new RuntimeException('Could not enter migration worker directory.');
    }
    if ($databaseUrl !== '') {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $schema) !== 1) {
            throw new RuntimeException('Migration worker schema is invalid.');
        }
        $provider = PostgresConnectionProvider::fromUrl($databaseUrl, 'migration test database URL');
        $pdo = $provider->connection();
        $pdo->exec('SET search_path TO "' . $schema . '"');
        if ($applicationName !== '') {
            $pdo->exec('SET application_name = ' . $pdo->quote($applicationName));
        }
    } else {
        if ($sqlitePath === '') {
            throw new RuntimeException('Migration worker SQLite path is missing.');
        }
        $provider = new SqliteConnectionProvider($sqlitePath);
        $pdo = $provider->connection();
    }

    if ($ready === '' || file_put_contents($ready, "ready\n") === false) {
        throw new RuntimeException('Could not publish migration worker readiness.');
    }
    migration_worker_wait($start, 'start barrier');

    if ($mode === 'hold_lock') {
        DatabaseLock::synchronized(
            $pdo,
            $namespace,
            static function () use ($signal, $release): void {
                if ($signal === '' || file_put_contents($signal, "locked\n") === false) {
                    throw new RuntimeException('Could not publish migration lock acquisition.');
                }
                migration_worker_wait($release, 'lock release');
            },
            $timeout,
        );
        fwrite(STDOUT, "released\n");
        exit(0);
    }

    if ($mode === 'database_migrate') {
        Database::useProvider($provider);
        DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        if ($signal !== '' && file_put_contents($signal, "ownership-verified\n") === false) {
            throw new RuntimeException('Could not publish pre-migration ownership verification.');
        }
        Database::migrate(false);
        fwrite(STDOUT, "database-migrated\n");
        exit(0);
    }

    if ($mode === 'crash_migrate' && $databaseUrl === '') {
        if (!method_exists($pdo, 'createFunction') && !method_exists($pdo, 'sqliteCreateFunction')) {
            throw new RuntimeException('SQLite test functions are unavailable.');
        }
        $pause = static function () use ($signal, $release): int {
            if ($signal === '' || file_put_contents($signal, "paused\n") === false) {
                throw new RuntimeException('Could not publish SQLite migration pause.');
            }
            migration_worker_wait($release, 'crash release', 30000);
            return 1;
        };
        if (method_exists($pdo, 'createFunction')) {
            $pdo->createFunction('cpe_test_pause', $pause);
        } else {
            @$pdo->sqliteCreateFunction('cpe_test_pause', $pause);
        }
    }

    if ($mode === 'crash_migrate' && $databaseUrl !== '') {
        $backendPid = trim((string) $pdo->query('SELECT pg_backend_pid()::text')->fetchColumn());
        if (preg_match('/^[0-9]+$/', $backendPid) !== 1) {
            throw new RuntimeException('Could not resolve PostgreSQL crash-worker backend PID.');
        }
        if ($signal === '' || file_put_contents($signal, $backendPid . "\n") === false) {
            throw new RuntimeException('Could not publish PostgreSQL crash-worker backend PID.');
        }
    }

    (new SqlMigrationRunner($pdo, $registry, $directory, $namespace, $timeout))->run();
    fwrite(STDOUT, "fixture-migrated\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
