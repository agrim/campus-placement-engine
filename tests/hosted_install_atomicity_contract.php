<?php

declare(strict_types=1);

$atomicTemporarySqlite = null;
$atomicTempDirectory = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
$atomicLogPath = $atomicTempDirectory . '/cpe-hosted-install-atomicity-' . bin2hex(random_bytes(6)) . '.log';
putenv('CPE_LOG_PATH=' . $atomicLogPath);
if (trim((string) (getenv('CPE_DATABASE_URL') ?: '')) === ''
    && !in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true)) {
    $atomicTemporarySqlite = $atomicTempDirectory . '/cpe-hosted-install-atomicity-' . bin2hex(random_bytes(6)) . '.sqlite';
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DB_PATH=' . $atomicTemporarySqlite);
}

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/authorized_setup_recovery_fixture.php';

use App\Hosted\HostedBootstrap;
use App\Hosted\Tenant\HostedResolutionException;
use App\Core\Http\UserVisibleException;
use App\Core\Persistence\TransactionRollbackGuard;
use App\Install\InstallationStepObserver;
use App\Install\Installer;
use App\Support\Database;

function hosted_atomicity_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function hosted_atomicity_row(PDO $pdo, string $sql): array
{
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    hosted_atomicity_assert(is_array($row), 'Expected database row is missing.');
    ksort($row);
    return $row;
}

/** @return list<string> */
function hosted_atomicity_tables(PDO $pdo): array
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'pgsql') {
        $tables = $pdo->query(
            "SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename",
        )->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        )->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_values(array_filter(
        array_map('strval', $tables),
        static fn (string $table): bool => !in_array($table, ['migrations', 'database_ownership'], true),
    ));
}

/** @return array<string, list<array<string, mixed>>> */
function hosted_atomicity_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach (hosted_atomicity_tables($pdo) as $table) {
        hosted_atomicity_assert(preg_match('/\A[a-z_]+\z/D', $table) === 1, 'Unexpected table identifier.');
        $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            ksort($row);
        }
        unset($row);
        usort($rows, static fn (array $left, array $right): int => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR),
            json_encode($right, JSON_THROW_ON_ERROR),
        ));
        $snapshot[$table] = $rows;
    }
    return $snapshot;
}

final class HostedAtomicityThrowingObserver implements InstallationStepObserver
{
    /** @var list<string> */
    public array $observedStages = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $throwAtStage,
    ) {
    }

    public function observe(string $stage): void
    {
        hosted_atomicity_assert($this->pdo->inTransaction(), 'Installation observer ran outside the install transaction.');
        hosted_atomicity_assert(in_array($stage, InstallationStepObserver::STAGES, true), 'Installation observer received an unreviewed stage.');
        $this->observedStages[] = $stage;
        if (hash_equals($this->throwAtStage, $stage)) {
            throw new RuntimeException('CPE_TEST_INSTALL_STAGE_INTERRUPTED');
        }
    }
}

final class HostedAtomicityRecordingObserver implements InstallationStepObserver
{
    /** @var list<string> */
    public array $observedStages = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function observe(string $stage): void
    {
        hosted_atomicity_assert($this->pdo->inTransaction(), 'Successful installation observer ran outside the install transaction.');
        $this->observedStages[] = $stage;
    }
}

final class HostedAtomicityRollbackFailurePdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function inTransaction(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        throw new RuntimeException('rollback-failure-must-not-be-public');
    }
}

/** @return array<string, mixed> */
function hosted_atomicity_input(): array
{
    return [
        'college_name' => 'Atomic Hosted College',
        'site_name' => 'Atomic Hosted Placement Desk',
        'site_tagline' => 'Atomic hosted installation',
        'timezone' => 'UTC',
        'cycle_name' => 'Atomic Hosted Cycle',
        'workflow' => 'default',
        'terminology_candidate_label' => 'Applicant',
        'admin_name' => 'Atomic Hosted Administrator',
        'admin_email' => 'atomic-hosted-admin@example.test',
        'admin_password' => 'atomic-hosted-contract-password-123',
        'seed_demo' => '1',
    ];
}

