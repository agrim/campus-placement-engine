<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Persistence\DatabaseLock;
use App\Core\Persistence\DatabaseOwnership;
use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use App\Support\Database;

const OWNERSHIP_ENGINE_MARKERS = ['migrations', 'settings', 'users', 'candidates', 'companies', 'applications'];
const OWNERSHIP_CLOUD_MARKERS = ['hosted_migrations', 'hosted_tenants', 'hosted_deployments'];

function ownership_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ownership_assert_throws(string $identifier, callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $e) {
        ownership_assert(str_contains($e->getMessage(), $identifier), $message . ': ' . $e->getMessage());
        return;
    }
    throw new RuntimeException($message . ': no exception was thrown.');
}

function ownership_assert_throws_detail(string $identifier, string $detail, callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $e) {
        ownership_assert(str_contains($e->getMessage(), $identifier), $message . ': ' . $e->getMessage());
        ownership_assert(str_contains($e->getMessage(), $detail), $message . ' used the wrong validation path: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException($message . ': no exception was thrown.');
}

function ownership_sqlite(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function ownership_postgres(string $url, string $schema): PDO
{
    $pdo = PostgresConnectionProvider::fromUrl($url, 'ownership test database URL')->connection();
    $pdo->exec('SET search_path TO "' . $schema . '"');
    return $pdo;
}

/** @param array<int, string> $tables */
function ownership_create_markers(PDO $pdo, array $tables): void
{
    foreach ($tables as $table) {
        ownership_assert(preg_match('/^[a-z_]+$/', $table) === 1, 'Unsafe ownership test table name.');
        $pdo->exec('CREATE TABLE ' . $table . ' (id INTEGER NOT NULL)');
    }
}

function ownership_has_relation(PDO $pdo, string $name): bool
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $query = $pdo->prepare("SELECT 1 FROM main.sqlite_master WHERE type IN ('table', 'view') AND name = ?");
        $query->execute([$name]);
        return $query->fetchColumn() !== false;
    }
    $query = $pdo->prepare(
        "SELECT EXISTS (
            SELECT 1 FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = current_schema() AND c.relname = CAST(? AS TEXT)
        )",
    );
    $query->execute([$name]);
    return in_array($query->fetchColumn(), [true, 1, '1', 't', 'true'], true);
}

/**
 * @param array{sqlite_paths?: array<int, string>, database_url?: string, schema?: string, schemas?: array<int, string>, cwd?: string, create_probe?: bool, modes?: array<int, string>, signals?: array<int, string>, releases?: array<int, string>, timeout_ms?: int} $target
 * @param array<int, string> $owners
 * @return array{barrier: string, start: string, workers: array<int, array{process: resource, pipes: array<int, resource>, ready: string}>}
 */
function ownership_spawn_group(array $target, array $owners): array
{
    $barrier = sys_get_temp_dir() . '/cpe-ownership-barrier-' . bin2hex(random_bytes(6));
    if (!mkdir($barrier, 0700, true) && !is_dir($barrier)) {
        throw new RuntimeException('Could not create ownership test barrier.');
    }
    $start = $barrier . '/start';
    $workers = [];
    try {
        foreach ($owners as $index => $owner) {
            $ready = $barrier . '/ready-' . $index;
            $environment = getenv();
            ownership_assert(is_array($environment), 'Could not read process environment for ownership race.');
            $environment['CPE_OWNERSHIP_TEST_OWNER'] = $owner;
            $environment['CPE_OWNERSHIP_TEST_READY'] = $ready;
            $environment['CPE_OWNERSHIP_TEST_START'] = $start;
            $environment['CPE_OWNERSHIP_TEST_SQLITE_PATH'] = (string) (($target['sqlite_paths'][$index] ?? null) ?? '');
            $environment['CPE_OWNERSHIP_TEST_DATABASE_URL'] = (string) ($target['database_url'] ?? '');
            $environment['CPE_OWNERSHIP_TEST_SCHEMA'] = (string) (($target['schemas'][$index] ?? null) ?? ($target['schema'] ?? ''));
            $environment['CPE_OWNERSHIP_TEST_CWD'] = (string) ($target['cwd'] ?? '');
            $environment['CPE_OWNERSHIP_TEST_CREATE_PROBE'] = ($target['create_probe'] ?? true) ? '1' : '0';
            $environment['CPE_OWNERSHIP_TEST_MODE'] = (string) (($target['modes'][$index] ?? null) ?? 'claim');
            $environment['CPE_OWNERSHIP_TEST_SIGNAL'] = (string) (($target['signals'][$index] ?? null) ?? '');
            $environment['CPE_OWNERSHIP_TEST_RELEASE'] = (string) (($target['releases'][$index] ?? null) ?? '');
            $environment['CPE_OWNERSHIP_TEST_LOCK_TIMEOUT_MS'] = (string) ($target['timeout_ms'] ?? 5000);
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/database_ownership_worker.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                __DIR__ . '/..',
                $environment,
            );
            ownership_assert(is_resource($process), 'Could not start ownership race worker.');
            fclose($pipes[0]);
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'ready' => $ready];
        }

        $deadline = hrtime(true) + 10_000_000_000;
        foreach ($workers as $worker) {
            while (!is_file($worker['ready'])) {
                ownership_assert(hrtime(true) < $deadline, 'Ownership race worker did not reach the barrier.');
                usleep(1000);
            }
        }
        return ['barrier' => $barrier, 'start' => $start, 'workers' => $workers];
    } catch (Throwable $e) {
        ownership_terminate_workers($workers);
        foreach (scandir($barrier) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($barrier . '/' . $entry);
            }
        }
        @rmdir($barrier);
        throw $e;
    }
}

/** @param array<int, array{process: resource, pipes: array<int, resource>, ready: string}> $workers */
function ownership_terminate_workers(array $workers): void
{
    foreach ($workers as $worker) {
        $status = proc_get_status($worker['process']);
        if (($status['running'] ?? false) === true) {
            proc_terminate($worker['process']);
            usleep(10_000);
            $status = proc_get_status($worker['process']);
            if (($status['running'] ?? false) === true) {
                proc_terminate($worker['process'], 9);
            }
        }
    }
}

