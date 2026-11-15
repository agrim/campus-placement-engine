<?php

declare(strict_types=1);

$preflightRoot = sys_get_temp_dir() . '/cpe-email-preflight-' . bin2hex(random_bytes(6));
if (!mkdir($preflightRoot, 0700)) {
    throw new RuntimeException('Could not create email preflight fixture directory.');
}
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DB_PATH=' . $preflightRoot . '/legacy % ? #.sqlite');
}
putenv('CPE_LOG_PATH=' . $preflightRoot . '/structured.log');
putenv('CPE_PLATFORM_BOOTSTRAP');
define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Persistence\SqlMigrationRunner;
use App\Support\Database;

function email_preflight_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{code: int, stdout: string, stderr: string} */
function email_preflight_cli(array $arguments = []): array
{
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/placement', 'pre-upgrade-check', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
    );
    email_preflight_assert(is_resource($process), 'Could not start email preflight CLI.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

/** @return array<string, mixed> */
function email_preflight_snapshot(PDO $pdo): array
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $schema = $pdo->query(
            "SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name",
        )->fetchAll(PDO::FETCH_ASSOC);
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        )->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $schema = $pdo->query(
            "SELECT c.relname, c.relkind, a.attnum, a.attname, a.atttypid, a.attnotnull,
                    pg_catalog.pg_get_expr(d.adbin, d.adrelid) AS column_default
             FROM pg_catalog.pg_class c
             JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
             LEFT JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
             LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = c.oid AND d.adnum = a.attnum
             WHERE n.nspname = current_schema() ORDER BY c.relname, a.attnum",
        )->fetchAll(PDO::FETCH_ASSOC);
        $schema['indexes'] = $pdo->query(
            'SELECT tablename, indexname, indexdef FROM pg_catalog.pg_indexes WHERE schemaname = current_schema() ORDER BY indexname',
        )->fetchAll(PDO::FETCH_ASSOC);
        $tables = $pdo->query(
            "SELECT c.relname FROM pg_catalog.pg_class c
             JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relkind = 'r' ORDER BY c.relname",
        )->fetchAll(PDO::FETCH_COLUMN);
    }
    $rows = [];
    foreach ($tables as $table) {
        email_preflight_assert(preg_match('/\A[a-z_]+\z/D', $table) === 1, 'Unexpected fixture table identifier.');
        $tableRows = $pdo->query('SELECT * FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tableRows as &$row) {
            ksort($row);
        }
        unset($row);
        usort($tableRows, static fn (array $left, array $right): int => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR),
            json_encode($right, JSON_THROW_ON_ERROR),
        ));
        $rows[$table] = $tableRows;
    }
    return ['schema' => $schema, 'rows' => $rows];
}

/** @param array<int, string> $users */
function email_preflight_seed(PDO $pdo, array $users): void
{
    $pdo->exec('DELETE FROM users');
    $insert = $pdo->prepare(
        'INSERT INTO users (id, email, name, password_hash, role, active, scope_value) VALUES (?, ?, ?, ?, ?, ?, ?)',
    );
    foreach ($users as $id => $email) {
        $insert->execute([$id, $email, 'Private Name Sentinel', 'private-password-hash-sentinel', 'private-role-sentinel', $id % 2, 'private-scope-sentinel']);
    }
}

/** @param list<list<int>> $groups */
function email_preflight_expect(PDO $pdo, int $groupCount, int $userCount, array $groups): void
{
    $before = email_preflight_snapshot($pdo);
    $expected = ['collision_groups' => $groupCount, 'conflicting_users' => $userCount];
    $first = null;
    foreach ([[], [], ['--show-user-ids'], ['--show-user-ids']] as $arguments) {
        $result = email_preflight_cli($arguments);
        email_preflight_assert($result['code'] === ($groupCount === 0 ? 0 : 2), 'Email preflight returned an incorrect status.');
        email_preflight_assert($result['stderr'] === '', 'Email preflight emitted unexpected diagnostics.');
        $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $wanted = $arguments === [] ? $expected : $expected + ['user_id_groups' => $groups];
        email_preflight_assert($decoded === $wanted, 'Email preflight returned incorrect collision groups or disclosed extra fields.');
        if ($arguments === []) {
            email_preflight_assert($first === null || $first === $result, 'Repeated preflight changed its result.');
            $first = $result;
        }
        email_preflight_assert(email_preflight_snapshot($pdo) === $before, 'Email preflight changed fixture schema, identities, permissions, or migration state.');
        email_preflight_assert(!$pdo->inTransaction(), 'Email preflight left an active fixture transaction.');
    }
}

