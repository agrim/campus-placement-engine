<?php

declare(strict_types=1);

$cleanupDatabaseUrl = trim((string) (getenv('CPE_DATABASE_URL') ?: ''));
$cleanupSqlitePath = null;
if ($cleanupDatabaseUrl === '') {
    $cleanupSqlitePath = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
        . '/cpe-connection-cleanup-' . bin2hex(random_bytes(6)) . '.sqlite';
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DB_PATH=' . $cleanupSqlitePath);
}

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Persistence\ConnectionProvider;
use App\Core\Persistence\DatabaseConnectionInvalidException;
use App\Core\Persistence\DatabaseOwnership;
use App\Core\Persistence\SqlMigrationRunner;
use App\Support\Database;

function cleanup_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class CleanupFaultPdo extends PDO
{
    public function __construct(
        string $dsn,
        ?string $username,
        ?string $password,
        array $options,
        public bool $failOwnership,
        public bool $failMigration,
    ) {
        parent::__construct($dsn, $username, $password, $options);
    }

    public function exec(string $statement): int|false
    {
        if (($this->failOwnership || $this->failMigration)
            && strtoupper(trim($statement)) === 'ROLLBACK') {
            throw new RuntimeException('cleanup rollback fault sentinel');
        }
        if ($this->failOwnership
            && str_contains($statement, 'CREATE TABLE')
            && str_contains($statement, 'cpe_database_ownership')) {
            throw new RuntimeException('primary ownership fault sentinel');
        }
        if ($this->failMigration
            && str_contains($statement, 'CREATE TABLE')
            && str_contains($statement, 'settings')) {
            throw new RuntimeException('primary migration fault sentinel');
        }
        return parent::exec($statement);
    }

    public function rollBack(): bool
    {
        if ($this->failOwnership || $this->failMigration) {
            throw new RuntimeException('cleanup rollback fault sentinel');
        }
        return parent::rollBack();
    }
}

final class CleanupSpyProvider implements ConnectionProvider
{
    public int $disconnects = 0;

    public function __construct(
        private ?CleanupFaultPdo $pdo,
        private readonly string $driverName,
        private readonly string $safeIdentifier,
    ) {
    }

    public function connection(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('Discarded cleanup provider was reused.');
        }
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driverName;
    }

    public function identifier(): string
    {
        return $this->safeIdentifier;
    }

    public function disconnect(): void
    {
        $this->disconnects++;
        $this->pdo = null;
    }

    public function backendPid(): ?string
    {
        if ($this->driverName !== 'pgsql' || $this->pdo === null) {
            return null;
        }
        return trim((string) $this->pdo->query('SELECT pg_backend_pid()::text')->fetchColumn());
    }
}