/** @param array{barrier: string, start: string, workers: array<int, array{process: resource, pipes: array<int, resource>, ready: string}>} $group */
function ownership_start_group(array $group): void
{
    ownership_assert(file_put_contents($group['start'], "start\n") !== false, 'Could not release ownership race barrier.');
}

/**
 * @param array{barrier: string, start: string, workers: array<int, array{process: resource, pipes: array<int, resource>, ready: string}>} $group
 * @return array<int, array{code: int, stdout: string, stderr: string}>
 */
function ownership_collect_group(array $group, int $timeoutMilliseconds = 10000): array
{
    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    $exitCodes = [];
    try {
        while (count($exitCodes) < count($group['workers'])) {
            foreach ($group['workers'] as $index => $worker) {
                if (array_key_exists($index, $exitCodes)) {
                    continue;
                }
                $status = proc_get_status($worker['process']);
                if (($status['running'] ?? false) === false) {
                    $exitCodes[$index] = (int) ($status['exitcode'] ?? -1);
                }
            }
            if (count($exitCodes) === count($group['workers'])) {
                break;
            }
            if (hrtime(true) >= $deadline) {
                ownership_terminate_workers($group['workers']);
                throw new RuntimeException('Ownership test workers exceeded the completion deadline.');
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
                'code' => $exitCodes[$index] >= 0 ? $exitCodes[$index] : $closed,
                'stdout' => (string) $stdout,
                'stderr' => (string) $stderr,
            ];
        }
        return $results;
    } finally {
        foreach (scandir($group['barrier']) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($group['barrier'] . '/' . $entry);
            }
        }
        @rmdir($group['barrier']);
    }
}

/**
 * @param array{sqlite_paths?: array<int, string>, database_url?: string, schema?: string, schemas?: array<int, string>, cwd?: string, create_probe?: bool, modes?: array<int, string>, signals?: array<int, string>, releases?: array<int, string>, timeout_ms?: int} $target
 * @param array<int, string> $owners
 * @return array<int, array{code: int, stdout: string, stderr: string}>
 */
function ownership_race(array $target, array $owners): array
{
    $group = ownership_spawn_group($target, $owners);
    ownership_start_group($group);
    return ownership_collect_group($group);
}

function ownership_wait_file(string $path, string $message, int $timeoutMilliseconds = 10000): void
{
    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    while (!is_file($path)) {
        ownership_assert(hrtime(true) < $deadline, $message);
        usleep(1000);
    }
}

function ownership_assert_cross_race(PDO $pdo, array $results, string $label): void
{
    $successes = array_values(array_filter($results, static fn (array $result): bool => $result['code'] === 0));
    $failures = array_values(array_filter($results, static fn (array $result): bool => $result['code'] !== 0));
    ownership_assert(count($successes) === 1 && count($failures) === 1, $label . ' must have exactly one winner.');
    ownership_assert(
        str_contains($failures[0]['stderr'], DatabaseOwnership::ERROR_CONFLICT),
        $label . ' loser must report the fixed ownership conflict identifier: ' . $failures[0]['stderr'],
    );
    $owner = (string) $pdo->query('SELECT owner_kind FROM cpe_database_ownership')->fetchColumn();
    $winnerProbe = $owner === DatabaseOwnership::OWNER_ENGINE_INSTITUTION ? 'migrations' : 'hosted_migrations';
    $loserProbe = $owner === DatabaseOwnership::OWNER_ENGINE_INSTITUTION ? 'hosted_migrations' : 'migrations';
    ownership_assert(ownership_has_relation($pdo, $winnerProbe), $label . ' winner probe is absent.');
    ownership_assert(!ownership_has_relation($pdo, $loserProbe), $label . ' loser created product DDL.');
}

function ownership_assert_lock_timeout(array $results, string $label): void
{
    ownership_assert(count($results) === 1 && $results[0]['code'] !== 0, $label . ' unexpectedly acquired the held lock.');
    ownership_assert(
        str_contains($results[0]['stderr'], DatabaseLock::ERROR_TIMEOUT),
        $label . ' did not report the fixed lock timeout identifier: ' . $results[0]['stderr'],
    );
}

function ownership_sqlite_deterministic_contention(string $root): void
{
    $path = $root . '/deterministic.sqlite';
    ownership_sqlite($path)->query('PRAGMA user_version')->fetchColumn();
    $alias = $root . '/deterministic-alias.sqlite';
    ownership_assert(symlink($path, $alias), 'Could not create deterministic SQLite symlink alias.');
    $signal = $root . '/holder-locked';
    $release = $root . '/holder-release';
    $holder = ownership_spawn_group(
        [
            'sqlite_paths' => [$path],
            'modes' => ['hold_lock'],
            'signals' => [$signal],
            'releases' => [$release],
            'create_probe' => false,
            'timeout_ms' => 5000,
        ],
        [DatabaseOwnership::OWNER_ENGINE_INSTITUTION],
    );
    ownership_start_group($holder);
    ownership_wait_file($signal, 'SQLite literal ownership lock holder did not acquire the lock.');

    $relative = ownership_race(
        [
            'sqlite_paths' => ['deterministic.sqlite'],
            'cwd' => $root,
            'modes' => ['lock_only'],
            'timeout_ms' => 75,
        ],
        [DatabaseOwnership::OWNER_ENGINE_INSTITUTION],
    );
    ownership_assert_lock_timeout($relative, 'SQLite real-path holder versus relative contender');
    $symlink = ownership_race(
        ['sqlite_paths' => [$alias], 'modes' => ['lock_only'], 'timeout_ms' => 75],
        [DatabaseOwnership::OWNER_ENGINE_INSTITUTION],
    );
    ownership_assert_lock_timeout($symlink, 'SQLite real-path holder versus symlink contender');

    $claimers = ownership_spawn_group(
        ['sqlite_paths' => [$path, $alias]],
        [DatabaseOwnership::OWNER_ENGINE_INSTITUTION, DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE],
    );
    ownership_start_group($claimers);
    usleep(125_000);
    $observer = ownership_sqlite($path);
    ownership_assert(!ownership_has_relation($observer, 'cpe_database_ownership'), 'SQLite waiters inspected or created ownership DDL before the literal lock was released.');
    ownership_assert(!ownership_has_relation($observer, 'migrations'), 'SQLite Engine waiter created probe DDL before lock release.');
    ownership_assert(!ownership_has_relation($observer, 'hosted_migrations'), 'SQLite Cloud waiter created probe DDL before lock release.');

    ownership_assert(file_put_contents($release, "release\n") !== false, 'Could not release deterministic SQLite lock holder.');
    $holderResult = ownership_collect_group($holder);
    ownership_assert(count($holderResult) === 1 && $holderResult[0]['code'] === 0, 'SQLite deterministic lock holder failed: ' . $holderResult[0]['stderr']);
    $results = ownership_collect_group($claimers);
    ownership_assert_cross_race(ownership_sqlite($path), $results, 'SQLite deterministic held-lock cross-owner race');
}

