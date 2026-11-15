<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Persistence\DatabaseOwnership;
use App\Core\Persistence\SqlMigrationRunner;

function migration_writer_wait(callable $ready, string $message): void
{
    $deadline = microtime(true) + 5.0;
    while (!$ready()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(1000);
    }
}

function migration_writer_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Signal the first main-database write attempt, without changing its locking. */
final class MigrationWriterProbePDO extends PDO
{
    public bool $observe = false;

    public function __construct(string $path, private readonly string $barrier)
    {
        parent::__construct('sqlite:' . $path);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->exec('PRAGMA busy_timeout = 5000');
    }

    public function exec(string $statement): int|false
    {
        if ($this->observe
            && !is_file($this->barrier . '/migration-read')
            && preg_match('/^\s*(?:UPDATE|INSERT|DELETE|REPLACE)\b/i', $statement) === 1) {
            touch($this->barrier . '/write-before-read');
        }
        return parent::exec($statement);
    }
}

/** Hold the real ownership verifier's writer reservation at the overlap. */
final class MigrationOwnershipProbePDO extends PDO
{
    public function __construct(string $path, private readonly string $barrier)
    {
        parent::__construct('sqlite:' . $path);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->exec('PRAGMA busy_timeout = 5000');
    }

    public function exec(string $statement): int|false
    {
        $result = parent::exec($statement);
        if ($statement === 'BEGIN IMMEDIATE') {
            touch($this->barrier . '/ownership-reserved');
            // Let an early writer wait and then proceed. If the migration
            // already read, retain the reservation until its upgrade fails.
            migration_writer_wait(
                fn (): bool => is_file($this->barrier . '/write-before-read')
                    || is_file($this->barrier . '/migration-finished'),
                'Migration did not request its writer or finish.',
            );
        }
        return $result;
    }
}

if (($argv[1] ?? '') === '--ownership-worker') {
    $barrier = (string) ($argv[2] ?? '');
    $pdo = new MigrationOwnershipProbePDO($barrier . '/database.sqlite', $barrier);
    DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
    echo "PASS concurrent ownership verification\n";
    exit;
}

function migration_writer_remove_tree(string $path): void
{
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . '/' . $entry;
        is_dir($child) && !is_link($child)
            ? migration_writer_remove_tree($child)
            : unlink($child);
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/cpe-migration-writer-' . bin2hex(random_bytes(6));
mkdir($root . '/migrations', 0700, true);
$process = null;
$pipes = [];

try {
    $pdo = new MigrationWriterProbePDO($root . '/database.sqlite', $root);
    DatabaseOwnership::claimOrVerify($pdo, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);
    file_put_contents($root . '/migrations/001_fixture.sql',
        'CREATE TABLE migration_probe (value INTEGER NOT NULL); INSERT INTO migration_probe VALUES (0);');
    $runner = new SqlMigrationRunner($pdo, 'migration_registry', $root . '/migrations', 'migration.writer.contract');
    $runner->run();
    file_put_contents($root . '/migrations/002_reader_then_writer.sql',
        'SELECT value FROM migration_probe; SELECT migration_reader_barrier(); UPDATE migration_probe SET value = 1;');
    $pdo->sqliteCreateFunction('migration_reader_barrier', function () use ($root): int {
        touch($root . '/migration-read');
        return 1;
    });

    $process = proc_open(
        [PHP_BINARY, __FILE__, '--ownership-worker', $root],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the ownership verifier.');
    }
    fclose($pipes[0]);
    migration_writer_wait(fn (): bool => is_file($root . '/ownership-reserved'), 'Ownership verifier did not reserve its writer.');
    $pdo->observe = true;
    $failure = null;
    try {
        $runner->run(function (PDO $connection): void {
            migration_writer_assert(!$connection->inTransaction(), 'Migration callback ran inside a transaction.');
            migration_writer_assert(
                $connection->query('SELECT migration FROM migration_registry ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN)
                    === ['001_fixture.sql', '002_reader_then_writer.sql'],
                'Migration callback saw incomplete registry history.',
            );
        });
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        touch($root . '/migration-finished');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $process = null;
    migration_writer_assert($status === 0, 'Ownership verifier failed: ' . $stdout . $stderr);
    if ($failure !== null) {
        throw $failure;
    }
    migration_writer_assert((int) $pdo->query('SELECT value FROM migration_probe')->fetchColumn() === 1,
        'Migration did not commit its data change.');
    migration_writer_assert(!$pdo->inTransaction(), 'Migration left an open transaction.');
    $runner->run();
    migration_writer_assert((int) $pdo->query('SELECT COUNT(*) FROM migration_registry')->fetchColumn() === 2,
        'Migration replay changed the exactly-once registry.');
    echo "PASS SQLite migration reserves its writer before reads beside ownership verification\n";
} finally {
    touch($root . '/migration-finished');
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    unset($pdo, $runner);
    migration_writer_remove_tree($root);
}
