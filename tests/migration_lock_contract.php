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

function migration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function migration_assert_throws(string $identifier, callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $e) {
        migration_assert(str_contains($e->getMessage(), $identifier), $message . ': ' . $e->getMessage());
        return $e;
    }
    throw new RuntimeException($message . ': no exception was thrown.');
}

function migration_exception_chain_contains(Throwable $exception, string $needle): bool
{
    do {
        if (str_contains($exception->getMessage(), $needle)) {
            return true;
        }
        $exception = $exception->getPrevious();
    } while ($exception !== null);
    return false;
}

final class MigrationCleanupFailurePdo extends PDO
{
    public bool $failRollback = false;
    public bool $failSavepointRollback = false;

    public function __construct(string $dsn)
    {
        parent::__construct($dsn);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function rollBack(): bool
    {
        if ($this->failRollback) {
            throw new RuntimeException('synthetic transaction rollback cleanup failure');
        }
        return parent::rollBack();
    }

    public function exec(string $statement): int|false
    {
        if ($this->failSavepointRollback
            && str_starts_with(strtoupper(trim($statement)), 'ROLLBACK TO SAVEPOINT')) {
            throw new RuntimeException('synthetic savepoint rollback cleanup failure');
        }
        return parent::exec($statement);
    }
}

function migration_sqlite(string $path): PDO
{
    $pdo = (new SqliteConnectionProvider($path))->connection();
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function migration_postgres(string $url, string $schema): PDO
{
    $pdo = PostgresConnectionProvider::fromUrl($url, 'migration test database URL')->connection();
    $pdo->exec('SET search_path TO "' . $schema . '"');
    return $pdo;
}

function migration_has_sqlite_relation(PDO $pdo, string $name): bool
{
    $query = $pdo->prepare("SELECT 1 FROM main.sqlite_schema WHERE type IN ('table', 'view') AND name = ?");
    $query->execute([$name]);
    return $query->fetchColumn() !== false;
}

function migration_has_postgres_relation(PDO $pdo, string $schema, string $name): bool
{
    $query = $pdo->prepare(
        'SELECT 1 FROM pg_catalog.pg_class c '
        . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
        . 'WHERE n.nspname = ? AND c.relname = ? AND c.relkind IN (\'r\', \'p\', \'v\')',
    );
    $query->execute([$schema, $name]);
    return $query->fetchColumn() !== false;
}

function migration_write_fixture(string $directory, string $name, string $sql): void
{
    migration_assert(preg_match('/^[0-9]{3}_[a-z0-9_]+\.sql$/', $name) === 1, 'Unsafe migration fixture filename.');
    migration_assert(file_put_contents($directory . '/' . $name, $sql) !== false, 'Could not write migration fixture.');
}

/**
 * @param array<int, array<string, mixed>> $configs
 * @return array{barrier: string, start: string, workers: array<int, array{process: resource, pipes: array<int, resource>}>}
 */
function migration_spawn_group(array $configs): array
{
    $barrier = sys_get_temp_dir() . '/cpe-migration-barrier-' . bin2hex(random_bytes(6));
    migration_assert(mkdir($barrier, 0700, true), 'Could not create migration worker barrier.');
    $start = $barrier . '/start';
    $workers = [];
    try {
        foreach ($configs as $index => $config) {
            $ready = $barrier . '/ready-' . $index;
            $environment = getenv();
            migration_assert(is_array($environment), 'Could not read migration worker environment.');
            $mapping = [
                'mode' => 'CPE_MIGRATION_TEST_MODE',
                'sqlite_path' => 'CPE_MIGRATION_TEST_SQLITE_PATH',
                'database_url' => 'CPE_MIGRATION_TEST_DATABASE_URL',
                'schema' => 'CPE_MIGRATION_TEST_SCHEMA',
                'registry' => 'CPE_MIGRATION_TEST_REGISTRY',
                'directory' => 'CPE_MIGRATION_TEST_DIRECTORY',
                'namespace' => 'CPE_MIGRATION_TEST_NAMESPACE',
                'timeout_ms' => 'CPE_MIGRATION_TEST_TIMEOUT_MS',
                'signal' => 'CPE_MIGRATION_TEST_SIGNAL',
                'release' => 'CPE_MIGRATION_TEST_RELEASE',
                'cwd' => 'CPE_MIGRATION_TEST_CWD',
                'application_name' => 'CPE_MIGRATION_TEST_APPLICATION_NAME',
            ];
            foreach ($mapping as $key => $environmentName) {
                $environment[$environmentName] = (string) ($config[$key] ?? '');
            }
            $environment['CPE_MIGRATION_TEST_READY'] = $ready;
            $environment['CPE_MIGRATION_TEST_START'] = $start;
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/migration_lock_worker.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                __DIR__ . '/..',
                $environment,
            );
            migration_assert(is_resource($process), 'Could not start migration worker.');
            fclose($pipes[0]);
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'ready' => $ready];
        }
        $deadline = hrtime(true) + 10_000_000_000;
        foreach ($workers as $worker) {
            while (!is_file($worker['ready'])) {
                if (hrtime(true) >= $deadline) {
                    migration_terminate_workers($workers);
                    throw new RuntimeException('Migration worker did not reach its barrier.');
                }
                usleep(1000);
            }
        }
        return ['barrier' => $barrier, 'start' => $start, 'workers' => $workers];
    } catch (Throwable $e) {
        migration_terminate_workers($workers);
        migration_remove_tree($barrier);
        throw $e;
    }
}

/** @param array<int, array{process: resource, pipes: array<int, resource>}> $workers */
function migration_terminate_workers(array $workers): void
{
    foreach ($workers as $worker) {
        $status = proc_get_status($worker['process']);
        if (($status['running'] ?? false) === true) {
            proc_terminate($worker['process']);
            usleep(20_000);
            $status = proc_get_status($worker['process']);
            if (($status['running'] ?? false) === true) {
                proc_terminate($worker['process'], 9);
            }
        }
    }
}

/** @param array{start: string} $group */
function migration_start_group(array $group): void
{
    migration_assert(file_put_contents($group['start'], "start\n") !== false, 'Could not release migration worker barrier.');
}

/**
 * @param array{barrier: string, start: string, workers: array<int, array{process: resource, pipes: array<int, resource>}>} $group
 * @return array<int, array{code: int, stdout: string, stderr: string}>
 */
function migration_collect_group(array $group, int $timeoutMilliseconds = 15000): array
{
    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    $codes = [];
    try {
        while (count($codes) < count($group['workers'])) {
            foreach ($group['workers'] as $index => $worker) {
                if (isset($codes[$index])) {
                    continue;
                }
                $status = proc_get_status($worker['process']);
                if (($status['running'] ?? false) === false) {
                    $codes[$index] = (int) ($status['exitcode'] ?? -1);
                }
            }
            if (count($codes) === count($group['workers'])) {
                break;
            }
            if (hrtime(true) >= $deadline) {
                migration_terminate_workers($group['workers']);
                throw new RuntimeException('Migration workers exceeded the completion deadline.');
            }
            usleep(1000);
        }
        $results = [];
        foreach ($group['workers'] as $index => $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $closed = proc_close($worker['process']);
            $results[] = [
                'code' => $codes[$index] >= 0 ? $codes[$index] : $closed,
                'stdout' => (string) $stdout,
                'stderr' => (string) $stderr,
            ];
        }
        return $results;
    } finally {
        migration_remove_tree($group['barrier']);
    }
}

function migration_wait_file(string $path, string $message, int $timeoutMilliseconds = 10000): void
{
    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    while (!is_file($path)) {
        migration_assert(hrtime(true) < $deadline, $message);
        usleep(1000);
    }
}

function migration_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            migration_remove_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
}

/** @param array<int, array{code: int, stdout: string, stderr: string}> $results */
function migration_assert_successes(array $results, string $label): void
{
    foreach ($results as $result) {
        migration_assert($result['code'] === 0, $label . ' failed: ' . $result['stderr']);
    }
}