function ownership_sqlite_crash_after_claim(string $path): void
{
    $signal = dirname($path) . '/crash-claimed-' . bin2hex(random_bytes(4));
    $crash = ownership_race(
        [
            'sqlite_paths' => [$path],
            'modes' => ['crash_after_claim'],
            'signals' => [$signal],
            'create_probe' => false,
        ],
        [DatabaseOwnership::OWNER_ENGINE_INSTITUTION],
    );
    ownership_assert(count($crash) === 1 && $crash[0]['code'] === 0 && is_file($signal), 'SQLite crash-after-claim worker did not commit and exit cleanly.');
    $pdo = ownership_sqlite($path);
    ownership_assert(!ownership_has_relation($pdo, 'migrations'), 'Crash-after-claim worker created product DDL.');
    ownership_assert(!ownership_has_relation($pdo, 'hosted_migrations'), 'Crash-after-claim worker created opposite product DDL.');
    $claimedAt = (string) $pdo->query('SELECT claimed_at FROM main.cpe_database_ownership')->fetchColumn();

    $resume = ownership_race(
        ['sqlite_paths' => [$path], 'create_probe' => false],
        [DatabaseOwnership::OWNER_ENGINE_INSTITUTION],
    );
    ownership_assert(count($resume) === 1 && $resume[0]['code'] === 0, 'Same owner could not resume after crash-after-claim: ' . $resume[0]['stderr']);
    $opposite = ownership_race(
        ['sqlite_paths' => [$path], 'create_probe' => false],
        [DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE],
    );
    ownership_assert(count($opposite) === 1 && $opposite[0]['code'] !== 0, 'Opposite owner resumed after crash-after-claim.');
    ownership_assert(str_contains($opposite[0]['stderr'], DatabaseOwnership::ERROR_CONFLICT), 'Opposite crash-resume refusal lacked the fixed conflict identifier.');
    ownership_assert((string) $pdo->query('SELECT claimed_at FROM main.cpe_database_ownership')->fetchColumn() === $claimedAt, 'Crash recovery rewrote immutable claimed_at.');
}

function ownership_create_lax_contract(PDO $pdo, string $extraColumn = ''): void
{
    $pdo->exec(
        'CREATE TABLE main.cpe_database_ownership ('
        . 'singleton_id INTEGER NOT NULL PRIMARY KEY, owner_kind TEXT NOT NULL, '
        . 'contract_version INTEGER NOT NULL, claimed_at TEXT NOT NULL'
        . $extraColumn . ')',
    );
}

/** @param array<int, string> $schemas */
function ownership_postgres_relation_count(PDO $pdo, array $schemas, string $name): int
{
    $placeholders = implode(', ', array_fill(0, count($schemas), '?'));
    $query = $pdo->prepare(
        'SELECT COUNT(*) FROM pg_catalog.pg_class c '
        . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
        . 'WHERE n.nspname IN (' . $placeholders . ') AND c.relname = ?',
    );
    $query->execute([...$schemas, $name]);
    return (int) $query->fetchColumn();
}

function ownership_postgres_cross_schema_contention(PDO $admin, string $url, string $engineSchema, string $cloudSchema): void
{
    $signal = sys_get_temp_dir() . '/cpe-pg-holder-' . bin2hex(random_bytes(5));
    $release = $signal . '-release';
    $holder = ownership_spawn_group(
        [
            'database_url' => $url,
            'schemas' => [$engineSchema],
            'modes' => ['hold_lock'],
            'signals' => [$signal],
            'releases' => [$release],
            'create_probe' => false,
        ],
        [DatabaseOwnership::OWNER_ENGINE_INSTITUTION],
    );
    ownership_start_group($holder);
    ownership_wait_file($signal, 'PostgreSQL literal ownership lock holder did not acquire the lock.');

    $claimers = ownership_spawn_group(
        ['database_url' => $url, 'schemas' => [$engineSchema, $cloudSchema]],
        [DatabaseOwnership::OWNER_ENGINE_INSTITUTION, DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE],
    );
    ownership_start_group($claimers);
    usleep(125_000);
    $schemas = [$engineSchema, $cloudSchema];
    ownership_assert(ownership_postgres_relation_count($admin, $schemas, 'cpe_database_ownership') === 0, 'PostgreSQL cross-search_path waiters created ownership DDL before lock release.');
    ownership_assert(ownership_postgres_relation_count($admin, $schemas, 'migrations') === 0, 'PostgreSQL Engine waiter created probe DDL before lock release.');
    ownership_assert(ownership_postgres_relation_count($admin, $schemas, 'hosted_migrations') === 0, 'PostgreSQL Cloud waiter created probe DDL before lock release.');

    ownership_assert(file_put_contents($release, "release\n") !== false, 'Could not release PostgreSQL ownership lock holder.');
    $holderResult = ownership_collect_group($holder);
    ownership_assert(count($holderResult) === 1 && $holderResult[0]['code'] === 0, 'PostgreSQL deterministic lock holder failed.');
    $results = ownership_collect_group($claimers);
    $successes = array_values(array_filter($results, static fn (array $result): bool => $result['code'] === 0));
    $failures = array_values(array_filter($results, static fn (array $result): bool => $result['code'] !== 0));
    ownership_assert(count($successes) === 1 && count($failures) === 1, 'PostgreSQL cross-search_path race must have exactly one winner.');
    ownership_assert(str_contains($failures[0]['stderr'], DatabaseOwnership::ERROR_CONFLICT), 'PostgreSQL cross-search_path loser lacked the fixed conflict identifier.');
    ownership_assert(ownership_postgres_relation_count($admin, $schemas, 'cpe_database_ownership') === 1, 'PostgreSQL cross-search_path race did not produce exactly one physical owner table.');

    $winnerSchemaQuery = $admin->prepare(
        "SELECT n.nspname FROM pg_catalog.pg_class c
         JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
         WHERE c.relname = 'cpe_database_ownership' AND n.nspname IN (?, ?)",
    );
    $winnerSchemaQuery->execute([$engineSchema, $cloudSchema]);
    $winnerSchema = (string) $winnerSchemaQuery->fetchColumn();
    if ($winnerSchema === $engineSchema) {
        $owner = (string) $admin->query('SELECT owner_kind FROM "' . $engineSchema . '".cpe_database_ownership')->fetchColumn();
        ownership_assert($owner === DatabaseOwnership::OWNER_ENGINE_INSTITUTION, 'PostgreSQL Engine schema stored the wrong owner.');
        ownership_assert(ownership_postgres_relation_count($admin, [$engineSchema], 'migrations') === 1, 'PostgreSQL Engine winner probe is absent.');
        ownership_assert(ownership_postgres_relation_count($admin, [$cloudSchema], 'hosted_migrations') === 0, 'PostgreSQL Cloud loser created probe DDL.');
    } else {
        ownership_assert($winnerSchema === $cloudSchema, 'PostgreSQL ownership table appeared outside both contender schemas.');
        $owner = (string) $admin->query('SELECT owner_kind FROM "' . $cloudSchema . '".cpe_database_ownership')->fetchColumn();
        ownership_assert($owner === DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE, 'PostgreSQL Cloud schema stored the wrong owner.');
        ownership_assert(ownership_postgres_relation_count($admin, [$cloudSchema], 'hosted_migrations') === 1, 'PostgreSQL Cloud winner probe is absent.');
        ownership_assert(ownership_postgres_relation_count($admin, [$engineSchema], 'migrations') === 0, 'PostgreSQL Engine loser created probe DDL.');
    }
    @unlink($signal);
    @unlink($release);
}