function email_preflight_migration_rejects(PDO $pdo, SqlMigrationRunner $runner): void
{
    $users = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    try {
        $runner->run();
        throw new RuntimeException('Conflicting identities unexpectedly passed migration.');
    } catch (Throwable $failure) {
        email_preflight_assert(
            str_contains($failure->getMessage(), SqlMigrationRunner::ERROR_MIGRATION)
                || str_contains($failure->getMessage(), 'UNIQUE'),
            'Migration did not fail through its existing unique-identity protection.',
        );
    }
    email_preflight_assert($pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $users, 'Failed migration partially normalized or changed legacy accounts.');
    email_preflight_assert(!$pdo->inTransaction(), 'Failed migration left a transaction open.');
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $indexCount = $driver === 'sqlite'
        ? $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'users_email_normalized'")->fetchColumn()
        : $pdo->query("SELECT COUNT(*) FROM pg_catalog.pg_indexes WHERE schemaname = current_schema() AND indexname = 'users_email_normalized'")->fetchColumn();
    email_preflight_assert((int) $indexCount === 0, 'Failed migration retained a partial normalized-email index.');
    $registryExists = $driver === 'sqlite'
        ? $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'audit_migrations'")->fetchColumn()
        : $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'audit_migrations'")->fetchColumn();
    email_preflight_assert(!$registryExists || (int) $pdo->query('SELECT COUNT(*) FROM audit_migrations')->fetchColumn() === 0, 'Failed migration was recorded as applied.');
}