function migration_sqlite_contract(): void
{
    $root = sys_get_temp_dir() . '/cpe-migration-lock-' . bin2hex(random_bytes(6));
    migration_assert(mkdir($root, 0700, true), 'Could not create migration contract directory.');
    try {
        $emptyDirectory = $root . '/empty';
        migration_assert(mkdir($emptyDirectory, 0700), 'Could not create empty migration directory.');
        migration_assert_throws(
            SqlMigrationRunner::ERROR_REGISTRY,
            static fn () => new SqlMigrationRunner(migration_sqlite(':memory:'), 'bad-name', $emptyDirectory, 'cpe.test-migrations'),
            'Unsafe registry identifier was accepted',
        );
        migration_assert_throws(
            SqlMigrationRunner::ERROR_DIRECTORY,
            static fn () => new SqlMigrationRunner(migration_sqlite(':memory:'), 'fixture_migrations', 'relative', 'cpe.test-migrations'),
            'Relative migration directory was accepted',
        );

        $orderingDirectory = $root . '/ordering';
        migration_assert(mkdir($orderingDirectory, 0700), 'Could not create ordering fixture directory.');
        migration_assert(mkdir($orderingDirectory . '/pgsql', 0700), 'Could not create intentionally ignored dialect directory.');
        migration_write_fixture($orderingDirectory, '002_insert_order.sql', "INSERT INTO ordered_product (value) VALUES ('second');");
        migration_write_fixture($orderingDirectory, '001_create_order.sql', 'CREATE TABLE ordered_product (value TEXT NOT NULL);');
        $orderingPdo = migration_sqlite(':memory:');
        (new SqlMigrationRunner($orderingPdo, 'fixture_migrations', $orderingDirectory, 'cpe.ordering-migrations'))->run();
        migration_assert((string) $orderingPdo->query('SELECT value FROM ordered_product')->fetchColumn() === 'second', 'Migration files did not execute in lexical order.');
        migration_assert(
            $orderingPdo->query('SELECT migration FROM fixture_migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
                === ['001_create_order.sql', '002_insert_order.sql'],
            'Migration registry order does not match sorted filenames.',
        );

        $sqliteControls = [
            'BEGIN EXCLUSIVE',
            'COMMIT TRANSACTION',
            'END TRANSACTION',
            'ABORT',
            'ROLLBACK TRANSACTION',
            'ROLLBACK TO SAVEPOINT malicious',
            'SAVEPOINT malicious',
            'RELEASE SAVEPOINT malicious',
        ];
        foreach ($sqliteControls as $index => $control) {
            $controlDirectory = $root . '/sqlite-control-' . $index;
            migration_assert(mkdir($controlDirectory, 0700), 'Could not create SQLite control fixture directory.');
            $product = 'forbidden_control_' . $index;
            migration_write_fixture(
                $controlDirectory,
                '001_control.sql',
                'CREATE TABLE ' . $product . ' (id INTEGER); ' . $control . ';',
            );
            $controlPdo = migration_sqlite(':memory:');
            $controlFailure = migration_assert_throws(
                SqlMigrationRunner::ERROR_MIGRATION,
                static fn () => (new SqlMigrationRunner(
                    $controlPdo,
                    'fixture_migrations',
                    $controlDirectory,
                    'cpe.control-' . $index,
                ))->run(),
                'SQLite top-level transaction control was accepted: ' . $control,
            );
            migration_assert(
                migration_exception_chain_contains($controlFailure, 'contains top-level transaction control SQL'),
                'SQLite control fixture failed after execution instead of during lexical preflight: ' . $control,
            );
            migration_assert(!migration_has_sqlite_relation($controlPdo, $product), 'Rejected SQLite control left product DDL behind.');
            migration_assert(!migration_has_sqlite_relation($controlPdo, 'fixture_migrations'), 'Rejected SQLite control left its fileless registry behind.');
        }

        $sqliteCommentBypassDirectory = $root . '/sqlite-comment-bypass';
        migration_assert(mkdir($sqliteCommentBypassDirectory, 0700), 'Could not create SQLite comment-bypass fixture directory.');
        migration_write_fixture(
            $sqliteCommentBypassDirectory,
            '001_comment_bypass.sql',
            'CREATE TABLE forbidden_comment_bypass (id INTEGER); /* outer /* inner */ END; */',
        );
        $sqliteCommentBypassPdo = migration_sqlite(':memory:');
        $sqliteCommentBypassFailure = migration_assert_throws(
            SqlMigrationRunner::ERROR_MIGRATION,
            static fn () => (new SqlMigrationRunner(
                $sqliteCommentBypassPdo,
                'fixture_migrations',
                $sqliteCommentBypassDirectory,
                'cpe.sqlite-comment-bypass',
            ))->run(),
            'SQLite nested-comment transaction-control bypass was accepted',
        );
        migration_assert(
            migration_exception_chain_contains($sqliteCommentBypassFailure, 'contains top-level transaction control SQL'),
            'SQLite nested-comment bypass was not rejected during lexical preflight.',
        );
        migration_assert(!migration_has_sqlite_relation($sqliteCommentBypassPdo, 'forbidden_comment_bypass'), 'SQLite comment bypass left product DDL behind.');
        migration_assert(!migration_has_sqlite_relation($sqliteCommentBypassPdo, 'fixture_migrations'), 'SQLite comment bypass left its fileless registry behind.');

        $scannerDirectory = $root . '/scanner-positive';
        migration_assert(mkdir($scannerDirectory, 0700), 'Could not create scanner-positive fixture directory.');
        migration_write_fixture(
            $scannerDirectory,
            '001_literals.sql',
            "-- BEGIN; COMMIT; END TRANSACTION\n"
            . "/* ROLLBACK; SAVEPOINT ignored; RELEASE ignored */\n"
            . "CREATE TABLE \"commit\" (value TEXT NOT NULL);\n"
            . "INSERT INTO \"commit\" (value) VALUES ('BEGIN; END TRANSACTION; ABORT;');",
        );
        migration_write_fixture(
            $scannerDirectory,
            '002_trigger.sql',
            "CREATE TABLE scanner_source (value TEXT NOT NULL);\n"
            . "CREATE TABLE scanner_log (value TEXT NOT NULL);\n"
            . "CREATE TRIGGER scanner_trigger AFTER INSERT ON scanner_source BEGIN\n"
            . "  INSERT INTO scanner_log (value) VALUES ('COMMIT; END;');\n"
            . "  UPDATE scanner_log SET value = CASE WHEN value = 'none' THEN 'ROLLBACK' ELSE value END;\n"
            . "END;\n"
            . "INSERT INTO scanner_source (value) VALUES ('accepted');",
        );
        $scannerPdo = migration_sqlite(':memory:');
        (new SqlMigrationRunner($scannerPdo, 'fixture_migrations', $scannerDirectory, 'cpe.scanner-positive'))->run();
        migration_assert((int) $scannerPdo->query('SELECT COUNT(*) FROM scanner_log')->fetchColumn() === 1, 'SQLite trigger fixture did not execute.');
        migration_assert((int) $scannerPdo->query('SELECT COUNT(*) FROM fixture_migrations')->fetchColumn() === 2, 'Scanner-positive fixtures were not recorded.');

        $invalidEntries = [
            'invalid-name' => static function (string $directory): void {
                migration_assert(file_put_contents($directory . '/bad-name.sql', 'SELECT 1;') !== false, 'Could not create invalid-name fixture.');
            },
            'uppercase-extension' => static function (string $directory): void {
                migration_assert(file_put_contents($directory . '/001_upper.SQL', 'SELECT 1;') !== false, 'Could not create uppercase-extension fixture.');
            },
            'zero-sequence' => static function (string $directory): void {
                migration_assert(file_put_contents($directory . '/000_zero.sql', 'SELECT 1;') !== false, 'Could not create zero-sequence fixture.');
            },
            'sql-directory' => static function (string $directory): void {
                migration_assert(mkdir($directory . '/001_directory.sql', 0700), 'Could not create SQL directory fixture.');
            },
            'symlink' => static function (string $directory): void {
                migration_assert(file_put_contents($directory . '/target.txt', 'SELECT 1;') !== false, 'Could not create symlink target.');
                migration_assert(symlink($directory . '/target.txt', $directory . '/001_link.sql'), 'Could not create migration symlink fixture.');
            },
            'broken-symlink' => static function (string $directory): void {
                migration_assert(symlink($directory . '/missing.sql', $directory . '/001_broken.sql'), 'Could not create broken migration symlink fixture.');
            },
            'unreadable' => static function (string $directory): void {
                migration_assert(file_put_contents($directory . '/001_unreadable.sql', 'SELECT 1;') !== false, 'Could not create unreadable fixture.');
                migration_assert(chmod($directory . '/001_unreadable.sql', 0000), 'Could not make migration fixture unreadable.');
            },
            'duplicate-sequence' => static function (string $directory): void {
                migration_assert(file_put_contents($directory . '/001_first.sql', 'SELECT 1;') !== false, 'Could not create first duplicate fixture.');
                migration_assert(file_put_contents($directory . '/001_second.sql', 'SELECT 1;') !== false, 'Could not create second duplicate fixture.');
            },
        ];
        foreach ($invalidEntries as $label => $createEntry) {
            $invalidDirectory = $root . '/invalid-entry-' . $label;
            migration_assert(mkdir($invalidDirectory, 0700), 'Could not create invalid-entry fixture directory.');
            $createEntry($invalidDirectory);
            $invalidPdo = migration_sqlite(':memory:');
            migration_assert_throws(
                SqlMigrationRunner::ERROR_DIRECTORY,
                static fn () => (new SqlMigrationRunner(
                    $invalidPdo,
                    'fixture_migrations',
                    $invalidDirectory,
                    'cpe.invalid-' . str_replace('_', '-', $label),
                ))->run(),
                'Invalid migration entry was accepted: ' . $label,
            );
            migration_assert(!migration_has_sqlite_relation($invalidPdo, 'fixture_migrations'), 'Invalid migration entry allowed registry DDL: ' . $label);
            if ($label === 'unreadable') {
                @chmod($invalidDirectory . '/001_unreadable.sql', 0600);
            }
        }

        $productionPath = $root . '/production.sqlite';
        migration_sqlite($productionPath)->query('PRAGMA user_version')->fetchColumn();
        $held = $root . '/production-held';
        $release = $root . '/production-release';
        $holder = migration_spawn_group([[
            'mode' => 'hold_lock', 'sqlite_path' => $productionPath,
            'namespace' => 'cpe.engine-migrations', 'signal' => $held, 'release' => $release,
        ]]);
        migration_start_group($holder);
        migration_wait_file($held, 'Production migration lock holder did not acquire the lock.');
        $migratorReadyA = $root . '/migrator-ready-a';
        $migratorReadyB = $root . '/migrator-ready-b';
        $migrators = migration_spawn_group([
            ['mode' => 'database_migrate', 'sqlite_path' => $productionPath, 'signal' => $migratorReadyA],
            ['mode' => 'database_migrate', 'sqlite_path' => $productionPath, 'signal' => $migratorReadyB],
        ]);
        migration_start_group($migrators);
        migration_wait_file($migratorReadyA, 'First Database migrator did not finish ownership verification.');
        migration_wait_file($migratorReadyB, 'Second Database migrator did not finish ownership verification.');
        usleep(75_000);
        $observer = migration_sqlite($productionPath);
        migration_assert(!migration_has_sqlite_relation($observer, 'migrations'), 'Held migration lock allowed registry DDL before release.');
        migration_assert(!migration_has_sqlite_relation($observer, 'settings'), 'Held migration lock allowed product DDL before release.');
        migration_assert(file_put_contents($release, "release\n") !== false, 'Could not release production migration holder.');
        migration_assert_successes(migration_collect_group($holder), 'Production lock holder');
        migration_assert_successes(migration_collect_group($migrators, 30000), 'Concurrent Database::migrate(false)');
        $expected = count(glob(cpe_path('database/migrations/*.sql')) ?: []);
        migration_assert((int) $observer->query('SELECT COUNT(*) FROM main.migrations')->fetchColumn() === $expected, 'Production migration registry count is wrong.');
        migration_assert((int) $observer->query('SELECT COUNT(DISTINCT migration) FROM main.migrations')->fetchColumn() === $expected, 'Production migration filenames were recorded more than once.');
        Database::useProvider(new SqliteConnectionProvider($productionPath));
        Database::migrate(false);
        $installerPdo = Database::connection();
        DatabaseLock::synchronized(
            migration_sqlite($productionPath),
            'cpe.engine-migrations',
            static function (): void {
            },
            100,
        );
        $installerPdo->beginTransaction();
        $installerPdo->rollBack();
        Database::reset();
        migration_assert((int) $observer->query('SELECT COUNT(*) FROM main.migrations')->fetchColumn() === $expected, 'Third production migration run was not a no-op.');

        $fixtureDirectory = $root . '/fixtures';
        migration_assert(mkdir($fixtureDirectory, 0700), 'Could not create fixture migration directory.');
        migration_write_fixture($fixtureDirectory, '001_create_fixture.sql', 'CREATE TABLE fixture_product (id INTEGER PRIMARY KEY);');

        $unknownFileDirectory = $root . '/unknown-file-registry';
        migration_assert(mkdir($unknownFileDirectory, 0700), 'Could not create file-backed unknown-registry fixture directory.');
        migration_write_fixture($unknownFileDirectory, '001_known.sql', 'CREATE TABLE unknown_file_known (id INTEGER PRIMARY KEY);');
        $unknownFilePdo = migration_sqlite($root . '/unknown-file-registry.sqlite');
        $unknownFileRunner = new SqlMigrationRunner(
            $unknownFilePdo,
            'unknown_file_migrations',
            $unknownFileDirectory,
            'cpe.unknown-file-migrations',
        );
        $unknownFileRunner->run();
        migration_write_fixture($unknownFileDirectory, '002_pending.sql', 'CREATE TABLE unknown_file_pending (id INTEGER PRIMARY KEY);');
        $unknownFilePdo->exec(
            "INSERT INTO unknown_file_migrations (migration, applied_at) VALUES ('999_future.sql', '2030-01-01 00:00:00')",
        );
        $unknownFileCallbackRan = false;
        $unknownFileFailure = migration_assert_throws(
            SqlMigrationRunner::ERROR_REGISTRY,
            static function () use ($unknownFileRunner, &$unknownFileCallbackRan): void {
                $unknownFileRunner->run(static function () use (&$unknownFileCallbackRan): void {
                    $unknownFileCallbackRan = true;
                });
            },
            'File-backed SQLite accepted a migration registry row absent from the release',
        );
        migration_assert(!str_contains($unknownFileFailure->getMessage(), '999_future.sql'), 'Registry failure leaked the unknown file-backed SQLite identifier.');
        migration_assert(!migration_has_sqlite_relation($unknownFilePdo, 'unknown_file_pending'), 'Unknown file-backed SQLite registry row allowed pending product DDL.');
        migration_assert(!$unknownFileCallbackRan, 'Unknown file-backed SQLite registry row allowed the post-migration callback.');
        migration_assert((int) $unknownFilePdo->query('SELECT COUNT(*) FROM unknown_file_migrations')->fetchColumn() === 2, 'File-backed SQLite registry rejection rewrote migration history.');

        $unknownMemoryDirectory = $root . '/unknown-memory-registry';
        migration_assert(mkdir($unknownMemoryDirectory, 0700), 'Could not create fileless unknown-registry fixture directory.');
        migration_write_fixture($unknownMemoryDirectory, '001_known.sql', 'CREATE TABLE unknown_memory_known (id INTEGER PRIMARY KEY);');
        $unknownMemoryPdo = migration_sqlite(':memory:');
        $unknownMemoryRunner = new SqlMigrationRunner(
            $unknownMemoryPdo,
            'unknown_memory_migrations',
            $unknownMemoryDirectory,
            'cpe.unknown-memory-migrations',
        );
        $unknownMemoryRunner->run();
        migration_write_fixture($unknownMemoryDirectory, '002_pending.sql', 'CREATE TABLE unknown_memory_pending (id INTEGER PRIMARY KEY);');
        $unknownMemoryPdo->exec(
            "INSERT INTO unknown_memory_migrations (migration, applied_at) VALUES ('999_future.sql', '2030-01-01 00:00:00')",
        );
        $unknownMemoryCallbackRan = false;
        $unknownMemoryFailure = migration_assert_throws(
            SqlMigrationRunner::ERROR_REGISTRY,
            static function () use ($unknownMemoryRunner, &$unknownMemoryCallbackRan): void {
                $unknownMemoryRunner->run(static function () use (&$unknownMemoryCallbackRan): void {
                    $unknownMemoryCallbackRan = true;
                });
            },
            'Fileless SQLite accepted a migration registry row absent from the release',
        );
        migration_assert(!str_contains($unknownMemoryFailure->getMessage(), '999_future.sql'), 'Registry failure leaked the unknown fileless SQLite identifier.');
        migration_assert(!migration_has_sqlite_relation($unknownMemoryPdo, 'unknown_memory_pending'), 'Unknown fileless SQLite registry row allowed pending product DDL.');
        migration_assert(!$unknownMemoryCallbackRan, 'Unknown fileless SQLite registry row allowed the post-migration callback.');
        migration_assert(!$unknownMemoryPdo->inTransaction(), 'Unknown fileless SQLite registry rejection left its transaction open.');
        migration_assert((int) $unknownMemoryPdo->query('SELECT COUNT(*) FROM unknown_memory_migrations')->fetchColumn() === 2, 'Fileless SQLite registry rejection rewrote migration history.');

        $activeTransaction = migration_sqlite(':memory:');
        $activeRunner = new SqlMigrationRunner($activeTransaction, 'fixture_migrations', $fixtureDirectory, 'cpe.active-migrations');
        $activeTransaction->beginTransaction();
        migration_assert_throws(
            DatabaseLock::ERROR_ACTIVE_TRANSACTION,
            static fn () => $activeRunner->run(),
            'Migration runner accepted an active transaction',
        );
        $activeTransaction->rollBack();

        $malformedRegistry = migration_sqlite($root . '/malformed-registry.sqlite');
        $malformedRegistry->exec(
            'CREATE TABLE main.fixture_migrations ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, '
            . 'applied_at TEXT NOT NULL, unexpected TEXT)',
        );
        migration_assert_throws(
            SqlMigrationRunner::ERROR_REGISTRY,
            static fn () => (new SqlMigrationRunner(
                $malformedRegistry,
                'fixture_migrations',
                $fixtureDirectory,
                'cpe.malformed-migrations',
            ))->run(),
            'Malformed migration registry was accepted',
        );
        migration_assert(!migration_has_sqlite_relation($malformedRegistry, 'fixture_product'), 'Malformed registry validation allowed product DDL.');

        $timeoutPath = $root . '/timeout.sqlite';
        migration_sqlite($timeoutPath)->query('PRAGMA user_version')->fetchColumn();
        $timeoutHeld = $root . '/timeout-held';
        $timeoutRelease = $root . '/timeout-release';
        $timeoutHolder = migration_spawn_group([[
            'mode' => 'hold_lock', 'sqlite_path' => $timeoutPath,
            'namespace' => 'cpe.test-migrations', 'signal' => $timeoutHeld, 'release' => $timeoutRelease,
        ]]);
        migration_start_group($timeoutHolder);
        migration_wait_file($timeoutHeld, 'Short-timeout lock holder did not acquire the lock.');
        $contender = migration_spawn_group([[
            'mode' => 'fixture_migrate', 'sqlite_path' => $timeoutPath, 'directory' => $fixtureDirectory,
            'registry' => 'fixture_migrations', 'namespace' => 'cpe.test-migrations', 'timeout_ms' => 60,
        ]]);
        migration_start_group($contender);
        $timeoutResult = migration_collect_group($contender);
        migration_assert(count($timeoutResult) === 1 && $timeoutResult[0]['code'] !== 0, 'Short-timeout contender unexpectedly succeeded.');
        migration_assert(str_contains($timeoutResult[0]['stderr'], DatabaseLock::ERROR_TIMEOUT), 'Short-timeout contender lacked the fixed timeout identifier.');
        $timeoutObserver = migration_sqlite($timeoutPath);
        migration_assert(!migration_has_sqlite_relation($timeoutObserver, 'fixture_migrations'), 'Timed-out contender created registry DDL.');
        migration_assert(!migration_has_sqlite_relation($timeoutObserver, 'fixture_product'), 'Timed-out contender created product DDL.');
        migration_assert(file_put_contents($timeoutRelease, "release\n") !== false, 'Could not release timeout holder.');
        migration_assert_successes(migration_collect_group($timeoutHolder), 'Short-timeout holder');

        $namespacePath = $root . '/namespace.sqlite';
        migration_sqlite($namespacePath)->query('PRAGMA user_version')->fetchColumn();
        $ownershipHeld = $root . '/ownership-held';
        $ownershipRelease = $root . '/ownership-release';
        $ownershipHolder = migration_spawn_group([[
            'mode' => 'hold_lock', 'sqlite_path' => $namespacePath,
            'namespace' => 'cpe.database-ownership', 'signal' => $ownershipHeld, 'release' => $ownershipRelease,
        ]]);
        migration_start_group($ownershipHolder);
        migration_wait_file($ownershipHeld, 'Ownership namespace holder did not acquire the lock.');
        $differentNamespace = migration_spawn_group([[
            'mode' => 'fixture_migrate', 'sqlite_path' => $namespacePath, 'directory' => $fixtureDirectory,
            'registry' => 'fixture_migrations', 'namespace' => 'cpe.test-migrations',
        ]]);
        migration_start_group($differentNamespace);
        migration_assert_successes(migration_collect_group($differentNamespace), 'Distinct migration namespace');
        migration_assert(file_put_contents($ownershipRelease, "release\n") !== false, 'Could not release ownership namespace holder.');
        migration_assert_successes(migration_collect_group($ownershipHolder), 'Ownership namespace holder');

        $aliasPath = $root . '/alias.sqlite';
        migration_sqlite($aliasPath)->query('PRAGMA user_version')->fetchColumn();
        $symlinkPath = $root . '/alias-link.sqlite';
        migration_assert(symlink($aliasPath, $symlinkPath), 'Could not create migration SQLite symlink.');
        $aliases = migration_spawn_group([
            [
                'mode' => 'fixture_migrate', 'sqlite_path' => 'alias.sqlite', 'cwd' => $root,
                'directory' => $fixtureDirectory, 'registry' => 'fixture_migrations', 'namespace' => 'cpe.alias-migrations',
            ],
            [
                'mode' => 'fixture_migrate', 'sqlite_path' => $symlinkPath,
                'directory' => $fixtureDirectory, 'registry' => 'fixture_migrations', 'namespace' => 'cpe.alias-migrations',
            ],
        ]);
        migration_start_group($aliases);
        migration_assert_successes(migration_collect_group($aliases), 'Relative/real/symlink migration aliases');
        $aliasPdo = migration_sqlite($aliasPath);
        (new SqlMigrationRunner($aliasPdo, 'fixture_migrations', $fixtureDirectory, 'cpe.alias-migrations'))->run();
        migration_assert((int) $aliasPdo->query('SELECT COUNT(*) FROM main.fixture_migrations')->fetchColumn() === 1, 'Alias migration filename was not recorded exactly once.');

        $callbackPath = $root . '/callback.sqlite';
        $callbackPdo = migration_sqlite($callbackPath);
        $callbackRunner = new SqlMigrationRunner($callbackPdo, 'fixture_migrations', $fixtureDirectory, 'cpe.callback-migrations');
        migration_assert_throws(
            'synthetic callback failure',
            static fn () => $callbackRunner->run(static function (): void {
                throw new RuntimeException('synthetic callback failure');
            }),
            'Callback failure was not propagated',
        );
        migration_assert(migration_has_sqlite_relation($callbackPdo, 'fixture_product'), 'Callback failure rolled back committed migration schema.');
        migration_assert((int) $callbackPdo->query('SELECT COUNT(*) FROM main.fixture_migrations')->fetchColumn() === 1, 'Callback failure lost the committed registry row.');
        $callbackContender = migration_sqlite($callbackPath);
        $callbackRunner->run(static function (PDO $pdo) use ($callbackContender): void {
            migration_assert_throws(
                DatabaseLock::ERROR_TIMEOUT,
                static fn () => DatabaseLock::synchronized(
                    $callbackContender,
                    'cpe.callback-migrations',
                    static function (): void {
                    },
                    50,
                ),
                'Post-migration callback did not remain under the migration lock',
            );
            $pdo->exec('CREATE TABLE callback_retry (id INTEGER PRIMARY KEY)');
        });
        migration_assert(migration_has_sqlite_relation($callbackPdo, 'callback_retry'), 'Callback did not retry after lock release.');
        migration_assert_throws(
            SqlMigrationRunner::ERROR_CALLBACK_TRANSACTION,
            static fn () => $callbackRunner->run(static function (PDO $pdo): void {
                $pdo->beginTransaction();
            }),
            'Callback-owned open transaction was accepted',
        );
        migration_assert(!$callbackPdo->inTransaction(), 'Callback transaction rejection did not roll back the transaction.');

        $callbackMutationDirectory = $root . '/callback-registry-mutations';
        migration_assert(mkdir($callbackMutationDirectory, 0700), 'Could not create callback registry-mutation fixture directory.');
        migration_write_fixture($callbackMutationDirectory, '001_callback.sql', 'SELECT 1;');

        $fileCallbackInsertPdo = migration_sqlite($root . '/callback-insert.sqlite');
        $fileCallbackInsertRunner = new SqlMigrationRunner(
            $fileCallbackInsertPdo,
            'callback_migrations',
            $callbackMutationDirectory,
            'cpe.callback-insert-migrations',
        );
        $fileCallbackInsertRunner->run();
        migration_assert_throws(
            SqlMigrationRunner::ERROR_REGISTRY,
            static fn () => $fileCallbackInsertRunner->run(static function (PDO $pdo): void {
                $pdo->exec(
                    "INSERT INTO callback_migrations (migration, applied_at) VALUES ('999_callback.sql', '2030-01-01 00:00:00')",
                );
            }),
            'File-backed SQLite callback inserted an unknown registry row without failing the run',
        );
        migration_assert((int) $fileCallbackInsertPdo->query("SELECT COUNT(*) FROM callback_migrations WHERE migration = '999_callback.sql'")->fetchColumn() === 1, 'File-backed SQLite callback insertion was misleadingly reported as rolled back.');

        $fileCallbackDeletePdo = migration_sqlite($root . '/callback-delete.sqlite');
        $fileCallbackDeleteRunner = new SqlMigrationRunner(
            $fileCallbackDeletePdo,
            'callback_migrations',
            $callbackMutationDirectory,
            'cpe.callback-delete-migrations',
        );
        $fileCallbackDeleteRunner->run();
        migration_assert_throws(
            SqlMigrationRunner::ERROR_REGISTRY,
            static fn () => $fileCallbackDeleteRunner->run(static function (PDO $pdo): void {
                $pdo->exec("DELETE FROM callback_migrations WHERE migration = '001_callback.sql'");
            }),
            'File-backed SQLite callback deleted a discovered registry row without failing the run',
        );
        migration_assert((int) $fileCallbackDeletePdo->query('SELECT COUNT(*) FROM callback_migrations')->fetchColumn() === 0, 'File-backed SQLite callback deletion was misleadingly reported as rolled back.');

        $memoryCallbackInsertPdo = migration_sqlite(':memory:');
        $memoryCallbackInsertRunner = new SqlMigrationRunner(
            $memoryCallbackInsertPdo,
            'callback_migrations',
            $callbackMutationDirectory,
            'cpe.memory-callback-insert',
        );
        $memoryCallbackInsertRunner->run();
        migration_assert_throws(
            SqlMigrationRunner::ERROR_REGISTRY,
            static fn () => $memoryCallbackInsertRunner->run(static function (PDO $pdo): void {
                $pdo->exec(
                    "INSERT INTO callback_migrations (migration, applied_at) VALUES ('999_callback.sql', '2030-01-01 00:00:00')",
                );
            }),
            'Fileless SQLite callback inserted an unknown registry row without failing the run',
        );
        migration_assert(!$memoryCallbackInsertPdo->inTransaction(), 'Fileless SQLite callback insertion rejection left its outer transaction open.');
        migration_assert((int) $memoryCallbackInsertPdo->query('SELECT COUNT(*) FROM callback_migrations')->fetchColumn() === 1, 'Fileless SQLite callback insertion was not rolled back with the outer transaction.');
        migration_assert((int) $memoryCallbackInsertPdo->query("SELECT COUNT(*) FROM callback_migrations WHERE migration = '999_callback.sql'")->fetchColumn() === 0, 'Fileless SQLite callback unknown row survived outer rollback.');

        $memoryCallbackDeletePdo = migration_sqlite(':memory:');
        $memoryCallbackDeleteRunner = new SqlMigrationRunner(
            $memoryCallbackDeletePdo,
            'callback_migrations',
            $callbackMutationDirectory,
            'cpe.memory-callback-delete',
        );
        $memoryCallbackDeleteRunner->run();
        migration_assert_throws(
            SqlMigrationRunner::ERROR_REGISTRY,
            static fn () => $memoryCallbackDeleteRunner->run(static function (PDO $pdo): void {
                $pdo->exec("DELETE FROM callback_migrations WHERE migration = '001_callback.sql'");
            }),
            'Fileless SQLite callback deleted a discovered registry row without failing the run',
        );
        migration_assert(!$memoryCallbackDeletePdo->inTransaction(), 'Fileless SQLite callback deletion rejection left its outer transaction open.');
        migration_assert((int) $memoryCallbackDeletePdo->query("SELECT COUNT(*) FROM callback_migrations WHERE migration = '001_callback.sql'")->fetchColumn() === 1, 'Fileless SQLite callback deletion was not rolled back with the outer transaction.');

        $cleanupDirectory = $root . '/cleanup-failure';
        migration_assert(mkdir($cleanupDirectory, 0700), 'Could not create cleanup-failure fixture directory.');
        migration_write_fixture($cleanupDirectory, '001_invalid.sql', 'CREATE TABLE cleanup_partial (id INTEGER); THIS IS INVALID SQL;');
        $cleanupPdo = new MigrationCleanupFailurePdo('sqlite:' . $root . '/cleanup-failure.sqlite');
        $cleanupPdo->failRollback = true;
        $cleanupFailure = migration_assert_throws(
            SqlMigrationRunner::ERROR_CLEANUP,
            static fn () => (new SqlMigrationRunner(
                $cleanupPdo,
                'fixture_migrations',
                $cleanupDirectory,
                'cpe.cleanup-failure',
            ))->run(),
            'Migration rollback cleanup failure did not fail closed',
        );
        migration_assert(str_contains($cleanupFailure->getMessage(), 'discard this database connection'), 'Cleanup failure omitted discard-connection guidance.');
        migration_assert($cleanupFailure->getPrevious() instanceof Throwable, 'Cleanup failure did not retain the primary migration error as its cause.');
        migration_assert(str_contains($cleanupFailure->getPrevious()->getMessage(), SqlMigrationRunner::ERROR_MIGRATION), 'Cleanup failure replaced the primary migration error.');
        $cleanupPdo->failRollback = false;
        if ($cleanupPdo->inTransaction()) {
            $cleanupPdo->rollBack();
        }

        $memory = migration_sqlite(':memory:');
        $memoryRunner = new SqlMigrationRunner($memory, 'fixture_migrations', $fixtureDirectory, 'cpe.memory-migrations');
        $memoryRunner->run(static function (PDO $pdo): void {
            migration_assert($pdo->inTransaction(), 'Fileless SQLite callback did not remain inside the outer transaction.');
            $pdo->exec('CREATE TABLE memory_callback (id INTEGER PRIMARY KEY)');
        });
        migration_assert(migration_has_sqlite_relation($memory, 'memory_callback'), 'Fileless SQLite callback was not committed.');

        $memoryFailure = migration_sqlite(':memory:');
        $memoryFailureRunner = new SqlMigrationRunner($memoryFailure, 'fixture_migrations', $fixtureDirectory, 'cpe.memory-failure');
        migration_assert_throws(
            'memory callback failure',
            static fn () => $memoryFailureRunner->run(static function (PDO $pdo): void {
                $pdo->exec('CREATE TABLE memory_partial_callback (id INTEGER PRIMARY KEY)');
                throw new RuntimeException('memory callback failure');
            }),
            'Fileless SQLite callback failure was not propagated',
        );
        migration_assert(migration_has_sqlite_relation($memoryFailure, 'fixture_product'), 'Fileless callback failure lost committed migration schema.');
        migration_assert(!migration_has_sqlite_relation($memoryFailure, 'memory_partial_callback'), 'Fileless callback savepoint did not roll back partial callback work.');
        $memoryFailureRunner->run(static function (PDO $pdo): void {
            $pdo->exec('CREATE TABLE memory_callback_retry (id INTEGER PRIMARY KEY)');
        });

        $savepointCleanup = new MigrationCleanupFailurePdo('sqlite::memory:');
        $savepointRunner = new SqlMigrationRunner(
            $savepointCleanup,
            'fixture_migrations',
            $fixtureDirectory,
            'cpe.savepoint-cleanup',
        );
        $savepointFailure = migration_assert_throws(
            SqlMigrationRunner::ERROR_CLEANUP,
            static fn () => $savepointRunner->run(static function (PDO $pdo) use ($savepointCleanup): void {
                $pdo->exec('CREATE TABLE savepoint_partial (id INTEGER PRIMARY KEY)');
                $savepointCleanup->failSavepointRollback = true;
                throw new RuntimeException('primary savepoint callback failure');
            }),
            'Savepoint rollback cleanup failure did not fail closed',
        );
        migration_assert(str_contains($savepointFailure->getMessage(), 'discard this database connection'), 'Savepoint cleanup failure omitted discard-connection guidance.');
        migration_assert($savepointFailure->getPrevious()?->getMessage() === 'primary savepoint callback failure', 'Savepoint cleanup failure replaced its primary callback error.');
        migration_assert(!$savepointCleanup->inTransaction(), 'Savepoint cleanup failure left the outer transaction active.');

        $crashDirectory = $root . '/crash-fixtures';
        migration_assert(mkdir($crashDirectory, 0700), 'Could not create crash fixture directory.');
        migration_write_fixture(
            $crashDirectory,
            '001_crash.sql',
            'CREATE TABLE crash_product (id INTEGER PRIMARY KEY); SELECT cpe_test_pause();',
        );
        $crashPath = $root . '/crash.sqlite';
        $crashSignal = $root . '/crash-paused';
        $crashRelease = $root . '/crash-release';
        $crash = migration_spawn_group([[
            'mode' => 'crash_migrate', 'sqlite_path' => $crashPath, 'directory' => $crashDirectory,
            'registry' => 'fixture_migrations', 'namespace' => 'cpe.crash-migrations',
            'signal' => $crashSignal, 'release' => $crashRelease,
        ]]);
        migration_start_group($crash);
        migration_wait_file($crashSignal, 'Crash migration did not reach its transactional pause.');
        migration_terminate_workers($crash['workers']);
        $crashResult = migration_collect_group($crash);
        migration_assert($crashResult[0]['code'] !== 0, 'Crash migration process unexpectedly completed.');
        $crashPdo = migration_sqlite($crashPath);
        migration_assert(!migration_has_sqlite_relation($crashPdo, 'crash_product'), 'Crash left transactional product DDL behind.');
        migration_assert((int) $crashPdo->query('SELECT COUNT(*) FROM main.fixture_migrations')->fetchColumn() === 0, 'Crash left a migration registry row behind.');
        if (method_exists($crashPdo, 'createFunction')) {
            $crashPdo->createFunction('cpe_test_pause', static fn (): int => 1);
        } else {
            @$crashPdo->sqliteCreateFunction('cpe_test_pause', static fn (): int => 1);
        }
        (new SqlMigrationRunner($crashPdo, 'fixture_migrations', $crashDirectory, 'cpe.crash-migrations'))->run();
        migration_assert(migration_has_sqlite_relation($crashPdo, 'crash_product'), 'Crash recovery did not resume the migration.');
        migration_assert((int) $crashPdo->query('SELECT COUNT(*) FROM main.fixture_migrations')->fetchColumn() === 1, 'Crash recovery registry row is missing.');
    } finally {
        Database::reset();
        migration_remove_tree($root);
    }
}

function migration_postgres_contract(string $url): void
{
    $schema = 'cpe_migration_' . bin2hex(random_bytes(6));
    migration_assert(preg_match('/^[a-z][a-z0-9_]{0,62}$/', $schema) === 1, 'Generated PostgreSQL migration schema is invalid.');
    $admin = PostgresConnectionProvider::fromUrl($url, 'migration test database URL')->connection();
    $root = sys_get_temp_dir() . '/cpe-pg-migration-' . bin2hex(random_bytes(6));
    migration_assert(mkdir($root, 0700, true), 'Could not create PostgreSQL fixture directory.');
    $fixtures = $root . '/fixtures';
    migration_assert(mkdir($fixtures, 0700), 'Could not create PostgreSQL migrations.');
    migration_write_fixture($fixtures, '001_create_fixture.sql', 'CREATE TABLE pg_fixture_product (id BIGINT PRIMARY KEY);');
    $admin->exec('CREATE SCHEMA "' . $schema . '"');
    try {
        $productionHeld = $root . '/production-held';
        $productionRelease = $root . '/production-release';
        $productionHolder = migration_spawn_group([[
            'mode' => 'hold_lock', 'database_url' => $url, 'schema' => $schema,
            'namespace' => 'cpe.engine-migrations', 'signal' => $productionHeld, 'release' => $productionRelease,
        ]]);
        migration_start_group($productionHolder);
        migration_wait_file($productionHeld, 'PostgreSQL production migration holder did not acquire the lock.');
        $productionReadyA = $root . '/production-ready-a';
        $productionReadyB = $root . '/production-ready-b';
        $productionMigrators = migration_spawn_group([
            ['mode' => 'database_migrate', 'database_url' => $url, 'schema' => $schema, 'signal' => $productionReadyA],
            ['mode' => 'database_migrate', 'database_url' => $url, 'schema' => $schema, 'signal' => $productionReadyB],
        ]);
        migration_start_group($productionMigrators);
        migration_wait_file($productionReadyA, 'First PostgreSQL Database migrator did not verify ownership.');
        migration_wait_file($productionReadyB, 'Second PostgreSQL Database migrator did not verify ownership.');
        usleep(75_000);
        migration_assert((int) $admin->query("SELECT COUNT(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=" . $admin->quote($schema) . " AND c.relname IN ('migrations','settings')")->fetchColumn() === 0, 'PostgreSQL held Engine lock allowed production registry/product DDL.');
        migration_assert(file_put_contents($productionRelease, "release\n") !== false, 'Could not release PostgreSQL production migration holder.');
        migration_assert_successes(migration_collect_group($productionHolder), 'PostgreSQL production holder');
        migration_assert_successes(migration_collect_group($productionMigrators, 30000), 'PostgreSQL concurrent Database::migrate(false)');
        $productionPdo = migration_postgres($url, $schema);
        $productionCount = count(glob(cpe_path('database/migrations/pgsql/*.sql')) ?: []);
        migration_assert((int) $productionPdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === $productionCount, 'PostgreSQL production migration count is wrong.');
        Database::useProvider(PostgresConnectionProvider::fromUrl($url, 'migration test database URL'));
        Database::connection()->exec('SET search_path TO "' . $schema . '"');
        Database::migrate(false);
        DatabaseLock::synchronized(
            migration_postgres($url, $schema),
            'cpe.engine-migrations',
            static function (): void {
            },
            100,
        );
        Database::connection()->beginTransaction();
        Database::connection()->rollBack();
        Database::reset();

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $held = $root . '/held';
        $release = $root . '/release';
        $holder = migration_spawn_group([[
            'mode' => 'hold_lock', 'database_url' => $url, 'schema' => $schema,
            'namespace' => 'cpe.pg-migrations', 'signal' => $held, 'release' => $release,
        ]]);
        migration_start_group($holder);
        migration_wait_file($held, 'PostgreSQL migration holder did not acquire the lock.');
        $timeout = migration_spawn_group([[
            'mode' => 'fixture_migrate', 'database_url' => $url, 'schema' => $schema,
            'directory' => $fixtures, 'registry' => 'fixture_migrations',
            'namespace' => 'cpe.pg-migrations', 'timeout_ms' => 60,
        ]]);
        migration_start_group($timeout);
        $timeoutResult = migration_collect_group($timeout);
        migration_assert($timeoutResult[0]['code'] !== 0 && str_contains($timeoutResult[0]['stderr'], DatabaseLock::ERROR_TIMEOUT), 'PostgreSQL short-timeout contender did not fail closed.');
        $migrators = migration_spawn_group([
            ['mode' => 'fixture_migrate', 'database_url' => $url, 'schema' => $schema, 'directory' => $fixtures, 'registry' => 'fixture_migrations', 'namespace' => 'cpe.pg-migrations'],
            ['mode' => 'fixture_migrate', 'database_url' => $url, 'schema' => $schema, 'directory' => $fixtures, 'registry' => 'fixture_migrations', 'namespace' => 'cpe.pg-migrations'],
        ]);
        migration_start_group($migrators);
        usleep(150_000);
        migration_assert((int) $admin->query("SELECT COUNT(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=" . $admin->quote($schema) . " AND c.relname IN ('fixture_migrations','pg_fixture_product')")->fetchColumn() === 0, 'PostgreSQL held lock allowed registry/product DDL.');
        migration_assert(file_put_contents($release, "release\n") !== false, 'Could not release PostgreSQL migration holder.');
        migration_assert_successes(migration_collect_group($holder), 'PostgreSQL migration holder');
        migration_assert_successes(migration_collect_group($migrators), 'PostgreSQL concurrent migrators');
        $pdo = migration_postgres($url, $schema);
        migration_assert((int) $pdo->query('SELECT COUNT(*) FROM fixture_migrations')->fetchColumn() === 1, 'PostgreSQL migration was not recorded exactly once.');
        (new SqlMigrationRunner($pdo, 'fixture_migrations', $fixtures, 'cpe.pg-migrations'))->run();

        $unknownDirectory = $root . '/unknown-registry';
        migration_assert(mkdir($unknownDirectory, 0700), 'Could not create PostgreSQL unknown-registry fixture directory.');
        migration_write_fixture($unknownDirectory, '001_known.sql', 'CREATE TABLE pg_unknown_known (id BIGINT PRIMARY KEY);');
        $unknownRunner = new SqlMigrationRunner(
            $pdo,
            'unknown_migrations',
            $unknownDirectory,
            'cpe.pg-unknown-migrations',
        );
        $unknownRunner->run();
        migration_write_fixture($unknownDirectory, '002_pending.sql', 'CREATE TABLE pg_unknown_pending (id BIGINT PRIMARY KEY);');
        $pdo->exec(
            "INSERT INTO unknown_migrations (migration, applied_at) VALUES ('999_future.sql', '2030-01-01 00:00:00')",
        );
        $unknownCallbackRan = false;
        $unknownFailure = migration_assert_throws(
            SqlMigrationRunner::ERROR_REGISTRY,
            static function () use ($unknownRunner, &$unknownCallbackRan): void {
                $unknownRunner->run(static function () use (&$unknownCallbackRan): void {
                    $unknownCallbackRan = true;
                });
            },
            'PostgreSQL accepted a migration registry row absent from the release',
        );
        migration_assert(!str_contains($unknownFailure->getMessage(), '999_future.sql'), 'Registry failure leaked the unknown PostgreSQL identifier.');
        migration_assert(!migration_has_postgres_relation($pdo, $schema, 'pg_unknown_pending'), 'Unknown PostgreSQL registry row allowed pending product DDL.');
        migration_assert(!$unknownCallbackRan, 'Unknown PostgreSQL registry row allowed the post-migration callback.');
        migration_assert((int) $pdo->query('SELECT COUNT(*) FROM unknown_migrations')->fetchColumn() === 2, 'PostgreSQL registry rejection rewrote migration history.');

        $postgresControls = [
            'BEGIN',
            'COMMIT TRANSACTION',
            'END WORK',
            'ABORT',
            'ROLLBACK TRANSACTION',
            'ROLLBACK TO SAVEPOINT malicious',
            'SAVEPOINT malicious',
            'RELEASE SAVEPOINT malicious',
            'START TRANSACTION',
            "PREPARE TRANSACTION 'malicious'",
        ];
        foreach ($postgresControls as $index => $control) {
            $controlDirectory = $root . '/pg-control-' . $index;
            migration_assert(mkdir($controlDirectory, 0700), 'Could not create PostgreSQL control fixture directory.');
            $product = 'pg_forbidden_control_' . $index;
            $registry = 'control_migrations_' . $index;
            migration_write_fixture(
                $controlDirectory,
                '001_control.sql',
                'CREATE TABLE ' . $product . ' (id BIGINT); ' . $control . ';',
            );
            $controlFailure = migration_assert_throws(
                SqlMigrationRunner::ERROR_MIGRATION,
                static fn () => (new SqlMigrationRunner(
                    $pdo,
                    $registry,
                    $controlDirectory,
                    'cpe.pg-control-' . $index,
                ))->run(),
                'PostgreSQL top-level transaction control was accepted: ' . $control,
            );
            migration_assert(
                migration_exception_chain_contains($controlFailure, 'contains top-level transaction control SQL'),
                'PostgreSQL control fixture failed after execution instead of during lexical preflight: ' . $control,
            );
            $productQuery = $pdo->prepare(
                'SELECT COUNT(*) FROM pg_catalog.pg_class c '
                . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
                . 'WHERE n.nspname = ? AND c.relname = ?',
            );
            $productQuery->execute([$schema, $product]);
            migration_assert((int) $productQuery->fetchColumn() === 0, 'Rejected PostgreSQL control left product DDL behind.');
            migration_assert((int) $pdo->query('SELECT COUNT(*) FROM "' . $registry . '"')->fetchColumn() === 0, 'Rejected PostgreSQL control left a registry row behind.');
        }

        foreach (['COMMIT', 'END TRANSACTION'] as $index => $control) {
            $triggerBypassDirectory = $root . '/pg-trigger-bypass-' . $index;
            migration_assert(mkdir($triggerBypassDirectory, 0700), 'Could not create PostgreSQL trigger-bypass directory.');
            $product = 'pg_trigger_product_' . $index;
            $trigger = 'pg_trigger_contract_' . $index;
            $function = 'pg_trigger_function_' . $index;
            $registry = 'trigger_migrations_' . $index;
            migration_write_fixture(
                $triggerBypassDirectory,
                '001_trigger_bypass.sql',
                'CREATE FUNCTION ' . $function . "() RETURNS trigger LANGUAGE plpgsql AS \$trigger\$\n"
                . "BEGIN\n  RETURN NEW;\nEND;\n\$trigger\$;\n"
                . 'CREATE TABLE ' . $product . " (id BIGINT PRIMARY KEY);\n"
                . 'CREATE TRIGGER ' . $trigger . ' BEFORE INSERT ON ' . $product
                . ' FOR EACH ROW EXECUTE FUNCTION ' . $function . "();\n"
                . $control . ';',
            );
            $triggerBypassFailure = migration_assert_throws(
                SqlMigrationRunner::ERROR_MIGRATION,
                static fn () => (new SqlMigrationRunner(
                    $pdo,
                    $registry,
                    $triggerBypassDirectory,
                    'cpe.pg-trigger-bypass-' . $index,
                ))->run(),
                'PostgreSQL CREATE TRIGGER transaction-control bypass was accepted: ' . $control,
            );
            migration_assert(
                migration_exception_chain_contains($triggerBypassFailure, 'contains top-level transaction control SQL'),
                'PostgreSQL trigger bypass was not rejected during lexical preflight: ' . $control,
            );
            $productQuery = $pdo->prepare(
                'SELECT COUNT(*) FROM pg_catalog.pg_class c '
                . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
                . 'WHERE n.nspname = ? AND c.relname = ?',
            );
            $productQuery->execute([$schema, $product]);
            migration_assert((int) $productQuery->fetchColumn() === 0, 'Rejected PostgreSQL trigger bypass left product DDL behind.');
            $triggerQuery = $pdo->prepare(
                'SELECT COUNT(*) FROM pg_catalog.pg_trigger t '
                . 'JOIN pg_catalog.pg_class c ON c.oid = t.tgrelid '
                . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
                . 'WHERE n.nspname = ? AND t.tgname = ?',
            );
            $triggerQuery->execute([$schema, $trigger]);
            migration_assert((int) $triggerQuery->fetchColumn() === 0, 'Rejected PostgreSQL trigger bypass left trigger DDL behind.');
            migration_assert((int) $pdo->query('SELECT COUNT(*) FROM "' . $registry . '"')->fetchColumn() === 0, 'Rejected PostgreSQL trigger bypass left a registry row behind.');
        }

        $pgScannerDirectory = $root . '/pg-scanner-positive';
        migration_assert(mkdir($pgScannerDirectory, 0700), 'Could not create PostgreSQL scanner-positive directory.');
        migration_write_fixture(
            $pgScannerDirectory,
            '001_scanner.sql',
            "-- START TRANSACTION; PREPARE TRANSACTION 'ignored'\n"
            . "/* COMMIT; ABORT; END TRANSACTION */\n"
            . "/* outer /* COMMIT; */ END TRANSACTION; */\n"
            . "CREATE TABLE pg_scanner_product (value TEXT NOT NULL);\n"
            . "INSERT INTO pg_scanner_product (value) VALUES ('BEGIN; ROLLBACK;');\n"
            . "DO \$body\$\nBEGIN\n  PERFORM 'COMMIT; END WORK; SAVEPOINT ignored';\nEND;\n\$body\$;",
        );
        (new SqlMigrationRunner($pdo, 'scanner_migrations', $pgScannerDirectory, 'cpe.pg-scanner'))->run();
        migration_assert((int) $pdo->query('SELECT COUNT(*) FROM pg_scanner_product')->fetchColumn() === 1, 'PostgreSQL dollar-quote scanner fixture did not execute.');
        migration_assert((int) $pdo->query('SELECT COUNT(*) FROM scanner_migrations')->fetchColumn() === 1, 'PostgreSQL scanner-positive fixture was not recorded.');

        migration_write_fixture($fixtures, '002_namespace.sql', 'CREATE TABLE pg_namespace_product (id BIGINT PRIMARY KEY);');
        $ownershipHeld = $root . '/ownership-held';
        $ownershipRelease = $root . '/ownership-release';
        $ownershipHolder = migration_spawn_group([[
            'mode' => 'hold_lock', 'database_url' => $url, 'schema' => $schema,
            'namespace' => 'cpe.database-ownership', 'signal' => $ownershipHeld, 'release' => $ownershipRelease,
        ]]);
        migration_start_group($ownershipHolder);
        migration_wait_file($ownershipHeld, 'PostgreSQL ownership namespace holder did not acquire the lock.');
        $migrationNamespace = migration_spawn_group([[
            'mode' => 'fixture_migrate', 'database_url' => $url, 'schema' => $schema,
            'directory' => $fixtures, 'registry' => 'fixture_migrations', 'namespace' => 'cpe.pg-migrations',
        ]]);
        migration_start_group($migrationNamespace);
        migration_assert_successes(migration_collect_group($migrationNamespace), 'PostgreSQL distinct migration namespace');
        migration_assert(file_put_contents($ownershipRelease, "release\n") !== false, 'Could not release PostgreSQL ownership namespace holder.');
        migration_assert_successes(migration_collect_group($ownershipHolder), 'PostgreSQL ownership namespace holder');
        migration_assert((int) $pdo->query("SELECT COUNT(*) FROM fixture_migrations WHERE migration = '002_namespace.sql'")->fetchColumn() === 1, 'PostgreSQL ownership namespace blocked migration namespace progress.');

        $callbackRunner = new SqlMigrationRunner($pdo, 'fixture_migrations', $fixtures, 'cpe.pg-callback');
        migration_assert_throws(
            'pg callback failure',
            static fn () => $callbackRunner->run(static function (): void {
                throw new RuntimeException('pg callback failure');
            }),
            'PostgreSQL callback failure was not propagated',
        );
        $callbackRunner->run(static function (PDO $connection): void {
            $connection->exec('CREATE TABLE pg_callback_retry (id BIGINT PRIMARY KEY)');
        });

        $callbackMutationDirectory = $root . '/pg-callback-registry-mutations';
        migration_assert(mkdir($callbackMutationDirectory, 0700), 'Could not create PostgreSQL callback registry-mutation fixture directory.');
        migration_write_fixture($callbackMutationDirectory, '001_callback.sql', 'SELECT 1;');

        $callbackInsertRunner = new SqlMigrationRunner(
            $pdo,
            'callback_insert_migrations',
            $callbackMutationDirectory,
            'cpe.pg-callback-insert',
        );
        $callbackInsertRunner->run();
        migration_assert_throws(
            SqlMigrationRunner::ERROR_REGISTRY,
            static fn () => $callbackInsertRunner->run(static function (PDO $connection): void {
                $connection->exec(
                    "INSERT INTO callback_insert_migrations (migration, applied_at) VALUES ('999_callback.sql', '2030-01-01 00:00:00')",
                );
            }),
            'PostgreSQL callback inserted an unknown registry row without failing the run',
        );
        migration_assert((int) $pdo->query("SELECT COUNT(*) FROM callback_insert_migrations WHERE migration = '999_callback.sql'")->fetchColumn() === 1, 'PostgreSQL callback insertion was misleadingly reported as rolled back.');

        $callbackDeleteRunner = new SqlMigrationRunner(
            $pdo,
            'callback_delete_migrations',
            $callbackMutationDirectory,
            'cpe.pg-callback-delete',
        );
        $callbackDeleteRunner->run();
        migration_assert_throws(
            SqlMigrationRunner::ERROR_REGISTRY,
            static fn () => $callbackDeleteRunner->run(static function (PDO $connection): void {
                $connection->exec("DELETE FROM callback_delete_migrations WHERE migration = '001_callback.sql'");
            }),
            'PostgreSQL callback deleted a discovered registry row without failing the run',
        );
        migration_assert((int) $pdo->query('SELECT COUNT(*) FROM callback_delete_migrations')->fetchColumn() === 0, 'PostgreSQL callback deletion was misleadingly reported as rolled back.');

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        migration_write_fixture(
            $fixtures,
            '003_crash.sql',
            'CREATE TABLE pg_crash_product (id BIGINT PRIMARY KEY); SELECT pg_sleep(30);',
        );
        $applicationName = 'cpe_migration_crash_' . bin2hex(random_bytes(5));
        $backendPidSignal = $root . '/pg-crash-backend-pid';
        $crash = migration_spawn_group([[
            'mode' => 'crash_migrate', 'database_url' => $url, 'schema' => $schema,
            'directory' => $fixtures, 'registry' => 'fixture_migrations', 'namespace' => 'cpe.pg-crash',
            'application_name' => $applicationName, 'signal' => $backendPidSignal,
        ]]);
        migration_start_group($crash);
        migration_wait_file($backendPidSignal, 'PostgreSQL crash worker did not publish its backend PID.');
        $backendPid = trim((string) file_get_contents($backendPidSignal));
        migration_assert(preg_match('/^[0-9]+$/', $backendPid) === 1, 'PostgreSQL crash worker published an invalid backend PID.');
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $active = $admin->prepare(
                "SELECT COUNT(*) FROM pg_catalog.pg_stat_activity "
                . "WHERE pid = CAST(? AS INTEGER) AND application_name = ? AND query LIKE '%pg_sleep%'",
            );
            $active->execute([$backendPid, $applicationName]);
            if ((int) $active->fetchColumn() > 0) {
                break;
            }
            migration_assert(hrtime(true) < $deadline, 'PostgreSQL crash migration did not reach pg_sleep.');
            usleep(10_000);
        } while (true);
        migration_terminate_workers($crash['workers']);
        $crashResult = migration_collect_group($crash);
        migration_assert($crashResult[0]['code'] !== 0, 'PostgreSQL crash worker unexpectedly completed.');

        $backendStatus = $admin->prepare(
            'SELECT state FROM pg_catalog.pg_stat_activity '
            . 'WHERE pid = CAST(? AS INTEGER) AND application_name = ?',
        );
        $backendStatus->execute([$backendPid, $applicationName]);
        $backendStillActive = $backendStatus->fetchColumn() !== false;
        if ($backendStillActive) {
            $recoveryProbe = migration_postgres($url, $schema);
            migration_assert_throws(
                DatabaseLock::ERROR_TIMEOUT,
                static fn () => DatabaseLock::synchronized(
                    $recoveryProbe,
                    'cpe.pg-crash',
                    static function (): void {
                    },
                    100,
                ),
                'A still-active PostgreSQL crash backend did not retain its session advisory lock',
            );

            $terminate = $admin->prepare(
                'SELECT CASE WHEN pg_catalog.pg_terminate_backend(pid) THEN 1 ELSE 0 END '
                . 'FROM pg_catalog.pg_stat_activity '
                . 'WHERE pid = CAST(? AS INTEGER) AND application_name = ?',
            );
            $terminate->execute([$backendPid, $applicationName]);
            $terminationResult = $terminate->fetchColumn();
            if ((int) $terminationResult !== 1) {
                $alreadyGone = $admin->prepare(
                    'SELECT COUNT(*) FROM pg_catalog.pg_stat_activity '
                    . 'WHERE pid = CAST(? AS INTEGER) AND application_name = ?',
                );
                $alreadyGone->execute([$backendPid, $applicationName]);
                migration_assert(
                    (int) $alreadyGone->fetchColumn() === 0,
                    'Control connection neither terminated nor observed the end of the exact synthetic PostgreSQL crash backend.',
                );
            }
        }

        $backendGoneDeadline = hrtime(true) + 10_000_000_000;
        do {
            $backendGone = $admin->prepare(
                'SELECT COUNT(*) FROM pg_catalog.pg_stat_activity WHERE pid = CAST(? AS INTEGER)',
            );
            $backendGone->execute([$backendPid]);
            if ((int) $backendGone->fetchColumn() === 0) {
                break;
            }
            migration_assert(hrtime(true) < $backendGoneDeadline, 'PostgreSQL crash backend remained active after termination.');
            usleep(10_000);
        } while (true);

        DatabaseLock::synchronized(
            migration_postgres($url, $schema),
            'cpe.pg-crash',
            static function (): void {
            },
            500,
        );
        $pdo = migration_postgres($url, $schema);
        $crashProduct = $pdo->prepare(
            'SELECT COUNT(*) FROM pg_catalog.pg_class c '
            . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
            . 'WHERE n.nspname = ? AND c.relname = ?',
        );
        $crashProduct->execute([$schema, 'pg_crash_product']);
        migration_assert((int) $crashProduct->fetchColumn() === 0, 'PostgreSQL crash left product DDL behind.');
        migration_assert((int) $pdo->query("SELECT COUNT(*) FROM fixture_migrations WHERE migration = '003_crash.sql'")->fetchColumn() === 0, 'PostgreSQL crash left its registry row behind.');
        migration_write_fixture($fixtures, '003_crash.sql', 'CREATE TABLE pg_crash_product (id BIGINT PRIMARY KEY);');
        (new SqlMigrationRunner($pdo, 'fixture_migrations', $fixtures, 'cpe.pg-crash'))->run();
        migration_assert((int) $pdo->query('SELECT COUNT(*) FROM fixture_migrations')->fetchColumn() === 3, 'PostgreSQL crash recovery did not resume all migrations.');
    } finally {
        $admin->exec('DROP SCHEMA IF EXISTS "' . $schema . '" CASCADE');
        migration_remove_tree($root);
    }
}

migration_sqlite_contract();
$postgresUrl = trim((string) (getenv('CPE_DATABASE_URL') ?: ''));
if ($postgresUrl !== '') {
    migration_postgres_contract($postgresUrl);
}

echo 'PASS migration lock contract (SQLite' . ($postgresUrl !== '' ? ' + PostgreSQL' : '') . ")\n";