function ownership_postgres_crash_after_claim(string $url, string $schema): void
{
    $signal = sys_get_temp_dir() . '/cpe-pg-crash-' . bin2hex(random_bytes(5));
    $crash = ownership_race(
        [
            'database_url' => $url,
            'schema' => $schema,
            'modes' => ['crash_after_claim'],
            'signals' => [$signal],
            'create_probe' => false,
        ],
        [DatabaseOwnership::OWNER_ENGINE_INSTITUTION],
    );
    ownership_assert(count($crash) === 1 && $crash[0]['code'] === 0 && is_file($signal), 'PostgreSQL crash-after-claim worker failed.');
    $pdo = ownership_postgres($url, $schema);
    $claimedAt = (string) $pdo->query('SELECT claimed_at FROM "' . $schema . '".cpe_database_ownership')->fetchColumn();
    $resume = ownership_race(
        ['database_url' => $url, 'schema' => $schema, 'create_probe' => false],
        [DatabaseOwnership::OWNER_ENGINE_INSTITUTION],
    );
    ownership_assert(count($resume) === 1 && $resume[0]['code'] === 0, 'PostgreSQL same owner could not resume after crash-after-claim.');
    $opposite = ownership_race(
        ['database_url' => $url, 'schema' => $schema, 'create_probe' => false],
        [DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE],
    );
    ownership_assert(count($opposite) === 1 && $opposite[0]['code'] !== 0, 'PostgreSQL opposite owner resumed after crash-after-claim.');
    ownership_assert(str_contains($opposite[0]['stderr'], DatabaseOwnership::ERROR_CONFLICT), 'PostgreSQL crash-resume refusal lacked the fixed conflict identifier.');
    ownership_assert((string) $pdo->query('SELECT claimed_at FROM "' . $schema . '".cpe_database_ownership')->fetchColumn() === $claimedAt, 'PostgreSQL crash recovery rewrote claimed_at.');
    @unlink($signal);
}

function ownership_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                ownership_remove_tree($path);
            } else {
                unlink($path);
            }
        }
    }
    rmdir($directory);
}