/** @return CleanupFaultPdo */
function cleanup_fault_pdo(bool $failOwnership, bool $failMigration): CleanupFaultPdo
{
    $databaseUrl = trim((string) (getenv('CPE_DATABASE_URL') ?: ''));
    if ($databaseUrl === '') {
        $path = (string) getenv('CPE_DB_PATH');
        $pdo = new CleanupFaultPdo(
            'sqlite:' . $path,
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
            $failOwnership,
            $failMigration,
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }
    $parts = parse_url($databaseUrl);
    cleanup_assert(is_array($parts), 'PostgreSQL cleanup URL is invalid.');
    parse_str((string) ($parts['query'] ?? ''), $query);
    $database = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
    $username = rawurldecode((string) ($parts['user'] ?? ''));
    $password = rawurldecode((string) ($parts['pass'] ?? ''));
    $host = (string) ($parts['host'] ?? '127.0.0.1');
    $port = (int) ($parts['port'] ?? 5432);
    $sslMode = (string) ($query['sslmode'] ?? 'prefer');
    $pdo = new CleanupFaultPdo(
        "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslMode}",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
        $failOwnership,
        $failMigration,
    );
    $pdo->exec("SET TIME ZONE 'UTC'");
    return $pdo;
}

/** @return DatabaseConnectionInvalidException */
function cleanup_expect_invalid(callable $operation, string $code, string $label): DatabaseConnectionInvalidException
{
    try {
        $operation();
    } catch (Throwable $failure) {
        $e = DatabaseConnectionInvalidException::find($failure);
        cleanup_assert($e !== null, $label . ' did not retain the typed cleanup failure.');
        cleanup_assert($e->failureCode() === $code, $label . ' used the wrong fixed code.');
        cleanup_assert($e->requiresConnectionReset(), $label . ' did not require a connection reset.');
        cleanup_assert($e->getPrevious() instanceof Throwable, $label . ' lost the primary cause.');
        cleanup_assert(
            str_contains($e->cleanupCause()->getMessage(), 'cleanup rollback fault sentinel'),
            $label . ' lost the cleanup cause.',
        );
        cleanup_assert(!str_contains($e->getMessage(), 'sentinel'), $label . ' exposed a raw cause.');
        return $e;
    }
    throw new RuntimeException($label . ' did not produce a typed invalid-connection failure.');
}

try {
    $driver = Database::driver();
    $initial = Database::connection();
    if ($driver === 'pgsql') {
        cleanup_assert(
            (int) $initial->query(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()',
            )->fetchColumn() === 0,
            'PostgreSQL connection cleanup contract requires a fresh dedicated database.',
        );
    }
    Database::reset();

    $ownershipProvider = new CleanupSpyProvider(
        cleanup_fault_pdo(true, false),
        $driver,
        $driver === 'sqlite' ? (string) getenv('CPE_DB_PATH') : 'postgresql://cleanup-contract',
    );
    $ownershipPid = $ownershipProvider->backendPid();
    Database::useProvider($ownershipProvider);
    $ownershipFailure = cleanup_expect_invalid(
        static fn () => Database::migrate(false),
        DatabaseOwnership::ERROR_CLEANUP,
        'Ownership rollback cleanup failure',
    );
    cleanup_assert(
        str_contains((string) $ownershipFailure->getPrevious()?->getMessage(), 'primary ownership fault sentinel'),
        'Ownership cleanup lost its primary failure.',
    );
    cleanup_assert($ownershipProvider->disconnects === 1, 'Ownership cleanup did not discard the cached provider exactly once.');
    unset($ownershipFailure);
    gc_collect_cycles();
    $afterOwnership = Database::connection();
    if ($driver === 'pgsql') {
        $afterOwnershipPid = trim((string) $afterOwnership->query('SELECT pg_backend_pid()::text')->fetchColumn());
        cleanup_assert($afterOwnershipPid !== $ownershipPid, 'Ownership cleanup reused the invalid PostgreSQL backend.');
    }
    cleanup_assert(
        (int) ($driver === 'pgsql'
            ? $afterOwnership->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'cpe_database_ownership'",
            )->fetchColumn()
            : $afterOwnership->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cpe_database_ownership'",
            )->fetchColumn()) === 0,
        'Ownership cleanup failure committed partial ownership state.',
    );
    DatabaseOwnership::claimOrVerify($afterOwnership, DatabaseOwnership::OWNER_ENGINE_INSTITUTION);

    $migrationProvider = new CleanupSpyProvider(
        cleanup_fault_pdo(false, true),
        $driver,
        $driver === 'sqlite' ? (string) getenv('CPE_DB_PATH') : 'postgresql://cleanup-contract',
    );
    $migrationPid = $migrationProvider->backendPid();
    Database::useProvider($migrationProvider);
    $migrationFailure = cleanup_expect_invalid(
        static fn () => Database::migrate(false),
        SqlMigrationRunner::ERROR_CLEANUP,
        'Migration rollback cleanup failure',
    );
    cleanup_assert(
        str_contains((string) $migrationFailure->getPrevious()?->getMessage(), SqlMigrationRunner::ERROR_MIGRATION),
        'Migration cleanup lost its fixed primary migration failure.',
    );
    cleanup_assert($migrationProvider->disconnects === 1, 'Migration cleanup did not discard the cached provider exactly once.');
    unset($migrationFailure);
    gc_collect_cycles();
    $afterMigration = Database::connection();
    if ($driver === 'pgsql') {
        $afterMigrationPid = trim((string) $afterMigration->query('SELECT pg_backend_pid()::text')->fetchColumn());
        cleanup_assert($afterMigrationPid !== $migrationPid, 'Migration cleanup reused the invalid PostgreSQL backend.');
    }
    $settingsPresent = $driver === 'pgsql'
        ? (int) $afterMigration->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'settings'",
        )->fetchColumn()
        : (int) $afterMigration->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'settings'",
        )->fetchColumn();
    cleanup_assert($settingsPresent === 0, 'Migration cleanup failure committed partial product schema.');
    Database::migrate(false);
    cleanup_assert(
        $driver === 'pgsql'
            ? (int) Database::connection()->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'settings'",
            )->fetchColumn() === 1
            : (int) Database::connection()->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'settings'",
            )->fetchColumn() === 1,
        'Fresh connection could not complete migrations after cleanup failure.',
    );

    echo 'PASS typed ownership/migration cleanup discards invalid connections (' . $driver . ")\n";
} finally {
    Database::reset();
    if ($cleanupSqlitePath !== null) {
        foreach (glob($cleanupSqlitePath . '*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        putenv('CPE_DB_DRIVER');
        putenv('CPE_DB_PATH');
    }
}