$pdo = null;
$schema = null;
$originalPgOptions = getenv('PGOPTIONS');
try {
    $pdo = Database::connection();
    if ($postgres) {
        // PGOPTIONS applies the isolated schema to libpq connections opened by the CLI.
        $schema = 'email_preflight_' . bin2hex(random_bytes(6));
        $pdo->exec('CREATE SCHEMA ' . $schema);
        $pdo->exec('SET search_path TO ' . $schema);
        putenv('PGOPTIONS=' . ($originalPgOptions === false ? '' : $originalPgOptions . ' ') . '-c search_path=' . $schema);
    }
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
        password_hash TEXT NOT NULL, role TEXT NOT NULL, active INTEGER NOT NULL, scope_value TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE role_capabilities (role_key TEXT NOT NULL, capability TEXT NOT NULL)');
    $pdo->exec("INSERT INTO role_capabilities VALUES ('private-role-sentinel', 'private-capability-sentinel')");
    $pdo->exec('CREATE TABLE migrations (migration TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
    $pdo->exec("INSERT INTO migrations VALUES ('legacy-migration-sentinel.sql', '2026-01-01')");
    $pdo->exec('CREATE TABLE cpe_database_ownership (owner TEXT NOT NULL)');
    $pdo->exec("INSERT INTO cpe_database_ownership VALUES ('private-owner-sentinel')");
    $hookMarker = $preflightRoot . '/platform-hook-ran';
    file_put_contents(
        $preflightRoot . '/platform-bootstrap.php',
        '<?php file_put_contents(' . var_export($hookMarker, true) . ', "platform hook ran"); throw new RuntimeException("private-platform-sentinel");',
    );
    putenv('CPE_PLATFORM_BOOTSTRAP=' . $preflightRoot . '/platform-bootstrap.php');

    email_preflight_seed($pdo, []);
    email_preflight_expect($pdo, 0, 0, []);
    if (!$postgres) {
        email_preflight_assert(strtolower((string) $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn()) === 'wal', 'Could not enable live WAL fixture.');
        $pdo->exec('PRAGMA wal_autocheckpoint = 0');
    }
    email_preflight_seed($pdo, [1 => 'CasePrivate@Example.Test', 2 => 'caseprivate@example.test']);
    if (!$postgres) {
        $wal = (string) getenv('CPE_DB_PATH') . '-wal';
        clearstatcache(true, $wal);
        email_preflight_assert(is_file($wal) && filesize($wal) > 32, 'Collision fixture was not written into the live WAL.');
    }
    email_preflight_expect($pdo, 1, 2, [[1, 2]]);
    email_preflight_seed($pdo, [3 => ' spaceprivate@example.test ', 8 => 'spaceprivate@example.test']);
    email_preflight_expect($pdo, 1, 2, [[3, 8]]);
    email_preflight_seed($pdo, [
        12 => 'ZuluPrivate@Example.Test', 5 => ' zuluprivate@example.test ',
        9 => 'alphaprivate@example.test', 2 => 'AlphaPrivate@Example.Test', 7 => ' alphaprivate@example.test ',
        14 => 'CleanPrivate@Example.Test',
    ]);
    email_preflight_expect($pdo, 2, 5, [[2, 7, 9], [5, 12]]);
    email_preflight_seed($pdo, [
        1 => 'cleanprivate@example.test', 2 => 'otherprivate@example.test',
        3 => "\tcleanprivate@example.test\t", 4 => "\ncleanprivate@example.test\n",
    ]);
    email_preflight_expect($pdo, 0, 0, []);

    // A bypassed preflight and a clean preflight made stale by a later writer
    // must both retain the shipped migration's automatic fail-closed behavior.
    $migrationDir = $preflightRoot . '/migration';
    mkdir($migrationDir, 0700);
    $migrationName = $postgres ? '019_user_email_normalization.sql' : '055_user_email_normalization.sql';
    copy(cpe_path('database/migrations/' . ($postgres ? 'pgsql/' : '') . $migrationName), $migrationDir . '/' . $migrationName);
    $runner = new SqlMigrationRunner($pdo, 'audit_migrations', $migrationDir, 'cpe.email-preflight-contract');
    email_preflight_seed($pdo, [1 => 'MigrationPrivate@Example.Test', 2 => 'migrationprivate@example.test']);
    email_preflight_migration_rejects($pdo, $runner);
    email_preflight_seed($pdo, [1 => 'MigrationPrivate@Example.Test']);
    email_preflight_expect($pdo, 0, 0, []);
    $pdo->exec("INSERT INTO users SELECT 2, ' migrationprivate@example.test ', name, password_hash, role, active, scope_value FROM users WHERE id = 1");
    email_preflight_migration_rejects($pdo, $runner);
    email_preflight_expect($pdo, 1, 2, [[1, 2]]);
    $pdo->exec("UPDATE users SET email = ' OtherMigrationPrivate@Example.Test ' WHERE id = 2");
    $runner->run();
    email_preflight_assert($pdo->query('SELECT email FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['migrationprivate@example.test', 'othermigrationprivate@example.test'], 'Resolved migration did not normalize the accounts.');
    email_preflight_expect($pdo, 0, 0, []);
    try {
        $pdo->exec("INSERT INTO users SELECT 3, ' MIGRATIONPRIVATE@EXAMPLE.TEST ', name, password_hash, role, active, scope_value FROM users WHERE id = 1");
        throw new RuntimeException('Normalized uniqueness was weakened.');
    } catch (PDOException) {
        // The original unique expression index remains the final enforcement boundary.
    }

    // Query/schema errors must be opaque and must not become ordinary log entries.
    $pdo->exec('ALTER TABLE users RENAME COLUMN email TO private_email_column_sentinel');
    $before = email_preflight_snapshot($pdo);
    foreach ([[], ['--show-user-ids']] as $arguments) {
        $result = email_preflight_cli($arguments);
        email_preflight_assert($result['code'] === 1 && $result['stdout'] === '', 'Malformed schema was reported as a successful collision check.');
        email_preflight_assert($result['stderr'] !== '', 'Failed check omitted its safe error message.');
        email_preflight_assert(preg_match('/@|SQLSTATE|private_|password|SELECT|pgsql:|sqlite:/i', $result['stderr']) !== 1, 'Failure output disclosed identity or database diagnostics.');
        email_preflight_assert(email_preflight_snapshot($pdo) === $before, 'Failed check modified the database.');
    }
    email_preflight_assert(!file_exists($preflightRoot . '/structured.log') || file_get_contents($preflightRoot . '/structured.log') === '', 'Preflight emitted ordinary structured logs.');
    email_preflight_assert(!file_exists($hookMarker), 'Read-only preflight executed a platform bootstrap hook.');

    $before = email_preflight_snapshot($pdo);
    foreach ([['--show-user-ids=1'], ['--unknown-private-argument'], ['private-argument@example.test'], ['--show-user-ids', '--show-user-ids']] as $arguments) {
        $result = email_preflight_cli($arguments);
        email_preflight_assert($result['code'] === 1 && $result['stdout'] === '', 'Invalid preflight arguments were accepted.');
        email_preflight_assert($result['stderr'] !== '' && !str_contains($result['stderr'], 'private-'), 'Invalid argument output echoed private input.');
    }
    $result = email_preflight_cli(['--help']);
    email_preflight_assert($result['code'] === 0 && $result['stderr'] === '' && str_contains($result['stdout'], '--show-user-ids'), 'Preflight help is unavailable.');
    email_preflight_assert(email_preflight_snapshot($pdo) === $before, 'Help or invalid preflight arguments accessed or modified the malformed database.');
    email_preflight_assert(!file_exists($hookMarker), 'Preflight help or argument errors executed a platform hook.');
    email_preflight_assert(!file_exists($preflightRoot . '/structured.log') || file_get_contents($preflightRoot . '/structured.log') === '', 'Preflight argument errors emitted ordinary structured logs.');

    if (!$postgres) {
        Database::reset();
        $pdo = null;
        $missing = $preflightRoot . '/missing-parent/missing.sqlite';
        putenv('CPE_DB_PATH=' . $missing);
        $result = email_preflight_cli();
        email_preflight_assert($result['code'] === 1 && $result['stdout'] === '', 'Missing SQLite target was treated as clean.');
        email_preflight_assert(!file_exists(dirname($missing)), 'Read-only preflight created the missing database or its parent.');
    }
    echo 'PASS email identity preflight contract (' . ($postgres ? 'pgsql' : 'sqlite') . ").\n";
} finally {
    if ($postgres && $pdo instanceof PDO && $schema !== null) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET search_path TO public');
        $pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
    }
    putenv($originalPgOptions === false ? 'PGOPTIONS' : 'PGOPTIONS=' . $originalPgOptions);
    Database::reset();
    $pdo = null;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($preflightRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($preflightRoot);
}