function ownership_sqlite_contract(): void
{
    $root = sys_get_temp_dir() . '/cpe-ownership-sqlite-' . bin2hex(random_bytes(6));
    ownership_assert(mkdir($root, 0700, true), 'Could not create SQLite ownership test directory.');
    try {
        ownership_sqlite_deterministic_contention($root);

        $crossPath = $root . '/cross.sqlite';
        $cross = ownership_race(
            ['sqlite_paths' => [$crossPath, $crossPath]],
            [DatabaseOwnership::OWNER_ENGINE_INSTITUTION, DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE],
        );
        ownership_assert_cross_race(ownership_sqlite($crossPath), $cross, 'SQLite cross-owner race');

        $samePath = $root . '/same.sqlite';
        $same = ownership_race(
            ['sqlite_paths' => [$samePath, $samePath], 'create_probe' => false],
            [DatabaseOwnership::OWNER_ENGINE_INSTITUTION, DatabaseOwnership::OWNER_ENGINE_INSTITUTION],
        );
        ownership_assert(count(array_filter($same, static fn (array $result): bool => $result['code'] === 0)) === 2, 'SQLite same-owner race must allow both callers.');
        $samePdo = ownership_sqlite($samePath);
        ownership_assert((int) $samePdo->query('SELECT COUNT(*) FROM main.cpe_database_ownership')->fetchColumn() === 1, 'SQLite same-owner race created more than one owner row.');

        ownership_sqlite_crash_after_claim($root . '/crash.sqlite');

        $observedPath = $root . '/observed.sqlite';
        Database::useProvider(new SqliteConnectionProvider($observedPath));
        try {
            ownership_assert(!Database::isInstalled(), 'Fresh observed database unexpectedly appeared installed.');
            Database::pendingMigrations();
            ownership_assert(!ownership_has_relation(Database::connection(), 'cpe_database_ownership'), 'Observational database methods claimed ownership.');
        } finally {
            Database::reset();
        }

        $repeat = ownership_sqlite($root . '/repeat.sqlite');
        DatabaseOwnership::claimOrVerify($repeat, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        $claimedAt = (string) $repeat->query('SELECT claimed_at FROM main.cpe_database_ownership')->fetchColumn();
        ownership_create_markers($repeat, ['migrations']);
        DatabaseOwnership::claimOrVerify($repeat, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        ownership_assert((string) $repeat->query('SELECT claimed_at FROM main.cpe_database_ownership')->fetchColumn() === $claimedAt, 'Repeat ownership verification rewrote claimed_at.');
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CONFLICT,
            static fn () => DatabaseOwnership::claimOrVerify($repeat, DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE),
            'Wrong owner was accepted after claim',
        );

        $legacyEngine = ownership_sqlite($root . '/legacy-engine.sqlite');
        ownership_create_markers($legacyEngine, OWNERSHIP_ENGINE_MARKERS);
        DatabaseOwnership::claimOrVerify($legacyEngine, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        ownership_assert((string) $legacyEngine->query('SELECT owner_kind FROM main.cpe_database_ownership')->fetchColumn() === DatabaseOwnership::OWNER_ENGINE_INSTITUTION, 'Complete Engine legacy database was not adopted.');

        $legacyCloud = ownership_sqlite($root . '/legacy-cloud.sqlite');
        ownership_create_markers($legacyCloud, OWNERSHIP_CLOUD_MARKERS);
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CONFLICT,
            static fn () => DatabaseOwnership::claimOrVerify($legacyCloud, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Complete Cloud legacy database was claimed by Engine',
        );
        ownership_assert(!ownership_has_relation($legacyCloud, 'cpe_database_ownership'), 'Cloud legacy refusal left an ownership table.');
        DatabaseOwnership::claimOrVerify($legacyCloud, DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE);

        foreach ([
            'partial' => ['migrations'],
            'mixed' => ['migrations', 'hosted_migrations'],
            'unknown' => ['unrelated_product_table'],
        ] as $case => $markers) {
            $pdo = ownership_sqlite($root . '/' . $case . '.sqlite');
            ownership_create_markers($pdo, $markers);
            ownership_assert_throws(
                DatabaseOwnership::ERROR_AMBIGUOUS,
                static fn () => DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
                'SQLite ' . $case . ' legacy database was claimed',
            );
            ownership_assert(!ownership_has_relation($pdo, 'cpe_database_ownership'), 'SQLite ' . $case . ' refusal left an ownership table.');
        }

        $corrupt = ownership_sqlite($root . '/corrupt.sqlite');
        ownership_create_lax_contract($corrupt);
        $corrupt->exec("INSERT INTO main.cpe_database_ownership VALUES (1, 'engine_institution', 1, '2026-01-01T00:00:00Z')");
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CORRUPT,
            static fn () => DatabaseOwnership::claimOrVerify($corrupt, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Corrupt ownership table was accepted',
        );

        $malformed = ownership_sqlite($root . '/malformed.sqlite');
        $malformed->exec(
            'CREATE TABLE main.cpe_database_ownership ('
            . 'singleton_id INTEGER NOT NULL PRIMARY KEY CHECK (singleton_id = 1), '
            . "owner_kind TEXT NOT NULL CHECK (owner_kind IN ('engine_institution', 'cloud_control_plane')), "
            . 'contract_version INTEGER NOT NULL CHECK (contract_version >= 0), '
            . 'claimed_at TEXT NOT NULL)',
        );
        $malformed->exec(
            'CREATE TRIGGER main.cpe_database_ownership_immutable '
            . 'BEFORE UPDATE OF owner_kind, claimed_at ON cpe_database_ownership '
            . 'FOR EACH ROW WHEN NEW.owner_kind IS NOT OLD.owner_kind OR NEW.claimed_at IS NOT OLD.claimed_at '
            . "BEGIN SELECT RAISE(ABORT, 'database ownership identity is immutable'); END",
        );
        $malformed->exec("INSERT INTO main.cpe_database_ownership VALUES (1, 'engine_institution', 1, '2026-01-01T00:00:00Z')");
        ownership_assert_throws_detail(
            DatabaseOwnership::ERROR_CORRUPT,
            'contract-version insert constraint is invalid',
            static fn () => DatabaseOwnership::claimOrVerify($malformed, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Malformed version constraint was accepted',
        );

        $broadTrigger = ownership_sqlite($root . '/broad-trigger.sqlite');
        $broadTrigger->exec(
            'CREATE TABLE main.cpe_database_ownership ('
            . 'singleton_id INTEGER NOT NULL PRIMARY KEY CHECK (singleton_id = 1), '
            . "owner_kind TEXT NOT NULL CHECK (owner_kind IN ('engine_institution', 'cloud_control_plane')), "
            . 'contract_version INTEGER NOT NULL CHECK (contract_version >= 1), '
            . 'claimed_at TEXT NOT NULL)',
        );
        $broadTrigger->exec(
            'CREATE TRIGGER main.reject_every_ownership_update BEFORE UPDATE ON cpe_database_ownership '
            . "BEGIN SELECT RAISE(ABORT, 'all updates rejected'); END",
        );
        $broadTrigger->exec("INSERT INTO main.cpe_database_ownership VALUES (1, 'engine_institution', 1, '2026-01-01T00:00:00Z')");
        ownership_assert_throws_detail(
            DatabaseOwnership::ERROR_CORRUPT,
            'valid contract-version update was unexpectedly rejected',
            static fn () => DatabaseOwnership::claimOrVerify($broadTrigger, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Broad reject-update trigger masked the ownership contract',
        );

        $generated = ownership_sqlite($root . '/generated.sqlite');
        $generated->exec(
            'CREATE TABLE main.cpe_database_ownership ('
            . 'singleton_id INTEGER NOT NULL PRIMARY KEY CHECK (singleton_id = 1), '
            . "owner_kind TEXT NOT NULL CHECK (owner_kind IN ('engine_institution', 'cloud_control_plane')), "
            . 'contract_version INTEGER NOT NULL CHECK (contract_version >= 1), '
            . 'claimed_at TEXT NOT NULL, '
            . 'owner_shadow TEXT GENERATED ALWAYS AS (owner_kind) VIRTUAL)',
        );
        $generated->exec(
            "INSERT INTO main.cpe_database_ownership (singleton_id, owner_kind, contract_version, claimed_at) "
            . "VALUES (1, 'engine_institution', 1, '2026-01-01T00:00:00Z')",
        );
        ownership_assert_throws_detail(
            DatabaseOwnership::ERROR_CORRUPT,
            'table columns are invalid',
            static fn () => DatabaseOwnership::claimOrVerify($generated, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Generated ownership column was hidden from exact shape validation',
        );

        $empty = ownership_sqlite($root . '/empty.sqlite');
        DatabaseOwnership::claimOrVerify($empty, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        $empty->exec('DELETE FROM main.cpe_database_ownership');
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CORRUPT,
            static fn () => DatabaseOwnership::claimOrVerify($empty, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Empty ownership table was accepted',
        );

        $multiple = ownership_sqlite($root . '/multiple.sqlite');
        ownership_create_lax_contract($multiple);
        $multiple->exec("INSERT INTO main.cpe_database_ownership VALUES (1, 'engine_institution', 1, '2026-01-01T00:00:00Z')");
        $multiple->exec("INSERT INTO main.cpe_database_ownership VALUES (2, 'engine_institution', 1, '2026-01-01T00:00:00Z')");
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CORRUPT,
            static fn () => DatabaseOwnership::claimOrVerify($multiple, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Multiple ownership rows were accepted',
        );

        $extra = ownership_sqlite($root . '/extra.sqlite');
        ownership_create_lax_contract($extra, ', extra_column TEXT');
        $extra->exec("INSERT INTO main.cpe_database_ownership VALUES (1, 'engine_institution', 1, '2026-01-01T00:00:00Z', NULL)");
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CORRUPT,
            static fn () => DatabaseOwnership::claimOrVerify($extra, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Ownership table with an extra column was accepted',
        );

        $wrongOwner = ownership_sqlite($root . '/wrong-owner.sqlite');
        ownership_create_lax_contract($wrongOwner);
        $wrongOwner->exec("INSERT INTO main.cpe_database_ownership VALUES (1, 'wrong_owner', 1, '2026-01-01T00:00:00Z')");
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CORRUPT,
            static fn () => DatabaseOwnership::claimOrVerify($wrongOwner, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Invalid stored ownership value was accepted',
        );

        $versionStorage = ownership_sqlite($root . '/version-storage.sqlite');
        ownership_create_lax_contract($versionStorage);
        $versionStorage->exec("INSERT INTO main.cpe_database_ownership VALUES (1, 'engine_institution', '1junk', '2026-01-01T00:00:00Z')");
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CORRUPT,
            static fn () => DatabaseOwnership::claimOrVerify($versionStorage, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Non-integer SQLite contract version storage was accepted',
        );

        $version = ownership_sqlite($root . '/version.sqlite');
        DatabaseOwnership::claimOrVerify($version, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        $version->exec('UPDATE main.cpe_database_ownership SET contract_version = 2');
        ownership_assert_throws(
            DatabaseOwnership::ERROR_VERSION,
            static fn () => DatabaseOwnership::claimOrVerify($version, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Unsupported ownership contract version was accepted',
        );

        $opposite = ownership_sqlite($root . '/opposite.sqlite');
        DatabaseOwnership::claimOrVerify($opposite, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        ownership_create_markers($opposite, ['hosted_migrations']);
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CONFLICT,
            static fn () => DatabaseOwnership::claimOrVerify($opposite, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Owned Engine database accepted an opposite reserved marker',
        );

        $viewMarker = ownership_sqlite($root . '/view-marker.sqlite');
        $viewMarker->exec('CREATE VIEW main.migrations AS SELECT 1 AS id');
        ownership_assert_throws(
            DatabaseOwnership::ERROR_AMBIGUOUS,
            static fn () => DatabaseOwnership::claimOrVerify($viewMarker, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Reserved legacy view was treated as adoption evidence',
        );
        ownership_assert(!ownership_has_relation($viewMarker, 'cpe_database_ownership'), 'Reserved-view refusal left an ownership table.');

        $virtualMarker = ownership_sqlite($root . '/virtual-marker.sqlite');
        $virtualMarker->exec('CREATE VIRTUAL TABLE main.migrations USING fts5(value)');
        ownership_create_markers($virtualMarker, array_values(array_diff(OWNERSHIP_ENGINE_MARKERS, ['migrations'])));
        ownership_assert_throws_detail(
            DatabaseOwnership::ERROR_AMBIGUOUS,
            'reserved legacy marker is not an ordinary or partitioned table',
            static fn () => DatabaseOwnership::claimOrVerify($virtualMarker, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Reserved SQLite virtual table and its shadow tables were treated as an ordinary complete legacy signature',
        );
        ownership_assert(!ownership_has_relation($virtualMarker, 'cpe_database_ownership'), 'Reserved virtual-table refusal left an ownership table.');

        $tempShadow = ownership_sqlite($root . '/temp-shadow.sqlite');
        $tempShadow->exec('CREATE TEMP TABLE cpe_database_ownership (bad TEXT NOT NULL)');
        DatabaseOwnership::claimOrVerify($tempShadow, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        ownership_assert((string) $tempShadow->query('SELECT owner_kind FROM main.cpe_database_ownership')->fetchColumn() === DatabaseOwnership::OWNER_ENGINE_INSTITUTION, 'Temporary SQLite shadow prevented main ownership claim.');

        $memory = ownership_sqlite(':memory:');
        DatabaseOwnership::claimOrVerify($memory, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        DatabaseOwnership::claimOrVerify($memory, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CONFLICT,
            static fn () => DatabaseOwnership::claimOrVerify($memory, DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE),
            'In-memory SQLite database accepted the wrong owner',
        );

        $transaction = ownership_sqlite(':memory:');
        $transaction->beginTransaction();
        ownership_assert_throws(
            DatabaseLock::ERROR_ACTIVE_TRANSACTION,
            static fn () => DatabaseOwnership::claimOrVerify($transaction, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Ownership claim was accepted inside an active transaction',
        );
        $transaction->rollBack();

        $attached = ownership_sqlite(':memory:');
        $attached->exec("ATTACH DATABASE ':memory:' AS unexpected");
        ownership_assert_throws(
            DatabaseLock::ERROR_UNSUPPORTED,
            static fn () => DatabaseOwnership::claimOrVerify($attached, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'Attached non-temporary SQLite database was accepted',
        );

        $relativeDirectory = $root . '/relative';
        ownership_assert(mkdir($relativeDirectory, 0700), 'Could not create relative SQLite test directory.');
        $relative = ownership_race(
            ['sqlite_paths' => ['relative.sqlite', './relative.sqlite'], 'cwd' => $relativeDirectory],
            [DatabaseOwnership::OWNER_ENGINE_INSTITUTION, DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE],
        );
        ownership_assert_cross_race(ownership_sqlite($relativeDirectory . '/relative.sqlite'), $relative, 'SQLite relative-path alias race');

        $realPath = $root . '/real.sqlite';
        ownership_sqlite($realPath)->query('PRAGMA user_version')->fetchColumn();
        $aliasPath = $root . '/alias.sqlite';
        ownership_assert(symlink($realPath, $aliasPath), 'Could not create SQLite symlink alias.');
        $symlink = ownership_race(
            ['sqlite_paths' => [$realPath, $aliasPath]],
            [DatabaseOwnership::OWNER_ENGINE_INSTITUTION, DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE],
        );
        ownership_assert_cross_race(ownership_sqlite($realPath), $symlink, 'SQLite symlink alias race');
    } finally {
        ownership_remove_tree($root);
    }
}

function ownership_postgres_contract(string $url): void
{
    $schema = 'cpe_ownership_' . bin2hex(random_bytes(6));
    $otherSchema = 'cpe_ownership_' . bin2hex(random_bytes(6));
    $admin = PostgresConnectionProvider::fromUrl($url, 'ownership test database URL')->connection();
    ownership_assert(preg_match('/^[a-z][a-z0-9_]{0,62}$/', $schema) === 1, 'Generated PostgreSQL ownership schema is invalid.');
    ownership_assert(preg_match('/^[a-z][a-z0-9_]{0,62}$/', $otherSchema) === 1, 'Generated secondary PostgreSQL ownership schema is invalid.');
    $admin->exec('CREATE SCHEMA "' . $schema . '"');
    $admin->exec('CREATE SCHEMA "' . $otherSchema . '"');
    try {
        ownership_postgres_cross_schema_contention($admin, $url, $schema, $otherSchema);

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('DROP SCHEMA "' . $otherSchema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $admin->exec('CREATE SCHEMA "' . $otherSchema . '"');
        DatabaseOwnership::claimOrVerify(ownership_postgres($url, $schema), DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CONFLICT,
            static fn () => DatabaseOwnership::claimOrVerify(ownership_postgres($url, $otherSchema), DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE),
            'PostgreSQL cross-search_path opposite owner was accepted after claim',
        );
        ownership_assert(ownership_postgres_relation_count($admin, [$otherSchema], 'cpe_database_ownership') === 0, 'PostgreSQL cross-search_path refusal created a second owner table.');
        ownership_assert(ownership_postgres_relation_count($admin, [$otherSchema], 'hosted_migrations') === 0, 'PostgreSQL cross-search_path refusal created Cloud product DDL.');

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('DROP SCHEMA "' . $otherSchema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $admin->exec('CREATE SCHEMA "' . $otherSchema . '"');
        DatabaseOwnership::claimOrVerify(ownership_postgres($url, $schema), DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        $admin->exec(
            'CREATE TABLE "' . $otherSchema . '".cpe_database_ownership ('
            . 'singleton_id INTEGER NOT NULL PRIMARY KEY, owner_kind TEXT NOT NULL, '
            . 'contract_version INTEGER NOT NULL, claimed_at TEXT NOT NULL)',
        );
        ownership_assert_throws(
            DatabaseOwnership::ERROR_AMBIGUOUS,
            static fn () => DatabaseOwnership::claimOrVerify(ownership_postgres($url, $schema), DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'PostgreSQL multiple cross-schema ownership tables were accepted',
        );

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('DROP SCHEMA "' . $otherSchema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $admin->exec('CREATE SCHEMA "' . $otherSchema . '"');
        ownership_postgres_crash_after_claim($url, $schema);

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $cross = ownership_race(
            ['database_url' => $url, 'schema' => $schema],
            [DatabaseOwnership::OWNER_ENGINE_INSTITUTION, DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE],
        );
        ownership_assert_cross_race(ownership_postgres($url, $schema), $cross, 'PostgreSQL cross-owner race');

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $same = ownership_race(
            ['database_url' => $url, 'schema' => $schema, 'create_probe' => false],
            [DatabaseOwnership::OWNER_ENGINE_INSTITUTION, DatabaseOwnership::OWNER_ENGINE_INSTITUTION],
        );
        ownership_assert(count(array_filter($same, static fn (array $result): bool => $result['code'] === 0)) === 2, 'PostgreSQL same-owner race must allow both callers.');

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo = ownership_postgres($url, $schema);
        DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        $claimedAt = (string) $pdo->query('SELECT claimed_at FROM cpe_database_ownership')->fetchColumn();
        ownership_create_markers($pdo, ['migrations']);
        DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        ownership_assert((string) $pdo->query('SELECT claimed_at FROM cpe_database_ownership')->fetchColumn() === $claimedAt, 'PostgreSQL repeat verification rewrote claimed_at.');
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CONFLICT,
            static fn () => DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE),
            'PostgreSQL claimed database accepted the wrong owner',
        );

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo = ownership_postgres($url, $schema);
        ownership_create_markers($pdo, OWNERSHIP_ENGINE_MARKERS);
        DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        ownership_assert((string) $pdo->query('SELECT owner_kind FROM cpe_database_ownership')->fetchColumn() === DatabaseOwnership::OWNER_ENGINE_INSTITUTION, 'PostgreSQL complete Engine legacy database was not adopted.');

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo = ownership_postgres($url, $schema);
        ownership_create_markers($pdo, OWNERSHIP_CLOUD_MARKERS);
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CONFLICT,
            static fn () => DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'PostgreSQL complete Cloud legacy database was claimed by Engine',
        );
        ownership_assert(!ownership_has_relation($pdo, 'cpe_database_ownership'), 'PostgreSQL Cloud legacy refusal left an ownership table.');
        DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_CLOUD_CONTROL_PLANE);

        foreach ([
            'partial' => ['migrations'],
            'mixed' => ['migrations', 'hosted_migrations'],
            'unknown' => ['unrelated_product_table'],
        ] as $case => $markers) {
            $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
            $admin->exec('CREATE SCHEMA "' . $schema . '"');
            $pdo = ownership_postgres($url, $schema);
            ownership_create_markers($pdo, $markers);
            ownership_assert_throws(
                DatabaseOwnership::ERROR_AMBIGUOUS,
                static fn () => DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
                'PostgreSQL ' . $case . ' legacy database was claimed',
            );
            ownership_assert(!ownership_has_relation($pdo, 'cpe_database_ownership'), 'PostgreSQL ' . $case . ' refusal left an ownership table.');
        }

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo = ownership_postgres($url, $schema);
        $pdo->exec(
            'CREATE TABLE cpe_database_ownership ('
            . 'singleton_id INTEGER NOT NULL PRIMARY KEY, owner_kind TEXT NOT NULL, '
            . 'contract_version INTEGER NOT NULL, claimed_at TEXT NOT NULL)',
        );
        $pdo->exec("INSERT INTO cpe_database_ownership VALUES (1, 'engine_institution', 1, '2026-01-01T00:00:00Z')");
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CORRUPT,
            static fn () => DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'PostgreSQL corrupt ownership table was accepted',
        );

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo = ownership_postgres($url, $schema);
        $pdo->exec(
            'CREATE TABLE cpe_database_ownership ('
            . 'singleton_id INTEGER NOT NULL PRIMARY KEY CHECK (singleton_id = 1), '
            . "owner_kind TEXT NOT NULL CHECK (owner_kind IN ('engine_institution', 'cloud_control_plane')), "
            . 'contract_version INTEGER NOT NULL CHECK (contract_version >= 0), '
            . 'claimed_at TEXT NOT NULL)',
        );
        $pdo->exec("INSERT INTO cpe_database_ownership VALUES (1, 'engine_institution', 1, '2026-01-01T00:00:00Z')");
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CORRUPT,
            static fn () => DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'PostgreSQL malformed version constraint was accepted',
        );

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo = ownership_postgres($url, $schema);
        $pdo->exec(
            'CREATE TABLE cpe_database_ownership ('
            . 'singleton_id INTEGER NOT NULL PRIMARY KEY CHECK (singleton_id = 1), '
            . "owner_kind TEXT NOT NULL CHECK (owner_kind IN ('engine_institution', 'cloud_control_plane')), "
            . 'contract_version INTEGER NOT NULL CHECK (contract_version >= 1), '
            . 'claimed_at TEXT NOT NULL)',
        );
        $pdo->exec(
            "CREATE FUNCTION reject_every_ownership_update() RETURNS trigger LANGUAGE plpgsql AS \$cpe\$ "
            . "BEGIN RAISE EXCEPTION 'all updates rejected'; END; \$cpe\$",
        );
        $pdo->exec(
            'CREATE TRIGGER reject_every_ownership_update BEFORE UPDATE ON cpe_database_ownership '
            . 'FOR EACH ROW EXECUTE FUNCTION reject_every_ownership_update()',
        );
        $pdo->exec("INSERT INTO cpe_database_ownership VALUES (1, 'engine_institution', 1, '2026-01-01T00:00:00Z')");
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CORRUPT,
            static fn () => DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'PostgreSQL broad reject-update trigger masked the ownership contract',
        );

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo = ownership_postgres($url, $schema);
        $pdo->exec(
            'CREATE TABLE cpe_database_ownership ('
            . 'singleton_id INTEGER NOT NULL PRIMARY KEY CHECK (singleton_id = 1), '
            . "owner_kind TEXT NOT NULL CHECK (owner_kind IN ('engine_institution', 'cloud_control_plane')), "
            . 'contract_version INTEGER NOT NULL CHECK (contract_version >= 1), '
            . 'claimed_at TEXT NOT NULL, '
            . 'owner_shadow TEXT GENERATED ALWAYS AS (owner_kind) STORED)',
        );
        $pdo->exec(
            "INSERT INTO cpe_database_ownership (singleton_id, owner_kind, contract_version, claimed_at) "
            . "VALUES (1, 'engine_institution', 1, '2026-01-01T00:00:00Z')",
        );
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CORRUPT,
            static fn () => DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'PostgreSQL generated ownership column was accepted',
        );

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo = ownership_postgres($url, $schema);
        DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        $pdo->exec('UPDATE cpe_database_ownership SET contract_version = 2');
        ownership_assert_throws(
            DatabaseOwnership::ERROR_VERSION,
            static fn () => DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'PostgreSQL unsupported ownership version was accepted',
        );

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo = ownership_postgres($url, $schema);
        DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
        ownership_create_markers($pdo, ['hosted_migrations']);
        ownership_assert_throws(
            DatabaseOwnership::ERROR_CONFLICT,
            static fn () => DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'PostgreSQL owned database accepted an opposite marker',
        );

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo = ownership_postgres($url, $schema);
        $pdo->exec('CREATE SEQUENCE migrations');
        ownership_assert_throws(
            DatabaseOwnership::ERROR_AMBIGUOUS,
            static fn () => DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION),
            'PostgreSQL reserved sequence was treated as legacy adoption evidence',
        );
        ownership_assert(!ownership_has_relation($pdo, 'cpe_database_ownership'), 'PostgreSQL reserved-sequence refusal left an ownership table.');

        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        $admin->exec('CREATE SCHEMA "' . $schema . '"');
        $first = ownership_postgres($url, $schema);
        $second = ownership_postgres($url, $schema);
        $pid = (string) $first->query('SELECT pg_backend_pid()::text')->fetchColumn();
        DatabaseLock::synchronized($first, 'cpe.test.namespace-a', function () use ($second): void {
            DatabaseLock::synchronized($second, 'cpe.test.namespace-b', static function (): void {
            }, 250);
            ownership_assert_throws(
                DatabaseLock::ERROR_TIMEOUT,
                static fn () => DatabaseLock::synchronized($second, 'cpe.test.namespace-a', static function (): void {
                }, 50),
                'PostgreSQL identical lock namespace did not contend',
            );
        });
        ownership_assert((string) $first->query('SELECT pg_backend_pid()::text')->fetchColumn() === $pid, 'PostgreSQL lock changed backend sessions.');
    } finally {
        $admin->exec('DROP SCHEMA IF EXISTS "' . $schema . '" CASCADE');
        $admin->exec('DROP SCHEMA IF EXISTS "' . $otherSchema . '" CASCADE');
    }
}

ownership_sqlite_contract();
$postgresUrl = trim((string) (getenv('CPE_DATABASE_URL') ?: ''));
if ($postgresUrl !== '') {
    ownership_postgres_contract($postgresUrl);
}

echo 'PASS database ownership contract (SQLite' . ($postgresUrl !== '' ? ' + PostgreSQL' : '') . ")\n";
