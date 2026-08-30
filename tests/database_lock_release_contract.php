<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/authorized_setup_recovery_fixture.php';

use App\Core\Persistence\DatabaseLock;
use App\Core\Persistence\DatabaseLockException;
use App\Install\InstallationStepObserver;
use App\Install\Installer;
use App\Support\Database;

function lock_release_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function lock_release_backend_pid(PDO $pdo): string
{
    $pid = trim((string) $pdo->query('SELECT pg_backend_pid()::text')->fetchColumn());
    lock_release_assert(preg_match('/\A[0-9]+\z/D', $pid) === 1, 'PostgreSQL backend PID is unavailable.');
    return $pid;
}

/** @param callable(): mixed $operation */
function lock_release_expect(callable $operation, string $label): DatabaseLockException
{
    try {
        $operation();
    } catch (DatabaseLockException $e) {
        lock_release_assert(
            $e->failureCode() === DatabaseLock::ERROR_RELEASE && $e->requiresConnectionReset(),
            $label . ' did not return the typed checked-release failure.',
        );
        return $e;
    }
    throw new RuntimeException($label . ' did not fail its checked advisory unlock.');
}

function lock_release_apply_legacy_postgres(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE migrations ('
        . 'id BIGSERIAL PRIMARY KEY, migration TEXT NOT NULL UNIQUE, applied_at TEXT NOT NULL)',
    );
    for ($number = 1; $number <= 5; $number++) {
        $pattern = sprintf('%03d_*.sql', $number);
        $matches = glob(__DIR__ . '/../database/migrations/pgsql/' . $pattern) ?: [];
        lock_release_assert(count($matches) === 1, 'PostgreSQL legacy migration fixture is incomplete.');
        $file = $matches[0];
        $sql = file_get_contents($file);
        lock_release_assert(is_string($sql), 'Could not read PostgreSQL legacy migration fixture.');
        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $record = $pdo->prepare(
                'INSERT INTO migrations (migration, applied_at) VALUES (?, ?) ON CONFLICT(migration) DO NOTHING',
            );
            $record->execute([basename($file), '2026-01-01 00:00:00']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    $pdo->prepare(
        'INSERT INTO institutions (public_id, slug, name, timezone, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?)',
    )->execute([
        'inst_' . str_repeat('1', 32),
        'default',
        'Lock Release Contract College',
        'UTC',
        '2026-01-01 00:00:00',
        '2026-01-01 00:00:00',
    ]);
}

final class LockReleaseInstallationObserver implements InstallationStepObserver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function observe(string $stage): void
    {
        if ($stage === InstallationStepObserver::AFTER_INSTALL_AUDIT) {
            $this->pdo->query('SELECT pg_advisory_unlock_all()')->fetchColumn();
        }
    }
}

$rawSentinel = 'raw-lock-release-sentinel@example.test';
$typedRelease = DatabaseLockException::releaseFailed(new RuntimeException($rawSentinel));
lock_release_assert($typedRelease->failureCode() === DatabaseLock::ERROR_RELEASE, 'Release failure code changed.');
lock_release_assert($typedRelease->requiresConnectionReset(), 'Release failure must require connection reset.');
lock_release_assert(!str_contains($typedRelease->getMessage(), $rawSentinel), 'Typed release failure exposed its raw cause.');
$typedSession = DatabaseLockException::sessionChanged(new RuntimeException($rawSentinel));
lock_release_assert($typedSession->failureCode() === DatabaseLock::ERROR_SESSION_CHANGED, 'Session failure code changed.');
lock_release_assert(!str_contains($typedSession->getMessage(), $rawSentinel), 'Typed session failure exposed its raw cause.');
$wrappedSession = new RuntimeException('fixed wrapper', 0, $typedSession);
lock_release_assert(
    DatabaseLockException::find($wrappedSession) === $typedSession,
    'Typed session failure was lost through a fixed causal wrapper.',
);

foreach ([
    'app/Core/Persistence/DatabaseLock.php' => ['DatabaseLockException::releaseFailed', 'DatabaseLockException::sessionChanged'],
    'app/Core/Persistence/DatabaseOwnership.php' => ['DatabaseLockException::sessionChanged'],
    'app/Core/Persistence/SqlMigrationRunner.php' => ['DatabaseLockException::sessionChanged'],
    'app/Support/Database.php' => ['DatabaseLockException::find($e)', 'self::reset()'],
    'app/Install/Installer.php' => ['DatabaseLockException::find($e)', 'Database::reset()'],
] as $path => $needles) {
    $source = (string) file_get_contents(__DIR__ . '/../' . $path);
    foreach ($needles as $needle) {
        lock_release_assert(str_contains($source, $needle), $path . ' is missing typed lock cleanup handling.');
    }
}

$databaseUrl = trim((string) (getenv('CPE_DATABASE_URL') ?: ''));
if ($databaseUrl === '') {
    echo "PASS database lock failures are typed and reset-aware (static/SQLite companion)\n";
    echo "SKIP database lock checked-release fault contract (pgsql): CPE_DATABASE_URL is not configured.\n";
    exit(0);
}

try {
    $pdo = Database::connection();
    lock_release_assert(
        strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql',
        'Checked-release fault contract requires PostgreSQL.',
    );
    lock_release_assert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()',
        )->fetchColumn() === 0,
        'Checked-release fault contract requires a fresh dedicated PostgreSQL database.',
    );

    $pdo->exec(
        "CREATE FUNCTION cpe_test_unlock_ownership() RETURNS event_trigger LANGUAGE plpgsql AS \$\$ "
        . 'BEGIN PERFORM pg_advisory_unlock_all(); END; ' . "\$\$",
    );
    $pdo->exec(
        "CREATE EVENT TRIGGER cpe_test_unlock_ownership ON ddl_command_end "
        . "WHEN TAG IN ('CREATE TABLE') EXECUTE FUNCTION cpe_test_unlock_ownership()",
    );
    $ownershipPid = lock_release_backend_pid($pdo);
    lock_release_expect(
        static fn () => Database::migrate(false),
        'Database ownership lock',
    );
    $afterOwnership = Database::connection();
    lock_release_assert(
        lock_release_backend_pid($afterOwnership) !== $ownershipPid,
        'Ownership lock release failure reused the cached PostgreSQL backend.',
    );
    lock_release_assert(
        (int) $afterOwnership->query('SELECT COUNT(*) FROM cpe_database_ownership')->fetchColumn() === 1,
        'Ownership claim did not retain its committed evidence.',
    );
    lock_release_assert(
        (int) $afterOwnership->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'migrations'",
        )->fetchColumn() === 0,
        'Migration work began after the ownership lock release became uncertain.',
    );
    $afterOwnership->exec('DROP EVENT TRIGGER cpe_test_unlock_ownership');
    $afterOwnership->exec('DROP FUNCTION cpe_test_unlock_ownership()');

    lock_release_apply_legacy_postgres($afterOwnership);
    $afterOwnership->exec(
        "CREATE FUNCTION cpe_test_unlock_migration() RETURNS trigger LANGUAGE plpgsql AS \$\$ "
        . 'BEGIN PERFORM pg_advisory_unlock_all(); RETURN NULL; END; ' . "\$\$",
    );
    $afterOwnership->exec(
        'CREATE TRIGGER cpe_test_unlock_migration BEFORE UPDATE ON institutions '
        . 'FOR EACH STATEMENT EXECUTE FUNCTION cpe_test_unlock_migration()',
    );
    $migrationPid = lock_release_backend_pid($afterOwnership);
    lock_release_expect(
        static fn () => Database::migrate(false),
        'Migration lock',
    );
    $afterMigration = Database::connection();
    lock_release_assert(
        lock_release_backend_pid($afterMigration) !== $migrationPid,
        'Migration lock release failure reused the cached PostgreSQL backend.',
    );
    $expectedMigrationNames = array_map(
        'basename',
        glob(__DIR__ . '/../database/migrations/pgsql/*.sql') ?: [],
    );
    sort($expectedMigrationNames, SORT_STRING);
    $recordedMigrationNames = $afterMigration->query(
        'SELECT migration FROM migrations ORDER BY id',
    )->fetchAll(PDO::FETCH_COLUMN);
    lock_release_assert(
        $recordedMigrationNames === $expectedMigrationNames,
        'Migration release fault did not preserve the exact completed migration registry truthfully.',
    );
    $afterMigration->exec('DROP TRIGGER cpe_test_unlock_migration ON institutions');
    $afterMigration->exec('DROP FUNCTION cpe_test_unlock_migration()');

    $tenantPublicId = 'tenant_' . str_repeat('9', 32);
    $recoveryAuthority = test_authorized_setup_recovery_authority();
    $installationPid = lock_release_backend_pid($afterMigration);
    lock_release_expect(
        static fn () => (new Installer(new LockReleaseInstallationObserver($afterMigration)))->installHosted([
            'college_name' => 'Checked Release College',
            'timezone' => 'UTC',
            'admin_name' => 'Checked Release Administrator',
            'admin_email' => 'checked-release@example.test',
            'admin_password' => 'checked-release-password-123',
            'seed_demo' => '1',
        ], $tenantPublicId, $recoveryAuthority),
        'Installation lock',
    );
    $afterInstallation = Database::connection();
    lock_release_assert(
        lock_release_backend_pid($afterInstallation) !== $installationPid,
        'Installation lock release failure reused the cached PostgreSQL backend.',
    );
    lock_release_assert(Database::isInstalled(), 'Installation did not commit before its checked unlock failed.');
    lock_release_assert(
        (string) $afterInstallation->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn() === $tenantPublicId,
        'Installation release fault lost the committed tenant identity.',
    );
    lock_release_assert(
        (int) $afterInstallation->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'install'")->fetchColumn() === 1,
        'Installation release fault did not retain exactly one install audit.',
    );
    lock_release_assert(
        (int) $afterInstallation->query("SELECT COUNT(*) FROM users WHERE email = 'checked-release@example.test'")->fetchColumn() === 1,
        'Installation release fault did not retain exactly one administrator.',
    );

    echo "PASS PostgreSQL ownership, migration, and installation checked-release faults discard cached sessions\n";
} finally {
    Database::reset();
}