try {
    $pdo = Database::connection();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'pgsql') {
        $existingTables = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()',
        )->fetchColumn();
        hosted_atomicity_assert(
            $existingTables === 0,
            'PostgreSQL hosted install atomicity contract requires a fresh dedicated database/schema.',
        );
    }

    hosted_atomicity_assert(!Database::isInstalled(), 'Hosted install atomicity contract requires an uninstalled database.');
    Database::migrate();
    $recoveryAuthority = test_authorized_setup_recovery_authority();
    $tenantPublicId = 'tenant_' . str_repeat('c', 32);
    $wrongTenantPublicId = 'tenant_' . str_repeat('d', 32);
    $input = hosted_atomicity_input();
    $baselineInstitution = hosted_atomicity_row(
        $pdo,
        "SELECT * FROM institutions WHERE slug = 'default'",
    );
    hosted_atomicity_assert(
        str_starts_with((string) ($baselineInstitution['public_id'] ?? ''), 'unbound_'),
        'Migrated hosted data plane does not have a reserved unbound identity.',
    );
    $baselineState = hosted_atomicity_snapshot($pdo);
    $observerMethod = new ReflectionMethod(InstallationStepObserver::class, 'observe');
    hosted_atomicity_assert(
        $observerMethod->getNumberOfParameters() === 1
            && (string) $observerMethod->getParameters()[0]->getType() === 'string',
        'Installation observer must receive only the fixed stage name.',
    );
    $hostedInstallMethod = new ReflectionMethod(Installer::class, 'installHosted');
    hosted_atomicity_assert(
        Installer::HOSTED_INSTALL_CONTRACT_VERSION === 1
            && $hostedInstallMethod->getNumberOfParameters() === 3
            && $hostedInstallMethod->getNumberOfRequiredParameters() === 2,
        'Hosted install public version 1 required-argument contract changed.',
    );
    $installerSource = (string) file_get_contents(__DIR__ . '/../app/Install/Installer.php');
    hosted_atomicity_assert(
        str_contains($installerSource, 'TransactionRollbackGuard::rollbackOrRethrow')
            && str_contains($installerSource, "'installation'"),
        'Installer does not use the guarded installation rollback boundary.',
    );
    $rollbackPdo = new HostedAtomicityRollbackFailurePdo();
    $rollbackPrimary = new RuntimeException('observer-primary-must-not-be-public');
    try {
        TransactionRollbackGuard::rollbackOrRethrow(
            $rollbackPdo,
            $rollbackPrimary,
            'installation',
            false,
        );
        throw new RuntimeException('Expected an explicit rollback-uncertain failure.');
    } catch (UserVisibleException $e) {
        hosted_atomicity_assert(
            $e->publicCode() === TransactionRollbackGuard::ERROR_ROLLBACK_UNCERTAIN,
            'Installation rollback uncertainty used the wrong fixed code.',
        );
        hosted_atomicity_assert(
            $e->getPrevious() === $rollbackPrimary,
            'Installation rollback uncertainty did not preserve primary causality.',
        );
        hosted_atomicity_assert(
            !str_contains($e->publicMessage(), 'rollback-failure-must-not-be-public')
                && !str_contains($e->publicMessage(), 'observer-primary-must-not-be-public'),
            'Installation rollback uncertainty exposed a raw failure.',
        );
        hosted_atomicity_assert(
            preg_match('/Reference: inc_[a-f0-9]{32}\z/D', $e->publicMessage()) === 1,
            'Installation rollback uncertainty did not include safe incident correlation.',
        );
        preg_match('/(inc_[a-f0-9]{32})\z/D', $e->publicMessage(), $incidentMatch);
        $protectedLog = is_file($atomicLogPath) ? (string) file_get_contents($atomicLogPath) : '';
        hosted_atomicity_assert(
            isset($incidentMatch[1])
                && str_contains($protectedLog, $incidentMatch[1])
                && str_contains($protectedLog, 'CPE_INSTALLATION_ROLLBACK_FAILED')
                && str_contains($protectedLog, '"operation":"installation"'),
            'Installation rollback uncertainty did not correlate to its protected fixed-code record.',
        );
        hosted_atomicity_assert(
            !str_contains($protectedLog, 'rollback-failure-must-not-be-public')
                && !str_contains($protectedLog, 'observer-primary-must-not-be-public'),
            'Installation rollback incident record exposed a raw failure.',
        );
        hosted_atomicity_assert(
            $rollbackPdo instanceof PDO,
            'Connection discard must be deferred until the installation lock releases.',
        );
    }

    foreach (InstallationStepObserver::STAGES as $stage) {
        $observer = new HostedAtomicityThrowingObserver($pdo, $stage);
        try {
            (new Installer($observer))->installHosted($input, $tenantPublicId, $recoveryAuthority);
            throw new RuntimeException('Injected installation interruption did not stop stage ' . $stage . '.');
        } catch (RuntimeException $e) {
            hosted_atomicity_assert(
                $e->getMessage() === 'CPE_TEST_INSTALL_STAGE_INTERRUPTED',
                'Injected installation stage returned an unexpected failure at ' . $stage . '.',
            );
        }
        hosted_atomicity_assert(
            in_array($stage, $observer->observedStages, true),
            'Installation did not invoke the requested durable stage ' . $stage . '.',
        );
        hosted_atomicity_assert(!$pdo->inTransaction(), 'Failed installation left a transaction open at ' . $stage . '.');
        hosted_atomicity_assert(!Database::isInstalled(), 'Failed installation left the database installed at ' . $stage . '.');
        hosted_atomicity_assert(
            hosted_atomicity_row($pdo, "SELECT * FROM institutions WHERE slug = 'default'") === $baselineInstitution,
            'Failed installation changed the reserved institution row at ' . $stage . '.',
        );
        hosted_atomicity_assert(
            hosted_atomicity_snapshot($pdo) === $baselineState,
            'Failed installation left durable settings, admin, seed, audit, synchronizer, or product state at ' . $stage . '.',
        );
        hosted_atomicity_assert(
            (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key = 'installed_at'")->fetchColumn() === 0,
            'Failed installation left an installed marker at ' . $stage . '.',
        );
        hosted_atomicity_assert(
            (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'atomic-hosted-admin@example.test'")->fetchColumn() === 0,
            'Failed installation left its administrator at ' . $stage . '.',
        );
        hosted_atomicity_assert(
            (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'install'")->fetchColumn() === 0,
            'Failed installation left its audit row at ' . $stage . '.',
        );
        hosted_atomicity_assert(
            (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn() === 0,
            'Failed installation left demo candidates at ' . $stage . '.',
        );
    }

    $recordingObserver = new HostedAtomicityRecordingObserver($pdo);
    $adminId = (new Installer($recordingObserver))->installHosted($input, $tenantPublicId, $recoveryAuthority);
    hosted_atomicity_assert(
        $recordingObserver->observedStages === InstallationStepObserver::STAGES,
        'Successful installation did not observe every reviewed stage in order.',
    );
    hosted_atomicity_assert(Database::isInstalled(), 'Retry after injected failures did not complete installation.');
    HostedBootstrap::assertDataPlaneIdentity($tenantPublicId);
    hosted_atomicity_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn() > 0,
        'Successful hosted retry did not commit demo seed data.',
    );
    hosted_atomicity_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM users WHERE id = ' . (int) $adminId)->fetchColumn() === 1,
        'Successful hosted retry did not commit its administrator.',
    );
    hosted_atomicity_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'install'")->fetchColumn() === 1,
        'Successful hosted retry did not commit exactly one installation audit.',
    );

    $installedState = hosted_atomicity_snapshot($pdo);
    hosted_atomicity_assert(Database::isInstalled(), 'Uncertain-success retry preflight did not observe installed state.');
    HostedBootstrap::assertDataPlaneIdentity($tenantPublicId);
    hosted_atomicity_assert(
        hosted_atomicity_snapshot($pdo) === $installedState,
        'Check-plus-identity uncertain-success retry mutated installed state.',
    );

    try {
        HostedBootstrap::assertDataPlaneIdentity($wrongTenantPublicId);
        throw new RuntimeException('Wrong-tenant identity verification unexpectedly succeeded.');
    } catch (HostedResolutionException $e) {
        hosted_atomicity_assert(
            str_contains($e->getMessage(), 'does not match'),
            'Wrong-tenant identity verification returned an unexpected failure.',
        );
    }
    hosted_atomicity_assert(
        hosted_atomicity_snapshot($pdo) === $installedState,
        'Wrong-tenant identity verification mutated installed state.',
    );

    foreach ([$tenantPublicId, $wrongTenantPublicId] as $retryTenantPublicId) {
        try {
            (new Installer())->installHosted($input, $retryTenantPublicId);
            throw new RuntimeException('Direct installHosted retry unexpectedly succeeded.');
        } catch (RuntimeException $e) {
            hosted_atomicity_assert(
                str_contains($e->getMessage(), Installer::ERROR_ALREADY_INSTALLED),
                'Direct installHosted retry did not return the stable installed conflict.',
            );
        }
        hosted_atomicity_assert(
            hosted_atomicity_snapshot($pdo) === $installedState,
            'Direct installHosted retry mutated installed state.',
        );
    }

    echo 'PASS hosted install atomicity contract (' . Database::driver() . ' ' . Database::serverVersion() . ")\n";
} finally {
    Database::reset();
    if ($atomicTemporarySqlite !== null && is_file($atomicTemporarySqlite)) {
        unlink($atomicTemporarySqlite);
    }
    if ($atomicTemporarySqlite !== null) {
        putenv('CPE_DB_PATH');
        putenv('CPE_DB_DRIVER');
    }
    if (is_file($atomicLogPath)) {
        unlink($atomicLogPath);
    }
    putenv('CPE_LOG_PATH');
}
