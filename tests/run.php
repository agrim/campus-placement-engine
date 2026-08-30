<?php

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/cpe-test-' . bin2hex(random_bytes(4)) . '.sqlite';
$importTmp = sys_get_temp_dir() . '/cpe-import-rollbacks-' . bin2hex(random_bytes(4));
$configTmp = sys_get_temp_dir() . '/cpe-config-snapshots-' . bin2hex(random_bytes(4));
$privacyTmp = sys_get_temp_dir() . '/cpe-privacy-snapshots-' . bin2hex(random_bytes(4));
putenv('CPE_DB_PATH=' . $tmp);
putenv('CPE_IMPORT_ROLLBACK_DIR=' . $importTmp);
putenv('CPE_CONFIG_SNAPSHOT_DIR=' . $configTmp);
putenv('CPE_PRIVACY_SNAPSHOT_DIR=' . $privacyTmp);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/authorized_setup_recovery_fixture.php';

use App\Domain\PlacementService;
use App\Domain\ConfigurationSnapshotService;
use App\Domain\DemoDataService;
use App\Domain\NotificationDeliveryService;
use App\Domain\ReadinessService;
use App\Domain\PrivacyService;
use App\Domain\SnapshotExporter;
use App\Domain\Workflow;
use App\Core\Portal;
use App\Core\Backup\BackupMetadata;
use App\Core\Backup\DatabaseBackupService;
use App\Core\Backup\DatabaseRestoreService;
use App\Core\Events\DomainEvent;
use App\Core\Events\DomainEventOutboxWorker;
use App\Core\Events\InternalEventDeliveryWorker;
use App\Core\Events\InternalEventFanoutWorker;
use App\Core\Events\PublicEventProjection;
use App\Core\Http\AuthorizationException;
use App\Core\Http\UserVisibleException;
use App\Core\Persistence\DatabaseConnectionInvalidException;
use App\Core\Persistence\WriteTransaction;
use App\Core\Modules\ModuleLifecycleService;
use App\Core\Modules\ModuleManifest;
use App\Core\Portability\PortalPortabilityService;
use App\Core\Privacy\PortalPrivacyService;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use App\Modules\Advising\Application\AdvisingService;
use App\Modules\Advising\Privacy\AdvisingPrivacyHandler;
use App\Modules\Placement\Workflow\WorkflowDefinitionValidator;
use App\Modules\Placement\Workflow\WorkflowDefinitionFileService;
use App\Modules\Placement\Workflow\WorkflowEngine;
use App\Modules\Placement\Workflow\WorkflowMigrationService;
use App\Modules\Placement\Workflow\WorkflowPublisher;
use App\Modules\Placement\Workflow\WorkflowRepository;
use App\Modules\Placement\Workflow\WorkflowSimulationService;
use App\Operations\MetricsService;
use App\Import\CsvImporter;
use App\Import\ImportRollbackService;
use App\Core\Install\PortalKernelSynchronizer;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Security\DatabaseSessionHandler;
use App\Security\ExternalIdentityService;
use App\Security\LoginThrottle;
use App\Security\OutboundHttpPolicy;
use App\Support\Auth;
use App\Support\Csv;
use App\Support\Database;
use App\Support\StructuredLogger;

$tests = [];

function test_case(string $name, callable $fn): void
{
    global $tests;
    $tests[] = [$name, $fn];
}

function assert_true(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = 'Values differ'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function collect_apps(array $groups): array
{
    $apps = [];
    foreach ($groups as $group) {
        foreach ($group['applications'] as $app) {
            $apps[] = $app;
        }
    }
    return $apps;
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = array_diff(scandir($dir) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            remove_tree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

function run_cli(array $args, array $env = []): array
{
    return run_cli_from(dirname(__DIR__), $args, $env);
}

function run_cli_from(string $root, array $args, array $env = []): array
{
    $processEnv = $_ENV;
    foreach (['CPE_DB_PATH', 'CPE_IMPORT_ROLLBACK_DIR', 'CPE_CONFIG_SNAPSHOT_DIR', 'CPE_PRIVACY_SNAPSHOT_DIR'] as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $processEnv[$key] = $value;
        }
    }
    $processEnv = array_merge($processEnv, $env);
    $process = proc_open(
        [PHP_BINARY, $root . '/placement', ...$args],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
        $processEnv
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start CLI process.');
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return [$exitCode, $stdout, $stderr];
}

function run_php_from(string $root, string $relative, array $env = []): array
{
    $processEnv = $_ENV;
    foreach (['CPE_DB_PATH', 'CPE_DB_DRIVER', 'CPE_DATABASE_URL', 'CPE_TEST_SCHEMA_PYTHON'] as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $processEnv[$key] = $value;
        }
    }
    $processEnv = array_merge($processEnv, $env);
    $process = proc_open(
        [PHP_BINARY, $root . '/' . $relative],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $processEnv,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start PHP contract process.');
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function render_layout_for_test(array $vars): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/app/Views/layout.php';
    return ob_get_clean() ?: '';
}

function render_view_for_test(string $template, array $vars): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/app/Views/' . $template . '.php';
    return ob_get_clean() ?: '';
}

/**
 * Raw SQL and PDO transaction boundaries are not interoperable across every
 * supported pdo_sqlite runtime. Keep this regression double honest by
 * rejecting PDO boundaries while still executing SQL boundaries.
 */
final class LegacySqliteManualTransactionPdo extends PDO
{
    public int $sqlBeginCalls = 0;
    public int $pdoCommitCalls = 0;
    public int $pdoRollbackCalls = 0;
    public bool $failNextSqlCommit = false;
    public bool $failNextSqlRollback = false;

    public function __construct(string $path)
    {
        parent::__construct('sqlite:' . $path);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->exec('PRAGMA foreign_keys = ON');
        $this->exec('PRAGMA busy_timeout = 5000');
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function exec(string $statement): int|false
    {
        $boundary = strtoupper(trim($statement));
        if ($boundary === 'BEGIN IMMEDIATE') {
            $this->sqlBeginCalls++;
        } elseif ($boundary === 'COMMIT' && $this->failNextSqlCommit) {
            $this->failNextSqlCommit = false;
            parent::exec('ROLLBACK');
            throw new RuntimeException('Forced SQL commit uncertainty.');
        } elseif ($boundary === 'ROLLBACK' && $this->failNextSqlRollback) {
            $this->failNextSqlRollback = false;
            parent::exec('ROLLBACK');
            throw new RuntimeException('Forced SQL rollback uncertainty.');
        }
        return parent::exec($statement);
    }

    public function commit(): bool
    {
        $this->pdoCommitCalls++;
        throw new RuntimeException('PDO commit cannot close a SQL-level SQLite transaction on this runtime.');
    }

    public function rollBack(): bool
    {
        $this->pdoRollbackCalls++;
        throw new RuntimeException('PDO rollback cannot close a SQL-level SQLite transaction on this runtime.');
    }
}

final class PdoWriteTransactionFaultDouble extends PDO
{
    public bool $active = false;
    public int $beginCalls = 0;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;
    public ?Throwable $commitFailure = null;
    public ?Throwable $rollbackFailure = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME
            ? 'pgsql'
            : parent::getAttribute($attribute);
    }

    public function beginTransaction(): bool
    {
        $this->beginCalls++;
        $this->active = true;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function commit(): bool
    {
        $this->commitCalls++;
        if ($this->commitFailure !== null) {
            throw $this->commitFailure;
        }
        $this->active = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->rollbackCalls++;
        if ($this->rollbackFailure !== null) {
            throw $this->rollbackFailure;
        }
        $this->active = false;
        return true;
    }
}

test_case('PDO write transaction preserves primary and cleanup failures', function (): void {
    $operationPrimary = new RuntimeException('forced PDO operation failure');
    $operationCleanup = new RuntimeException('forced PDO operation rollback failure');
    $operationPdo = new PdoWriteTransactionFaultDouble();
    $operationPdo->rollbackFailure = $operationCleanup;
    try {
        WriteTransaction::run($operationPdo, static function () use ($operationPrimary): never {
            throw $operationPrimary;
        });
        throw new RuntimeException('Expected PDO operation cleanup failure.');
    } catch (DatabaseConnectionInvalidException $e) {
        assert_same(WriteTransaction::ERROR_CLEANUP, $e->failureCode(), 'Operation rollback cleanup should use the write transaction failure code');
        assert_true($e->getPrevious() === $operationPrimary, 'Operation rollback cleanup should preserve the primary failure');
        assert_true($e->cleanupCause() === $operationCleanup, 'Operation rollback cleanup should preserve the cleanup failure');
        assert_true($e->requiresConnectionReset(), 'Operation rollback cleanup should require connection reset');
    }
    assert_same(1, $operationPdo->beginCalls, 'Operation fault should start one PDO transaction');
    assert_same(0, $operationPdo->commitCalls, 'Operation fault should not attempt commit');
    assert_same(1, $operationPdo->rollbackCalls, 'Operation fault should attempt one rollback');
    assert_true($operationPdo->inTransaction(), 'Failed PDO cleanup should leave transaction state uncertain');

    $commitPrimary = new RuntimeException('forced PDO commit failure');
    $commitCleanup = new RuntimeException('forced PDO commit rollback failure');
    $commitPdo = new PdoWriteTransactionFaultDouble();
    $commitPdo->commitFailure = $commitPrimary;
    $commitPdo->rollbackFailure = $commitCleanup;
    try {
        WriteTransaction::run($commitPdo, static fn (): string => 'result');
        throw new RuntimeException('Expected PDO commit cleanup failure.');
    } catch (DatabaseConnectionInvalidException $e) {
        assert_same(WriteTransaction::ERROR_CLEANUP, $e->failureCode(), 'Commit rollback cleanup should use the write transaction failure code');
        assert_true($e->getPrevious() === $commitPrimary, 'Commit rollback cleanup should preserve the commit failure');
        assert_true($e->cleanupCause() === $commitCleanup, 'Commit rollback cleanup should preserve the cleanup failure');
        assert_true($e->requiresConnectionReset(), 'Commit rollback cleanup should require connection reset');
    }
    assert_same(1, $commitPdo->beginCalls, 'Commit fault should start one PDO transaction');
    assert_same(1, $commitPdo->commitCalls, 'Commit fault should attempt one commit');
    assert_same(1, $commitPdo->rollbackCalls, 'Commit fault should attempt one rollback');
    assert_true($commitPdo->inTransaction(), 'Failed commit cleanup should leave transaction state uncertain');

    $callerPdo = new PdoWriteTransactionFaultDouble();
    $callerPdo->beginTransaction();
    assert_same('caller result', WriteTransaction::run($callerPdo, static fn (): string => 'caller result'), 'Caller-owned PDO transaction should execute directly');
    assert_same(1, $callerPdo->beginCalls, 'Shared boundary should not begin inside a caller-owned PDO transaction');
    assert_same(0, $callerPdo->commitCalls, 'Shared boundary should not commit a caller-owned PDO transaction');
    assert_same(0, $callerPdo->rollbackCalls, 'Shared boundary should not roll back a caller-owned PDO transaction');
    assert_true($callerPdo->inTransaction(), 'Caller-owned PDO transaction should remain active');
    $callerPdo->rollBack();
});

test_case('installer creates database, admin, settings, and demo data', function (): void {
    try {
        (new Installer())->install([
            'college_name' => 'Test College',
            'admin_name' => 'Test Admin',
            'admin_email' => 'not-an-email',
            'admin_password' => 'password123',
        ]);
        throw new RuntimeException('Expected invalid installer email failure.');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'valid email'), 'Installer should reject an invalid administrator email before migration.');
    }
    try {
        (new Installer())->install([
            'college_name' => 'Test College',
            'timezone' => 'Not/A-Timezone',
            'admin_name' => 'Test Admin',
            'admin_email' => 'admin@test.local',
            'admin_password' => 'password123',
        ]);
        throw new RuntimeException('Expected invalid installer timezone failure.');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'IANA timezone'), 'Installer should reject an invalid timezone before migration.');
    }

    Database::migrate();
    $recoveryAuthority = test_authorized_setup_recovery_authority();
    $pdo = Database::connection();
    try {
        (new Installer())->install([
            'college_name' => 'Partial Install College',
            'timezone' => 'Asia/Kolkata',
            'calendar_non_operating_weekdays' => 'funday',
            'admin_name' => 'Partial Admin',
            'admin_email' => 'partial-admin@example.test',
            'admin_password' => 'password123',
        ], $recoveryAuthority);
        throw new RuntimeException('Expected mid-install validation failure.');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'unknown weekday'), 'Atomic installer test should fail after beginning setup writes');
        assert_true(!Database::isInstalled(), 'A failed install must not leave the installer lock set');
        $partial = $pdo->query("SELECT value FROM settings WHERE key = 'college_name'")->fetchColumn();
        assert_same(false, $partial, 'A failed install should roll back partial settings');
    }
    $adminId = (new Installer())->install([
        'college_name' => 'Test College',
        'timezone' => 'Asia/Kolkata',
        'admin_name' => 'Test Admin',
        'admin_email' => 'admin@test.local',
        'admin_password' => 'password123',
        'seed_demo' => '1',
    ], $recoveryAuthority);
    assert_true($adminId > 0, 'Admin id should be created');
    assert_true(Database::isInstalled(), 'Database should be installed');
    $pdo = Database::connection();
    assert_same(5, (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn(), 'Demo candidates');
    assert_same(3, (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn(), 'Demo companies');
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM company_rounds')->fetchColumn() >= 5, 'Demo company rounds');
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM round_schedules')->fetchColumn() >= 5, 'Demo round schedules');
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM round_panelists')->fetchColumn() >= 6, 'Demo round panelists');
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM application_slot_assignments')->fetchColumn() >= 4, 'Demo slot assignments');
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() >= 7, 'Demo users should be created');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'idempotency_keys'")->fetchColumn(), 'Installer should create idempotency table');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('candidates') WHERE name = 'tags'")->fetchColumn(), 'Installer should create candidate tags field');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('companies') WHERE name = 'tags'")->fetchColumn(), 'Installer should create company tags field');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('candidates') WHERE name = 'custom_fields_json'")->fetchColumn(), 'Installer should create candidate custom fields field');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('companies') WHERE name = 'custom_fields_json'")->fetchColumn(), 'Installer should create company custom fields field');
    assert_same('Test College Placement Cycle', $pdo->query("SELECT value FROM settings WHERE key = 'cycle_name'")->fetchColumn(), 'Installer should default cycle name from college');
    assert_same('Campus Placement Engine', $pdo->query("SELECT value FROM settings WHERE key = 'site_name'")->fetchColumn(), 'Installer should default site name');
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'site_tagline'")->fetchColumn(), 'Installer should default site tagline empty');
    assert_same('Public Placements', $pdo->query("SELECT value FROM settings WHERE key = 'public_placements_title'")->fetchColumn(), 'Installer should default public placements title');
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'candidate_status_title'")->fetchColumn(), 'Installer should default candidate status title to dynamic');
    assert_same('final', $pdo->query("SELECT value FROM settings WHERE key = 'cycle_type'")->fetchColumn(), 'Installer should default cycle type');
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'calendar_non_operating_weekdays'")->fetchColumn(), 'Installer should default non-operating weekdays empty');
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'calendar_non_operating_dates'")->fetchColumn(), 'Installer should default non-operating dates empty');
    assert_same('none', $pdo->query("SELECT value FROM settings WHERE key = 'audit_request_metadata'")->fetchColumn(), 'Installer should default audit request metadata retention to none');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('audit_logs') WHERE name = 'ip_address'")->fetchColumn(), 'Installer should create optional audit IP metadata field');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('audit_logs') WHERE name = 'user_agent'")->fetchColumn(), 'Installer should create optional audit user agent metadata field');
    assert_same('45', $pdo->query("SELECT value FROM settings WHERE key = 'board_refresh_seconds'")->fetchColumn(), 'Installer should default board refresh interval');
    assert_same('0', $pdo->query("SELECT value FROM settings WHERE key = 'configuration_freeze'")->fetchColumn(), 'Installer should default configuration freeze off');
    assert_same('1', $pdo->query("SELECT value FROM settings WHERE key = 'synthetic_demo_data_loaded'")->fetchColumn(), 'Installer should mark live dummy data as loaded');
    assert_same('Candidate', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_candidate_label'")->fetchColumn(), 'Installer should default candidate singular label');
    assert_same('Candidates', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_candidates_label'")->fetchColumn(), 'Installer should default candidate plural label');
    assert_same('Company', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_company_label'")->fetchColumn(), 'Installer should default company singular label');
    assert_same('Companies', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_companies_label'")->fetchColumn(), 'Installer should default company plural label');
    assert_same('placement_totals,application_status_counts,placements_by_company', $pdo->query("SELECT value FROM settings WHERE key = 'export_profile_custom_datasets'")->fetchColumn(), 'Installer should default custom export profile');
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'import_header_aliases_json'")->fetchColumn(), 'Installer should default custom import aliases empty');
});

test_case('portal kernel establishes institution module and capability context', function (): void {
    Portal::reset();
    $pdo = Database::connection();
    assert_same('sqlite', Database::driver(), 'Self-hosted default driver');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM institutions WHERE slug = 'default'")->fetchColumn(), 'Default institution');
    assert_same('Test College', $pdo->query("SELECT name FROM institutions WHERE slug = 'default'")->fetchColumn(), 'Institution name synchronized from installer');
    assert_same('Test College Placement Cycle', $pdo->query("SELECT name FROM placement_cycles WHERE cycle_key = 'default'")->fetchColumn(), 'Placement cycle synchronized from installer');
    assert_same(1, (int) $pdo->query("SELECT enabled FROM module_installations WHERE module_key = 'placement'")->fetchColumn(), 'Placement module enabled');
    assert_true((int) $pdo->query("SELECT COUNT(*) FROM role_capabilities WHERE role_key = 'control'")->fetchColumn() > 0, 'Control capabilities synchronized');
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM user_role_assignments')->fetchColumn() >= 7, 'User role assignments synchronized');

    $context = cpe_context();
    assert_same('Test College', $context->institution()->name(), 'Application context institution');
    assert_true($context->modules()->isEnabled('placement'), 'Application context module registry');
    assert_true($context->capabilities()->allows(['role' => 'control', 'active' => 1], 'placement.records.manage'), 'Control records capability');
    assert_true(!$context->capabilities()->allows(['role' => 'auditor', 'active' => 1], 'placement.application.transition'), 'Auditor transition denied');
    assert_true($context->capabilities()->allows(['role' => 'admin', 'active' => 1], 'portal.settings.manage'), 'Administrator wildcard capability');
});

test_case('SQLite immediate transactions use SQL boundaries compatible with PHP 8.2 PDO state', function (): void {
    $path = sys_get_temp_dir() . '/cpe-legacy-pdo-state-' . bin2hex(random_bytes(4)) . '.sqlite';
    $source = Database::connection();
    $source->exec('VACUUM INTO ' . $source->quote($path));
    try {
        $pdo = new LegacySqliteManualTransactionPdo($path);
        $now = cpe_now();
        $pdo->exec("UPDATE module_installations SET version = '0.0.1' WHERE module_key = 'advising'");
        $pdo->exec("DELETE FROM role_capabilities WHERE role_key = 'control' AND capability = 'placement.board.view'");
        $pdo->exec(
            "CREATE TRIGGER fail_legacy_pdo_kernel_sync BEFORE INSERT ON role_capabilities
             WHEN NEW.role_key = 'control' AND NEW.capability = 'portal.access'
             BEGIN SELECT RAISE(ABORT, 'forced legacy PDO rollback'); END"
        );
        try {
            (new PortalKernelSynchronizer())->synchronize($pdo);
            throw new RuntimeException('Expected legacy PDO synchronization rollback.');
        } catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), 'forced legacy PDO rollback'), 'Expected forced legacy PDO synchronization failure');
        } finally {
            $pdo->exec('DROP TRIGGER fail_legacy_pdo_kernel_sync');
        }
        assert_same('0.0.1', $pdo->query("SELECT version FROM module_installations WHERE module_key = 'advising'")->fetchColumn(), 'SQL-level rollback should restore module synchronization state');
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM role_capabilities WHERE role_key = 'control' AND capability = 'placement.board.view'")->fetchColumn(), 'SQL-level rollback should restore role capability state');

        (new PortalKernelSynchronizer())->synchronize($pdo);
        assert_same('0.1.0', $pdo->query("SELECT version FROM module_installations WHERE module_key = 'advising'")->fetchColumn(), 'SQL-level commit should persist kernel synchronization');

        $lifecycle = new ModuleLifecycleService($pdo);
        $lifecycle->disable('placement', 1);
        $lifecycle->enable('placement', 1);
        assert_same(1, (int) $pdo->query("SELECT enabled FROM module_installations WHERE module_key = 'placement'")->fetchColumn(), 'SQL-level module lifecycle commit should persist');

        $applicationId = (int) $pdo->query("SELECT id FROM applications WHERE current_status = 'idle' ORDER BY id LIMIT 1")->fetchColumn();
        assert_true($applicationId > 0, 'Compatibility fixture should contain an idle application');
        $result = (new PlacementService($pdo))->applyBoardMove(
            $applicationId,
            1,
            'admin',
            'scheduled',
            '',
            'legacy PDO transaction compatibility',
            'idle',
            bin2hex(random_bytes(16)),
        );
        assert_same(['duplicate' => false, 'status' => 'scheduled'], $result, 'SQL-level board transaction commit should persist');
        assert_same(0, $pdo->pdoCommitCalls, 'SQL-level SQLite transactions must not call PDO::commit()');
        assert_same(0, $pdo->pdoRollbackCalls, 'SQL-level SQLite transactions must not call PDO::rollBack()');

        $siteName = (string) $pdo->query("SELECT value FROM settings WHERE key = 'site_name'")->fetchColumn();
        $beginCalls = $pdo->sqlBeginCalls;
        $pdo->failNextSqlCommit = true;
        try {
            WriteTransaction::run($pdo, static function () use ($pdo): void {
                $pdo->exec("UPDATE settings SET value = 'uncertain commit' WHERE key = 'site_name'");
            });
            throw new RuntimeException('Expected uncertain SQL commit failure.');
        } catch (DatabaseConnectionInvalidException $e) {
            assert_same(WriteTransaction::ERROR_CLEANUP, $e->failureCode(), 'Commit cleanup failure should invalidate the connection');
        }
        assert_same($siteName, $pdo->query("SELECT value FROM settings WHERE key = 'site_name'")->fetchColumn(), 'Uncertain SQL commit fixture should be rolled back');
        WriteTransaction::run($pdo, static function () use ($pdo): void {
            $pdo->exec("UPDATE settings SET value = 'after commit failure' WHERE key = 'site_name'");
        });
        assert_same($beginCalls + 2, $pdo->sqlBeginCalls, 'Commit failure must not leave stale shared transaction ownership');

        $pdo->failNextSqlRollback = true;
        $beginCalls = $pdo->sqlBeginCalls;
        try {
            WriteTransaction::run($pdo, static function () use ($pdo): void {
                $pdo->exec("UPDATE settings SET value = 'uncertain rollback' WHERE key = 'site_name'");
                throw new RuntimeException('Forced transaction operation failure.');
            });
            throw new RuntimeException('Expected uncertain SQL rollback failure.');
        } catch (DatabaseConnectionInvalidException $e) {
            assert_same(WriteTransaction::ERROR_CLEANUP, $e->failureCode(), 'Rollback cleanup failure should invalidate the connection');
        }
        assert_same('after commit failure', $pdo->query("SELECT value FROM settings WHERE key = 'site_name'")->fetchColumn(), 'Uncertain SQL rollback fixture should be rolled back');
        WriteTransaction::run($pdo, static function () use ($pdo): void {
            $pdo->exec("UPDATE settings SET value = 'after rollback failure' WHERE key = 'site_name'");
        });
        assert_same($beginCalls + 2, $pdo->sqlBeginCalls, 'Rollback failure must not leave stale shared transaction ownership');

        $caller = new PDO('sqlite:' . $path);
        $caller->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $caller->beginTransaction();
        WriteTransaction::run($caller, static function () use ($caller): void {
            $caller->exec("UPDATE settings SET value = 'caller owned' WHERE key = 'site_name'");
        });
        assert_true($caller->inTransaction(), 'Shared boundary must not commit a caller-owned PDO transaction');
        $caller->rollBack();
        assert_same('after rollback failure', $caller->query("SELECT value FROM settings WHERE key = 'site_name'")->fetchColumn(), 'Caller-owned PDO rollback should remain authoritative');
    } finally {
        unset($pdo);
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test_case('portal kernel synchronization is atomic and exactly reconciles system grants', function (): void {
    $pdo = Database::connection();
    $now = cpe_now();
    $pdo->exec("UPDATE module_installations SET version = '0.0.1' WHERE module_key = 'advising'");
    $pdo->prepare(
        'INSERT INTO roles (role_key, label, system_role, created_at, updated_at)
         VALUES (?, ?, 0, ?, ?) ON CONFLICT(role_key) DO NOTHING'
    )->execute(['local_test_role', 'Local Test Role', $now, $now]);
    $pdo->exec("INSERT INTO role_capabilities (role_key, capability) VALUES ('local_test_role', 'placement.board.view')");
    $pdo->exec("INSERT INTO role_capabilities (role_key, capability) VALUES ('control', 'placement.retired.manage')");
    $pdo->exec("DELETE FROM role_capabilities WHERE role_key = 'control' AND capability = 'placement.board.view'");
    $pdo->prepare(
        'INSERT INTO roles (role_key, label, system_role, created_at, updated_at) VALUES (?, ?, 1, ?, ?)'
    )->execute(['retired_system_role', 'Retired System Role', $now, $now]);
    $pdo->exec("INSERT INTO role_capabilities (role_key, capability) VALUES ('retired_system_role', 'placement.board.view')");

    $pdo->exec(
        "CREATE TRIGGER fail_kernel_capability_sync BEFORE INSERT ON role_capabilities
         WHEN NEW.role_key = 'control' AND NEW.capability = 'portal.access'
         BEGIN SELECT RAISE(ABORT, 'forced kernel synchronization rollback'); END"
    );
    try {
        (new PortalKernelSynchronizer())->synchronize($pdo);
        throw new RuntimeException('Expected kernel synchronization rollback.');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'forced kernel synchronization rollback'), 'Expected forced synchronizer failure');
    } finally {
        $pdo->exec('DROP TRIGGER fail_kernel_capability_sync');
    }
    assert_same('0.0.1', $pdo->query("SELECT version FROM module_installations WHERE module_key = 'advising'")->fetchColumn(), 'Failed synchronization should roll back module version');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM role_capabilities WHERE role_key = 'control' AND capability = 'placement.retired.manage'")->fetchColumn(), 'Failed synchronization should roll back system grant deletion');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM role_capabilities WHERE role_key = 'control' AND capability = 'placement.board.view'")->fetchColumn(), 'Failed synchronization should roll back system grant insertion');

    (new PortalKernelSynchronizer())->synchronize($pdo);
    assert_same('0.1.0', $pdo->query("SELECT version FROM module_installations WHERE module_key = 'advising'")->fetchColumn(), 'Synchronization should converge module version');
    assert_same(0, (int) $pdo->query("SELECT enabled FROM module_installations WHERE module_key = 'advising'")->fetchColumn(), 'Version synchronization should preserve configured enablement');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM role_capabilities WHERE role_key = 'control' AND capability = 'placement.board.view'")->fetchColumn(), 'Synchronization should restore configured system grant');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM role_capabilities WHERE role_key = 'control' AND capability = 'placement.retired.manage'")->fetchColumn(), 'Synchronization should revoke obsolete system grant');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM role_capabilities WHERE role_key = 'retired_system_role'")->fetchColumn(), 'Synchronization should revoke grants for retired system roles');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM role_capabilities WHERE role_key = 'local_test_role' AND capability = 'placement.board.view'")->fetchColumn(), 'Synchronization should preserve custom-role grants');
    Portal::reset();
});

test_case('placement module owns its routes and capability-filtered navigation', function (): void {
    Portal::reset();
    $manager = cpe_context()->moduleManager();
    $routes = $manager->routes();
    $routeNames = array_column($routes, 'name');

    assert_true(in_array('board', $routeNames, true), 'Placement module should register the board route');
    assert_true(in_array('records', $routeNames, true), 'Placement module should register the records route');
    assert_same(count($routes), count(array_unique(array_map(
        fn (array $route): string => strtoupper((string) $route['method']) . ' ' . (string) $route['name'],
        $routes
    ))), 'Module routes should be unique by method and name');
    foreach ($routes as $route) {
        assert_same('placement', $route['module'], 'Current operational routes should belong to Placement Operations');
    }

    $moduleDefinitions = cpe_config('modules', []);
    foreach (cpe_config('capabilities.roles', []) as $capabilities) {
        foreach ($capabilities as $capability) {
            if ($capability === '*' || !str_contains($capability, '.')) {
                continue;
            }
            [$owner] = explode('.', $capability, 2);
            if (!isset($moduleDefinitions[$owner])) {
                continue;
            }
            assert_true(
                in_array($capability, $moduleDefinitions[$owner]['capabilities'] ?? [], true),
                'Module manifest should own configured capability ' . $capability
            );
        }
    }

    $companyItems = $manager->navigation(['role' => 'company', 'active' => 1]);
    $companyRoutes = array_column($companyItems, 'route');
    assert_true(in_array('board', $companyRoutes, true), 'Company navigation should include the board');
    assert_true(!in_array('records', $companyRoutes, true), 'Company navigation should hide records management');

    $adminItems = $manager->navigation(['role' => 'admin', 'active' => 1]);
    $adminRoutes = array_column($adminItems, 'route');
    assert_true(in_array('records', $adminRoutes, true), 'Administrator navigation should include records');
    assert_true(in_array('system', $adminRoutes, true), 'Administrator navigation should include system operations');
});

test_case('module lifecycle disables routes without deleting placement data', function (): void {
    $pdo = Database::connection();
    $manifest = ModuleManifest::fromArray('placement', cpe_config('modules.placement'));
    assert_same('Placement Operations', $manifest->name(), 'Placement manifest name');
    assert_same('0.1.0', $manifest->version(), 'Placement manifest version');
    assert_same('>=0.1.0-alpha.1', $manifest->coreRequires(), 'Placement manifest prerelease core requirement');
    $manifest->assertCompatible('0.1.0-alpha.1', cpe_config('modules'));
    assert_true(in_array('placement.application.transition', $manifest->capabilities(), true), 'Placement manifest capabilities');

    $service = new ModuleLifecycleService($pdo);
    $candidateCount = (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn();
    $applicationCount = (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn();
    $service->disable('placement', 1);
    assert_true(!cpe_context()->modules()->isEnabled('placement'), 'Placement should be disabled in the request context');
    assert_same([], cpe_context()->moduleManager()->routes(), 'Disabled module should contribute no routes');
    assert_true(!cpe_context()->capabilities()->allows(['role' => 'control', 'active' => 1], 'placement.board.view'), 'Disabled module should deny its granted capability');
    assert_true(!cpe_context()->capabilities()->allows(['role' => 'admin', 'active' => 1], 'placement.board.view'), 'Disabled module should deny wildcard administrators');
    assert_true(cpe_context()->capabilities()->allows(['role' => 'admin', 'active' => 1], 'portal.modules.manage'), 'Disabling a module should preserve core portal capability');
    assert_same($candidateCount, (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn(), 'Disabling should preserve candidates');
    assert_same($applicationCount, (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn(), 'Disabling should preserve applications');
    $service->disable('placement', 1);
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM module_lifecycle_events WHERE module_key = 'placement' AND event_type = 'disabled'")->fetchColumn(), 'Repeated disable should not duplicate lifecycle event');
    try {
        $service->uninstall('placement', false, false, 1);
        throw new RuntimeException('Expected guarded uninstall failure.');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'successful export'), 'Uninstall should require export and confirmation');
    }
    $service->enable('placement', 1);
    assert_true(cpe_context()->modules()->isEnabled('placement'), 'Placement should be re-enabled');
    assert_true(count(cpe_context()->moduleManager()->routes()) > 0, 'Re-enabled module should restore routes');
    assert_true(cpe_context()->capabilities()->allows(['role' => 'admin', 'active' => 1], 'placement.board.view'), 'Re-enabled module should reactivate preserved grant');
    $service->enable('placement', 1);
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM module_lifecycle_events WHERE module_key = 'placement' AND event_type IN ('disabled', 'enabled')")->fetchColumn(), 'Lifecycle changes should be recorded');
});

test_case('career advising proves module lifecycle events privacy and independent routes', function (): void {
    $pdo = Database::connection();
    $lifecycle = new ModuleLifecycleService($pdo);
    $advising = array_values(array_filter($lifecycle->modules(), fn (array $module): bool => $module['key'] === 'advising'))[0] ?? null;
    assert_true(is_array($advising), 'Advising manifest should be registered');
    assert_true(!$advising['enabled'], 'Advising should be disabled by default for self-hosted installs');

    $lifecycle->enable('advising', 1);
    Portal::reset();
    $routes = cpe_context()->moduleManager()->routes();
    $advisingRoutes = array_values(array_filter($routes, fn (array $route): bool => $route['module'] === 'advising'));
    assert_same(5, count($advisingRoutes), 'Advising should contribute only its own routes');
    assert_true(in_array('board', array_column($routes, 'name'), true), 'Enabling Advising must not replace Placement routes');
    $advisorNavigation = cpe_context()->moduleManager()->navigation(['role' => 'advisor', 'active' => 1]);
    $advisorRoutes = array_column($advisorNavigation, 'route');
    assert_true(in_array('advising', $advisorRoutes, true), 'Advisor navigation should expose Career Advising');
    assert_true(!in_array('board', $advisorRoutes, true) && !in_array('records', $advisorRoutes, true), 'Advisor navigation should not expose Placement operations');

    $studentId = (int) $pdo->query('SELECT id FROM student_profiles ORDER BY id LIMIT 1')->fetchColumn();
    $service = new AdvisingService($pdo);
    $appointmentId = $service->createAppointment([
        'student_profile_id' => $studentId,
        'adviser_user_id' => 1,
        'appointment_status' => 'scheduled',
        'starts_at' => '2026-08-01T10:00',
        'ends_at' => '2026-08-01T10:30',
        'appointment_mode' => 'in_person',
        'location' => 'Career Services Room 1',
        'topic' => 'Offer decision and transition',
        'student_notes' => 'Discuss first ninety days.',
    ], 1);
    assert_true($appointmentId > 0, 'Advising appointment should be created');
    $service->addNote($appointmentId, 'Student would like an alumni mentor introduction.', 1);
    $service->updateAppointmentStatus($appointmentId, 'completed', 1);

    $candidatePublicId = (string) $pdo->query(
        "SELECT c.public_id FROM candidates c
         JOIN people p ON p.legacy_candidate_id = c.id
         JOIN student_profiles sp ON sp.person_id = p.id
         WHERE sp.id = {$studentId}"
    )->fetchColumn();
    $event = new DomainEvent(
        'placement.offer.accepted',
        'placement_application',
        'application_' . str_repeat('a', 32),
        'placement',
        ['candidate_public_id' => $candidatePublicId, 'company_public_id' => 'company_' . str_repeat('b', 32)],
        cpe_now(),
    );
    cpe_context()->events()->dispatch($event);
    cpe_context()->events()->dispatch($event);
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM advising_tasks WHERE task_type = 'offer_outcome_followup'")->fetchColumn(), 'Advising observer must not run in the publishing transaction');
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM domain_event_module_fanout WHERE module_key = 'advising'")->fetchColumn(), 'Each event should persist durable Advising eligibility');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM domain_event_deliveries WHERE subscription_id = 'internal.advising.offer_follow_up.v1'")->fetchColumn(), 'Publishing must not construct Advising declarations');
    $fanoutResult = (new InternalEventFanoutWorker($pdo))->work(10);
    assert_same(2, $fanoutResult['expanded'], 'Post-commit fanout should expand both Advising declarations');
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM domain_event_deliveries WHERE subscription_id = 'internal.advising.offer_follow_up.v1'")->fetchColumn(), 'Each event should expand to one stable Advising delivery');
    $observerResult = (new InternalEventDeliveryWorker($pdo))->work(10);
    assert_same(2, $observerResult['delivered'], 'Post-commit worker should deliver both Advising observations');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM advising_tasks WHERE task_type = 'offer_outcome_followup'")->fetchColumn(), 'Advising event subscriber should be idempotent');
    assert_same($studentId, (int) $pdo->query("SELECT student_profile_id FROM advising_tasks WHERE task_type = 'offer_outcome_followup'")->fetchColumn(), 'Event subject should resolve through the core person reference bridge');

    $lifecycle->disable('advising', 1);
    Portal::reset();
    assert_true(!in_array('advising', array_column(cpe_context()->moduleManager()->routes(), 'name'), true), 'Disabling Advising should remove its routes');
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM advising_appointments')->fetchColumn(), 'Disabling Advising must preserve appointments');
    $lifecycle->enable('advising', 1);
});

test_case('domain event outbox claims delivers retries and dead letters without duplicate delivery', function (): void {
    $pdo = Database::connection();
    $pdo->exec('DELETE FROM domain_event_outbox');
    $instanceId = (string) $pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $outbox = sys_get_temp_dir() . '/cpe-domain-events-' . bin2hex(random_bytes(4)) . '.jsonl';
    $blocker = sys_get_temp_dir() . '/cpe-domain-events-blocker-' . bin2hex(random_bytes(4));
    file_put_contents($blocker, 'not a directory');
    try {
        $event = new DomainEvent(
            'placement.contract.checked',
            'placement_application',
            'application_' . str_repeat('c', 32),
            'placement',
            ['contract' => true],
            cpe_now(),
            PublicEventProjection::applicationStatusChanged(
                $instanceId,
                'application_' . str_repeat('c', 32),
                2,
                'requested',
                'scheduled',
                StructuredLogger::requestId(),
            ),
        );
        $publicId = cpe_context()->events()->dispatch($event);
        putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH=' . $outbox);
        $result = (new DomainEventOutboxWorker($pdo))->work(10);
        assert_same(1, $result['claimed'], 'Outbox worker should claim one pending event');
        assert_same(1, $result['delivered'], 'Outbox worker should deliver one pending event');
        assert_same(1, count(file($outbox, FILE_IGNORE_NEW_LINES) ?: []), 'Outbox file should receive one envelope');
        $row = $pdo->query("SELECT processed_at, attempts, delivered_to FROM domain_event_outbox WHERE public_id = " . $pdo->quote($publicId))->fetch();
        assert_true((string) $row['processed_at'] !== '', 'Delivered event should be acknowledged');
        assert_same(1, (int) $row['attempts'], 'A claim should increment attempts exactly once');
        assert_same('file', (string) $row['delivered_to'], 'Delivered event should record only the fixed destination kind');
        assert_same(0, (new DomainEventOutboxWorker($pdo))->work(10)['claimed'], 'Acknowledged events should not be claimed again');

        cpe_context()->events()->dispatch(new DomainEvent(
            'placement.contract.failed',
            'placement_application',
            'application_' . str_repeat('d', 32),
            'placement',
            ['contract' => false],
            cpe_now(),
            PublicEventProjection::applicationStatusChanged(
                $instanceId,
                'application_' . str_repeat('d', 32),
                2,
                'scheduled',
                'intransit',
                StructuredLogger::requestId(),
            ),
        ));
        putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH=' . $blocker . '/events.jsonl');
        putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS=1');
        $failed = (new DomainEventOutboxWorker($pdo))->work(10);
        assert_same(1, $failed['failed'], 'Failed event delivery should be reported');
        assert_same(1, $failed['dead_lettered'], 'Configured terminal attempt should dead-letter the event');
        assert_same(1, (new MetricsService($pdo))->snapshot()['domain_events_dead_lettered'], 'Metrics should expose dead-lettered events');
    } finally {
        putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH');
        putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS');
        if (is_file($outbox)) {
            unlink($outbox);
        }
        if (is_file($blocker)) {
            unlink($blocker);
        }
        $pdo->exec('DELETE FROM domain_event_outbox');
    }
});

test_case('public dead-letter replay CLI targets one exact event and audits idempotently', function (): void {
    $db = sys_get_temp_dir() . '/cpe-public-replay-cli-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        [$installStatus, , $installError] = run_cli(['install-demo'], ['CPE_DB_PATH' => $db]);
        assert_same(0, $installStatus, 'Public replay CLI fixture install should succeed: ' . $installError);
        $fixture = (static function (string $path): array {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $institution = $pdo->query(
                "SELECT id, public_id FROM institutions WHERE slug = 'default'",
            )->fetch();
            $applicationPublicId = (string) $pdo->query(
                'SELECT public_id FROM applications ORDER BY id LIMIT 1',
            )->fetchColumn();
            assert_true(is_array($institution), 'Public replay CLI fixture institution is missing');
            assert_true($applicationPublicId !== '', 'Public replay CLI fixture application is missing');
            $adminId = (int) $pdo->query(
                "SELECT id FROM users WHERE active = 1 AND role = 'admin' ORDER BY id LIMIT 1",
            )->fetchColumn();
            $restrictedActorId = (int) $pdo->query(
                "SELECT id FROM users WHERE active = 1 AND role <> 'admin' ORDER BY id LIMIT 1",
            )->fetchColumn();
            assert_true($adminId > 0, 'Public replay CLI fixture administrator is missing');
            assert_true($restrictedActorId > 0, 'Public replay CLI fixture restricted user is missing');
            $inactiveEmail = 'inactive-cli-replay-' . bin2hex(random_bytes(4)) . '@example.test';
            $inactive = $pdo->prepare(
                "INSERT INTO users (name, email, password_hash, role, active, created_at)
                 VALUES (?, ?, ?, 'admin', 0, ?)",
            );
            $inactive->execute([
                'Inactive CLI Replay Administrator',
                $inactiveEmail,
                password_hash('inactive-cli-replay-password', PASSWORD_DEFAULT),
                cpe_now(),
            ]);
            $inactiveQuery = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $inactiveQuery->execute([$inactiveEmail]);
            $inactiveAdminId = (int) $inactiveQuery->fetchColumn();
            assert_true($inactiveAdminId > 0, 'Public replay CLI fixture inactive administrator is missing');
            $eventPublicId = 'event_' . bin2hex(random_bytes(16));
            $now = cpe_now();
            $insert = $pdo->prepare(
                'INSERT INTO domain_event_outbox
                 (public_id, event_name, aggregate_type, aggregate_public_id, institution_id,
                  module_key, payload_json, occurred_at, processed_at, attempts, available_at,
                  delivered_to, failed_at, locked_at, lock_token,
                  public_event_type, public_schema_version, public_instance_id,
                  public_aggregate_type, public_aggregate_id, public_aggregate_version,
                  public_payload_json, public_correlation_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 10, ?, ?, ?, NULL, NULL,
                         ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $insert->execute([
                $eventPublicId,
                'placement.application.transitioned',
                'placement_application',
                $applicationPublicId,
                (int) $institution['id'],
                'placement',
                '{"private":"not-for-audit-or-delivery"}',
                $now,
                $now,
                '',
                $now,
                'application.status_changed',
                1,
                (string) $institution['public_id'],
                'application',
                $applicationPublicId,
                2,
                '{"from_status":"idle","to_status":"scheduled"}',
                'req_' . bin2hex(random_bytes(12)),
            ]);
            return [
                'event_public_id' => $eventPublicId,
                'admin_id' => $adminId,
                'restricted_actor_id' => $restrictedActorId,
                'inactive_admin_id' => $inactiveAdminId,
            ];
        })($db);
        $eventPublicId = (string) $fixture['event_public_id'];
        $actorFailure = 'Error: An active administrator user ID is required.' . "\n";
        foreach ([
            'active restricted' => ['--actor-user-id=' . (int) $fixture['restricted_actor_id']],
            'inactive administrator' => ['--actor-user-id=' . (int) $fixture['inactive_admin_id']],
            'missing' => [],
            'unknown' => ['--actor-user-id=2147483647'],
        ] as $actorLabel => $actorArguments) {
            [$deniedStatus, $deniedOut, $deniedError] = run_cli([
                'replay-public-event',
                '--event=' . $eventPublicId,
                ...$actorArguments,
            ], ['CPE_DB_PATH' => $db]);
            assert_same(1, $deniedStatus, ucfirst($actorLabel) . ' actor should be rejected by public replay CLI');
            assert_same('', $deniedOut, 'Denied public replay CLI should not report a state transition');
            assert_same($actorFailure, $deniedError, 'Denied public replay CLI should use one stable redacted actor message');
        }
        $deniedProof = (static function (string $path, string $publicId): array {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return [
                'dead_lettered' => (int) $pdo->query(
                    'SELECT COUNT(*) FROM domain_event_outbox
                     WHERE public_id = ' . $pdo->quote($publicId) . ' AND failed_at IS NOT NULL',
                )->fetchColumn(),
                'audits' => (int) $pdo->query(
                    "SELECT COUNT(*) FROM audit_logs WHERE action = 'public_event.dead_letter_replay'",
                )->fetchColumn(),
            ];
        })($db, $eventPublicId);
        assert_same(1, $deniedProof['dead_lettered'], 'Denied public replay CLI changed dead-letter state');
        assert_same(0, $deniedProof['audits'], 'Denied public replay CLI wrote false attribution');

        [$status, $stdout, $stderr] = run_cli([
            'replay-public-event',
            '--event=' . $eventPublicId,
            '--actor-user-id=' . (int) $fixture['admin_id'],
        ], ['CPE_DB_PATH' => $db]);
        assert_same(0, $status, 'Public replay CLI should requeue an exact dead letter: ' . $stderr);
        assert_same(
            'Public event replay replayed: ' . $eventPublicId . "\n",
            $stdout,
            'Public replay CLI should report only the exact event identity and outcome',
        );

        $proof = (static function (string $path, string $publicId): array {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $event = $pdo->query(
                'SELECT attempts, failed_at, locked_at, lock_token
                 FROM domain_event_outbox WHERE public_id = ' . $pdo->quote($publicId),
            )->fetch(PDO::FETCH_ASSOC);
            $audit = $pdo->query(
                "SELECT actor_user_id, subject_type, detail
                 FROM audit_logs WHERE action = 'public_event.dead_letter_replay'",
            )->fetchAll(PDO::FETCH_ASSOC);
            return ['event' => $event, 'audit' => $audit];
        })($db, $eventPublicId);
        assert_same(0, (int) $proof['event']['attempts'], 'Public replay CLI should reset attempts');
        assert_same(null, $proof['event']['failed_at'], 'Public replay CLI should clear dead-letter state');
        assert_same(null, $proof['event']['locked_at'], 'Public replay CLI should clear stale lock time');
        assert_same(null, $proof['event']['lock_token'], 'Public replay CLI should clear stale lock token');
        assert_same(1, count($proof['audit']), 'Public replay CLI should write one audit row');
        assert_same((int) $fixture['admin_id'], (int) $proof['audit'][0]['actor_user_id'], 'Public replay CLI audit should record the active administrator');
        assert_same('public_event', (string) $proof['audit'][0]['subject_type'], 'Public replay CLI audit subject differs');
        assert_same(
            'Dead-lettered public event requeued for delivery.',
            (string) $proof['audit'][0]['detail'],
            'Public replay CLI audit must remain fixed and payload-free',
        );

        [$repeatStatus, $repeatOut, $repeatError] = run_cli([
            'replay-public-event',
            '--event=' . $eventPublicId,
            '--actor-user-id=' . (int) $fixture['admin_id'],
        ], ['CPE_DB_PATH' => $db]);
        assert_same(0, $repeatStatus, 'Repeated public replay CLI should be idempotent: ' . $repeatError);
        assert_same(
            'Public event replay already-replayed: ' . $eventPublicId . "\n",
            $repeatOut,
            'Repeated public replay CLI outcome differs',
        );
        $auditCount = (static function (string $path): int {
            $pdo = new PDO('sqlite:' . $path);
            return (int) $pdo->query(
                "SELECT COUNT(*) FROM audit_logs WHERE action = 'public_event.dead_letter_replay'",
            )->fetchColumn();
        })($db);
        assert_same(1, $auditCount, 'Repeated public replay CLI should not duplicate the audit marker');
    } finally {
        foreach ([$db, $db . '-wal', $db . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});

test_case('logical portability bundle round trips placement data and rejects tampering', function (): void {
    $sourceProvider = Database::provider();
    $sourcePdo = Database::connection();
    $root = sys_get_temp_dir() . '/cpe-portability-' . bin2hex(random_bytes(4));
    $bundle = $root . '/bundle';
    $targetDb = $root . '/target.sqlite';
    $hostedTargetDb = $root . '/hosted-target.sqlite';
    $backupDir = $root . '/backups';
    $hostedBackupDir = $root . '/hosted-backups';
    mkdir($root, 0775, true);

    $snapshot = static function (PDO $pdo): array {
        $schema = $pdo->query(
            "SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name",
        )->fetchAll(PDO::FETCH_ASSOC);
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        )->fetchAll(PDO::FETCH_COLUMN);
        $rows = [];
        foreach (array_map('strval', $tables) as $table) {
            assert_true(preg_match('/\A[a-z_]+\z/D', $table) === 1, 'Unexpected portability table identifier');
            $tableRows = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
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
    };

    $tables = [
        'candidates',
        'companies',
        'applications',
        'events',
        'company_rounds',
        'round_schedules',
        'round_panelists',
        'application_slot_assignments',
        'candidate_unavailability_windows',
        'preference_requests',
        'preference_options',
        'wanted_alerts',
        'placement_offers',
        'workflow_transition_events',
        'advising_appointments',
        'advising_notes',
        'advising_tasks',
    ];
    $sourceCounts = [];
    foreach ($tables as $table) {
        $sourceCounts[$table] = (int) $sourcePdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    $sourceCandidateIds = $sourcePdo->query('SELECT public_id FROM candidates ORDER BY public_id')->fetchAll(PDO::FETCH_COLUMN);
    $sourceApplicationIds = $sourcePdo->query('SELECT public_id FROM applications ORDER BY public_id')->fetchAll(PDO::FETCH_COLUMN);
    $sourcePdo->exec(
        "UPDATE institutions SET created_at = '1999-01-01 00:00:00', updated_at = '2000-01-01 00:00:00' WHERE slug = 'default'",
    );
    $sourceInstitution = $sourcePdo->query(
        "SELECT public_id, created_at, updated_at FROM institutions WHERE slug = 'default'",
    )->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($sourceInstitution), 'Source institution identity should exist');

    try {
        $export = (new PortalPortabilityService($sourcePdo))->export($bundle);
        assert_true(is_file($bundle . '/manifest.json'), 'Bundle manifest should be written');
        assert_true(is_file($bundle . '/core.json'), 'Bundle core payload should be written');
        assert_true(is_file($bundle . '/modules/placement.json'), 'Placement module payload should be written');
        assert_true(is_file($bundle . '/modules/advising.json'), 'Advising module payload should be written');
        $bundleText = (string) file_get_contents($bundle . '/core.json')
            . (string) file_get_contents($bundle . '/modules/placement.json')
            . (string) file_get_contents($bundle . '/modules/advising.json');
        assert_true(!str_contains($bundleText, 'admin@test.local'), 'Bundle should exclude user accounts');
        assert_true(!str_contains($bundleText, '"password_hash":'), 'Bundle should exclude password hash fields');
        assert_true(!str_contains($bundleText, '$2y$'), 'Bundle should exclude bcrypt password values');

        $placementPath = $bundle . '/modules/placement.json';
        $manifestPath = $bundle . '/manifest.json';
        $originalPlacementJson = (string) file_get_contents($placementPath);
        $originalManifestJson = (string) file_get_contents($manifestPath);
        $assertPublicIdTamperRejected = static function (
            string $collection,
            string $invalidPublicId,
            string $label,
        ) use ($placementPath, $manifestPath, $originalPlacementJson, $originalManifestJson, $sourcePdo, $bundle): void {
            $payload = json_decode($originalPlacementJson, true, 512, JSON_THROW_ON_ERROR);
            assert_true(is_array($payload[$collection] ?? null) && isset($payload[$collection][0]), 'Tamper fixture lacks ' . $collection);
            $payload[$collection][0]['public_id'] = $invalidPublicId;
            $tamperedJson = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n";
            file_put_contents($placementPath, $tamperedJson);
            $manifest = json_decode($originalManifestJson, true, 64, JSON_THROW_ON_ERROR);
            foreach ($manifest['files'] as &$file) {
                if (($file['path'] ?? null) === 'modules/placement.json') {
                    $file['bytes'] = strlen($tamperedJson);
                    $file['sha256'] = hash('sha256', $tamperedJson);
                }
            }
            unset($file);
            file_put_contents(
                $manifestPath,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );
            try {
                (new PortalPortabilityService($sourcePdo))->validate($bundle);
                throw new RuntimeException('Tampered ' . $label . ' public id was accepted.');
            } catch (RuntimeException $e) {
                assert_true(
                    str_contains($e->getMessage(), 'invalid ' . $label . ' public id'),
                    'Tampered ' . $label . ' public id did not fail at canonical-id validation',
                );
            } finally {
                file_put_contents($placementPath, $originalPlacementJson);
                file_put_contents($manifestPath, $originalManifestJson);
            }
        };
        $assertPublicIdTamperRejected('applications', 'application_' . str_repeat('g', 32), 'application');
        $assertPublicIdTamperRejected('candidates', 'candidate_' . str_repeat('a', 31), 'candidate');
        $assertPublicIdTamperRejected('companies', 'company_' . str_repeat('A', 32), 'company');

        Database::useProvider(new SqliteConnectionProvider($targetDb));
        (new Installer())->install([
            'college_name' => 'Empty Target College',
            'timezone' => 'UTC',
            'admin_name' => 'Target Administrator',
            'admin_email' => 'target-admin@test.local',
            'admin_password' => 'target-password-123',
        ]);
        putenv('CPE_BACKUP_DIR=' . $backupDir);
        $targetPdo = Database::connection();
        $targetInstitutionBefore = $targetPdo->query(
            "SELECT public_id, created_at, updated_at FROM institutions WHERE slug = 'default'",
        )->fetch(PDO::FETCH_ASSOC);
        assert_true(is_array($targetInstitutionBefore), 'Target institution identity should exist');
        $targetSchemaBefore = $targetPdo->query(
            "SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name",
        )->fetchAll(PDO::FETCH_ASSOC);
        $service = new PortalPortabilityService($targetPdo);
        $validation = $service->validate($bundle);
        assert_same($export['bundle_id'], $validation['bundle_id'], 'Bundle id should validate');
        $result = $service->import($bundle);
        assert_true(preg_match('/^backup_[a-f0-9]{24}$/', (string) $result['safety_reference']) === 1, 'Import should return an opaque safety reference');
        assert_true(count(glob($backupDir . '/*.{sqlite,pgdump}', GLOB_BRACE) ?: []) >= 1, 'Import should create a pre-import backup');
        assert_true(count(glob($backupDir . '/*.{sqlite,pgdump}.sha256', GLOB_BRACE) ?: []) >= 1, 'Import backup should have a checksum');

        foreach ($sourceCounts as $table => $count) {
            assert_same($count, (int) $targetPdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), 'Round-trip count for ' . $table);
        }
        assert_same($sourceCandidateIds, $targetPdo->query('SELECT public_id FROM candidates ORDER BY public_id')->fetchAll(PDO::FETCH_COLUMN), 'Candidate public ids should survive round trip');
        assert_same($sourceApplicationIds, $targetPdo->query('SELECT public_id FROM applications ORDER BY public_id')->fetchAll(PDO::FETCH_COLUMN), 'Application public ids should survive round trip');
        $targetInstitutionAfter = $targetPdo->query(
            "SELECT public_id, created_at, updated_at FROM institutions WHERE slug = 'default'",
        )->fetch(PDO::FETCH_ASSOC);
        assert_true(is_array($targetInstitutionAfter), 'Imported target institution identity should exist');
        assert_same($targetInstitutionBefore['public_id'], $targetInstitutionAfter['public_id'], 'Portability must preserve the installed target public id');
        assert_same($targetInstitutionBefore['created_at'], $targetInstitutionAfter['created_at'], 'Portability must preserve the installed target creation time');
        assert_true($targetInstitutionAfter['public_id'] !== $sourceInstitution['public_id'], 'Cross-instance portability must not copy the source institution id');
        assert_true($targetInstitutionAfter['updated_at'] !== $sourceInstitution['updated_at'], 'Portability must not copy the source institution update time');
        assert_same(
            $targetSchemaBefore,
            $targetPdo->query("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC),
            'Logical portability must not change the target schema',
        );
        assert_true(Auth::attempt('target-admin@test.local', 'target-password-123'), 'Target administrator should remain local to the target installation');

        $transitionApplication = $targetPdo->query(
            "SELECT id, public_id, current_status, aggregate_version
             FROM applications
             WHERE current_status NOT IN ('placed', 'rejected')
             ORDER BY CASE WHEN current_status = 'idle' THEN 0 ELSE 1 END, id
             LIMIT 1",
        )->fetch(PDO::FETCH_ASSOC);
        assert_true(is_array($transitionApplication), 'Imported portability data has no transitionable application');
        $publicEventsBeforeTransition = (int) $targetPdo->query(
            'SELECT COUNT(*) FROM domain_event_outbox WHERE public_event_type IS NOT NULL',
        )->fetchColumn();
        (new PlacementService($targetPdo))->moveNext(
            (int) $transitionApplication['id'],
            1,
            'admin',
            'Post-portability public event contract',
            (string) $transitionApplication['current_status'],
        );
        assert_same(
            (int) $transitionApplication['aggregate_version'] + 1,
            (int) $targetPdo->query(
                'SELECT aggregate_version FROM applications WHERE id = ' . (int) $transitionApplication['id'],
            )->fetchColumn(),
            'Imported application aggregate version did not advance after a real status change',
        );
        $restoredProjection = $targetPdo->query(
            'SELECT public_aggregate_id, public_aggregate_version
             FROM domain_event_outbox WHERE public_event_type IS NOT NULL ORDER BY id DESC LIMIT 1',
        )->fetch(PDO::FETCH_ASSOC);
        assert_true(is_array($restoredProjection), 'Imported application status change did not publish a public event');
        assert_same($transitionApplication['public_id'], $restoredProjection['public_aggregate_id'], 'Restored application published a noncanonical aggregate id');
        assert_same((int) $transitionApplication['aggregate_version'] + 1, (int) $restoredProjection['public_aggregate_version'], 'Restored application published the wrong aggregate version');
        assert_same($publicEventsBeforeTransition + 1, (int) $targetPdo->query('SELECT COUNT(*) FROM domain_event_outbox WHERE public_event_type IS NOT NULL')->fetchColumn(), 'Post-portability transition did not emit exactly one public event');

        Database::useProvider(new SqliteConnectionProvider($hostedTargetDb));
        (new Installer())->installHosted([
            'college_name' => 'Hosted Target College',
            'timezone' => 'UTC',
            'admin_name' => 'Hosted Target Administrator',
            'admin_email' => 'hosted-target-admin@example.test',
            'admin_password' => 'hosted-target-password-123',
            'seed_demo' => '1',
        ], 'tenant_' . str_repeat('c', 32));
        putenv('CPE_BACKUP_DIR=' . $hostedBackupDir);
        $hostedTargetPdo = Database::connection();
        $hostedTargetBefore = $snapshot($hostedTargetPdo);
        try {
            (new PortalPortabilityService($hostedTargetPdo))->import($bundle);
            throw new RuntimeException('Hosted target accepted a self-hosted portability bundle.');
        } catch (RuntimeException $e) {
            assert_true(
                str_contains($e->getMessage(), 'not compatible'),
                'Hosted portability mismatch should fail with fixed identity guidance',
            );
        }
        assert_same($hostedTargetBefore, $snapshot($hostedTargetPdo), 'Hosted portability identity rejection must be exactly non-mutating');
        assert_true(!is_dir($hostedBackupDir) || (glob($hostedBackupDir . '/*') ?: []) === [], 'Hosted identity mismatch must fail before creating a safety backup');

        Database::useProvider(new SqliteConnectionProvider($targetDb));
        $service = new PortalPortabilityService(Database::connection());

        file_put_contents($bundle . '/modules/placement.json', "\n", FILE_APPEND);
        try {
            $service->validate($bundle);
            throw new RuntimeException('Expected portability checksum failure.');
        } catch (RuntimeException $e) {
            assert_true(
                str_contains($e->getMessage(), 'checksum') || str_contains($e->getMessage(), 'size'),
                'Tampered bundle should fail its integrity metadata'
            );
        }
    } finally {
        putenv('CPE_BACKUP_DIR');
        Database::useProvider($sourceProvider);
        Portal::reset();
        remove_tree($root);
    }
});

test_case('hosted portability wrapper validates and round trips one exact tenant identity', function (): void {
    $originalProvider = Database::provider();
    $root = sys_get_temp_dir() . '/cpe-hosted-portability-' . bin2hex(random_bytes(4));
    $sourceDb = $root . '/source.sqlite';
    $targetDb = $root . '/target.sqlite';
    $bundle = $root . '/bundle';
    $backupDir = $root . '/backups';
    $tenantPublicId = 'tenant_' . str_repeat('e', 32);
    mkdir($root, 0775, true);
    try {
        Database::useProvider(new SqliteConnectionProvider($sourceDb));
        (new Installer())->installHosted([
            'college_name' => 'Hosted Portability Source',
            'timezone' => 'UTC',
            'admin_name' => 'Hosted Source Administrator',
            'admin_email' => 'hosted-source@example.test',
            'admin_password' => 'hosted-source-password-123',
            'seed_demo' => '1',
        ], $tenantPublicId);
        $sourcePdo = Database::connection();
        $sourceApplications = $sourcePdo->query(
            'SELECT public_id, aggregate_version FROM applications ORDER BY public_id',
        )->fetchAll(PDO::FETCH_ASSOC);
        $export = (new PortalPortabilityService($sourcePdo))->export($bundle);

        Database::useProvider(new SqliteConnectionProvider($targetDb));
        (new Installer())->installHosted([
            'college_name' => 'Hosted Portability Target',
            'timezone' => 'UTC',
            'admin_name' => 'Hosted Target Administrator',
            'admin_email' => 'hosted-target-roundtrip@example.test',
            'admin_password' => 'hosted-target-roundtrip-password-123',
            'seed_demo' => '0',
        ], $tenantPublicId);
        putenv('CPE_BACKUP_DIR=' . $backupDir);
        $targetPdo = Database::connection();
        $portability = new PortalPortabilityService($targetPdo);
        $validation = $portability->validate($bundle);
        assert_same($export['bundle_id'], $validation['bundle_id'], 'Hosted wrapper validation changed the bundle id');
        assert_same($tenantPublicId, $validation['institution_public_id'], 'Hosted wrapper validation changed tenant identity');
        $portability->import($bundle);
        assert_same($tenantPublicId, (string) $targetPdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn(), 'Hosted portability changed target tenant identity');
        assert_same($sourceApplications, $targetPdo->query('SELECT public_id, aggregate_version FROM applications ORDER BY public_id')->fetchAll(PDO::FETCH_ASSOC), 'Hosted wrapper roundtrip changed application public ids or aggregate versions');
        assert_same(0, (int) $targetPdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn(), 'Hosted portability synthesized event rows');
    } finally {
        putenv('CPE_BACKUP_DIR');
        Database::useProvider($originalProvider);
        Portal::reset();
        remove_tree($root);
    }
});

test_case('portable custom workflows validate before target import and remain active after round trip', function (): void {
    $originalProvider = Database::provider();
    $root = sys_get_temp_dir() . '/cpe-custom-workflow-portability-' . bin2hex(random_bytes(4));
    $sourceDb = $root . '/source.sqlite';
    $targetDb = $root . '/target.sqlite';
    $bundle = $root . '/bundle';
    mkdir($root, 0775, true);
    try {
        Database::useProvider(new SqliteConnectionProvider($sourceDb));
        (new Installer())->install([
            'college_name' => 'Custom Workflow Source',
            'timezone' => 'UTC',
            'admin_name' => 'Source Administrator',
            'admin_email' => 'source-admin@test.local',
            'admin_password' => 'source-password-123',
        ]);
        $sourcePdo = Database::connection();
        $repository = new WorkflowRepository($sourcePdo);
        $defaultVersion = (int) $repository->activeVersionId('default');
        $definitionPayload = (new WorkflowDefinitionFileService($sourcePdo))->payloadForVersion($defaultVersion);
        $definitionPayload['definition']['name'] = 'Custom Contract Workflow';
        $definitionPayload['definition']['source_template_key'] = 'custom_contract';
        $sourcePdo->prepare("UPDATE settings SET value = ? WHERE key = 'workflow'")->execute(['custom_contract']);
        (new WorkflowPublisher($sourcePdo))->publish('custom_contract', $definitionPayload['definition'], 'test', 1, true);
        (new PortalPortabilityService($sourcePdo))->export($bundle);

        Database::useProvider(new SqliteConnectionProvider($targetDb));
        (new Installer())->install([
            'college_name' => 'Custom Workflow Target',
            'timezone' => 'UTC',
            'admin_name' => 'Target Administrator',
            'admin_email' => 'target-custom@test.local',
            'admin_password' => 'target-password-123',
        ]);
        $targetPdo = Database::connection();
        $portability = new PortalPortabilityService($targetPdo);
        $portability->validate($bundle);
        $portability->import($bundle);
        assert_same('custom_contract', (string) $targetPdo->query("SELECT value FROM settings WHERE key = 'workflow'")->fetchColumn(), 'Custom workflow setting should survive portability');
        $targetRepository = new WorkflowRepository($targetPdo);
        assert_true($targetRepository->activeVersionId('custom_contract') !== null, 'Custom workflow definition should be active on the target');
        assert_same('custom_contract', (string) $targetRepository->workflowForCurrentCycle()['key'], 'Target cycle should bind the custom workflow');
    } finally {
        Database::useProvider($originalProvider);
        Portal::reset();
        remove_tree($root);
    }
});

test_case('career advising privacy handler reports and redacts module-owned records', function (): void {
    $pdo = Database::connection();
    $personPublicId = (string) $pdo->query(
        'SELECT p.public_id FROM people p
         JOIN student_profiles sp ON sp.person_id = p.id
         JOIN advising_appointments a ON a.student_profile_id = sp.id
         ORDER BY a.id LIMIT 1'
    )->fetchColumn();
    $privacy = new AdvisingPrivacyHandler($pdo);
    $report = $privacy->reportForPerson($personPublicId);
    assert_true($report['found'], 'Advising privacy report should find the person');
    assert_same(1, $report['records']['appointments'], 'Advising privacy report appointment count');
    assert_same(1, $report['records']['staff_notes'], 'Advising privacy report note count');
    assert_same(1, $report['records']['tasks'], 'Advising privacy report task count');
    $result = $privacy->erasePerson($personPublicId, 'Test erasure contract');
    assert_same(1, $result['appointments'], 'Advising privacy erasure appointment count');
    assert_same('[redacted by privacy erasure]', (string) $pdo->query('SELECT body FROM advising_notes ORDER BY id LIMIT 1')->fetchColumn(), 'Advising note should be redacted');
    assert_same('', (string) $pdo->query('SELECT subject_reference FROM advising_tasks ORDER BY id LIMIT 1')->fetchColumn(), 'Advising task subject should be redacted');
});

test_case('portal privacy orchestrates every installed module under one safety backup', function (): void {
    $pdo = Database::connection();
    $placement = new PlacementService($pdo);
    $candidateId = $placement->saveCandidate(['external_id' => 'PORTALPRV001', 'name' => 'Portal Privacy Person', 'program' => 'MBA'], 1);
    $studentId = (int) $pdo->query(
        "SELECT sp.id FROM student_profiles sp JOIN people p ON p.id = sp.person_id WHERE p.legacy_candidate_id = {$candidateId}"
    )->fetchColumn();
    $appointmentId = (new AdvisingService($pdo))->createAppointment([
        'student_profile_id' => $studentId,
        'adviser_user_id' => 1,
        'appointment_status' => 'scheduled',
        'starts_at' => '2026-08-02T10:00',
        'ends_at' => '2026-08-02T10:30',
        'appointment_mode' => 'phone',
        'topic' => 'Portal privacy contract',
        'student_notes' => 'Sensitive student note.',
    ], 1);
    (new AdvisingService($pdo))->addNote($appointmentId, 'Sensitive staff note.', 1);
    $personPublicId = (string) $pdo->query("SELECT public_id FROM people WHERE legacy_candidate_id = {$candidateId}")->fetchColumn();
    $privacy = new PortalPrivacyService($pdo);
    $report = $privacy->report($personPublicId);
    assert_true(isset($report['modules']['placement'], $report['modules']['advising']), 'Portal privacy report should include every installed module');
    $result = $privacy->erase($personPublicId, 'Portal privacy integration test', 1);
    assert_true(preg_match('/^backup_[a-f0-9]{24}$/', (string) $result['safety_reference']) === 1, 'Portal privacy erasure should return an opaque safety reference');
    $privacyDir = (string) getenv('CPE_PRIVACY_SNAPSHOT_DIR');
    assert_true(count(glob($privacyDir . '/*.{sqlite,pgdump}', GLOB_BRACE) ?: []) >= 1, 'Portal privacy erasure should create one safety backup');
    assert_true(count(glob($privacyDir . '/*.{sqlite,pgdump}.sha256', GLOB_BRACE) ?: []) >= 1, 'Portal privacy safety backup should have a checksum');
    assert_true(isset($result['modules']['placement'], $result['modules']['advising']), 'Portal privacy erasure should invoke every installed module');
    assert_same('Anonymized Person', (string) $pdo->query("SELECT display_name FROM people WHERE public_id = " . $pdo->quote($personPublicId))->fetchColumn(), 'Core person should be anonymized');
    assert_same('[redacted by privacy erasure]', (string) $pdo->query("SELECT body FROM advising_notes WHERE appointment_id = {$appointmentId}")->fetchColumn(), 'Advising data should be redacted by portal erasure');
});

test_case('legacy placement records have complete durable domain counterparts', function (): void {
    $pdo = Database::connection();
    assert_same(
        (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM people')->fetchColumn(),
        'Each legacy candidate should map to one person'
    );
    assert_same(
        (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM student_profiles')->fetchColumn(),
        'Each legacy candidate should map to one student profile'
    );
    assert_same(
        (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM placement_cycle_participants')->fetchColumn(),
        'Each legacy candidate should map to one cycle participant'
    );
    assert_same(
        (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn(),
        'Each legacy company should map to one organization'
    );
    assert_same(
        (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM placement_opportunities')->fetchColumn(),
        'Each legacy company should map to one placement opportunity'
    );
    assert_same(
        0,
        (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE public_id IS NULL OR public_id = '' OR participant_id IS NULL OR opportunity_id IS NULL")->fetchColumn(),
        'Every application should have a public id and durable participant/opportunity links'
    );
    assert_same(
        (int) $pdo->query('SELECT COUNT(*) FROM placement_cycle_participants')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM placement_presence')->fetchColumn(),
        'Each cycle participant should have a durable presence record'
    );
    assert_same(
        (int) $pdo->query('SELECT COUNT(*) FROM candidates WHERE placed_company_id IS NOT NULL')->fetchColumn(),
        (int) $pdo->query("SELECT COUNT(*) FROM placement_offers WHERE source = 'legacy_projection' AND offer_status = 'accepted'")->fetchColumn(),
        'Each accepted legacy placement should map to a durable offer'
    );
});

test_case('published workflows are immutable pinned and branch capable', function (): void {
    $pdo = Database::connection();
    $repository = new WorkflowRepository($pdo);
    assert_same(
        (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM workflow_instances')->fetchColumn(),
        'Every migrated application should have a workflow instance'
    );
    assert_same(
        0,
        (int) $pdo->query('SELECT COUNT(*) FROM applications WHERE workflow_version_id IS NULL')->fetchColumn(),
        'Every migrated application should be pinned to a workflow version'
    );

    $existingApplicationId = (int) $pdo->query('SELECT id FROM applications ORDER BY id LIMIT 1')->fetchColumn();
    $existingVersionId = (int) $pdo->query("SELECT workflow_version_id FROM applications WHERE id = {$existingApplicationId}")->fetchColumn();
    $publisher = new WorkflowPublisher($pdo);
    $definition = $publisher->fromTemplate('default', cpe_config('workflows.default'));
    $definition['states']['rejected'] = [
        'label' => 'Rejected',
        'order' => 12,
        'color' => '#f3c4c4',
        'semantic_category' => 'rejected',
        'is_terminal' => true,
    ];
    $definition['transitions'][] = [
        'key' => 'reject_after_interview',
        'label' => 'Mark rejected',
        'from' => 'inside',
        'to' => 'rejected',
        'required_capability' => 'placement.application.transition',
        'roles' => ['company', 'placement', 'admin'],
        'guards' => ['candidate.not_opted_out'],
        'effects' => ['application.set_state'],
        'order' => 50,
        'is_correction' => false,
    ];
    assert_same([], (new WorkflowDefinitionValidator())->validate($definition), 'Branched definition should validate');

    $pdo->beginTransaction();
    try {
        $branchedVersionId = $publisher->publish('default', $definition, 'test', 1, true);
        assert_true($branchedVersionId !== $existingVersionId, 'Publishing a changed definition should create a new version');
        assert_same(
            $existingVersionId,
            (int) $pdo->query("SELECT workflow_version_id FROM applications WHERE id = {$existingApplicationId}")->fetchColumn(),
            'Existing application should remain pinned to its original version'
        );

        $service = new PlacementService($pdo);
        $candidateId = $service->saveCandidate(['external_id' => 'WFV001', 'name' => 'Versioned Workflow Candidate'], 1);
        $companyId = $service->saveCompany(['code' => 'WFV', 'name' => 'Versioned Workflow Company'], 1);
        $service->saveApplication($candidateId, $companyId, 'inside', null, 1);
        $newApplicationId = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$companyId}")->fetchColumn();
        assert_same(
            $branchedVersionId,
            (int) $pdo->query("SELECT workflow_version_id FROM applications WHERE id = {$newApplicationId}")->fetchColumn(),
            'New application should pin the newly active version'
        );
        $actions = (new WorkflowEngine($pdo))->availableTransitions($newApplicationId, 'company');
        assert_same(2, count($actions), 'Branched state should expose both permitted named transitions');
        assert_true(in_array('reject_after_interview', array_column($actions, 'key'), true), 'Named rejection branch should be available');
    } finally {
        $pdo->rollBack();
    }

    assert_same($existingVersionId, (int) $repository->activeVersionId('default'), 'Rollback should restore the active workflow version');
});

test_case('workflow definitions export simulate and migrate explicitly', function (): void {
    $pdo = Database::connection();
    $repository = new WorkflowRepository($pdo);
    $activeVersionId = (int) $repository->activeVersionId('default');
    $workflow = $repository->workflowForVersion($activeVersionId);
    assert_true($workflow !== null, 'Active workflow should be readable');

    $standardTransitions = array_values(array_filter(
        $workflow['transitions'],
        fn (array $transition): bool => empty($transition['is_correction'])
    ));
    $simulation = (new WorkflowSimulationService($pdo))->simulate(
        $activeVersionId,
        array_map(fn (array $transition): array => [
            'transition_key' => $transition['key'],
            'actor_role' => 'admin',
        ], $standardTransitions)
    );
    assert_true($simulation['terminal'], 'Default workflow simulation should reach a terminal state');
    assert_same('placed', $simulation['end_state'], 'Default workflow simulation should finish placed');

    $path = sys_get_temp_dir() . '/cpe-workflow-' . bin2hex(random_bytes(4)) . '.json';
    $fileService = new WorkflowDefinitionFileService($pdo);
    try {
        $fileService->export($activeVersionId, $path);
        $validated = $fileService->validate($path);
        assert_same(count($workflow['states']), $validated['states'], 'Workflow export should preserve states');
        assert_same(count($workflow['transitions']), $validated['transitions'], 'Workflow export should preserve transitions');
        assert_same($activeVersionId, $fileService->publish($path, 1, true), 'Publishing identical workflow JSON should reuse its immutable version');
    } finally {
        @unlink($path);
    }

    $publisher = new WorkflowPublisher($pdo);
    $definition = $publisher->fromTemplate('default', cpe_config('workflows.default'));
    $definition['states']['scheduled']['label'] = 'Migration Ready';
    $pdo->beginTransaction();
    try {
        $targetVersionId = $publisher->publish('default', $definition, 'test', 1, true);
        $migration = new WorkflowMigrationService($pdo);
        $preview = $migration->preview($targetVersionId);
        assert_same((int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn(), $preview['applications'], 'Migration preview should count applications outside target version');
        assert_same([], $preview['unmapped_states'], 'Same-key workflow migration should not require mappings');
        $result = $migration->migrate($targetVersionId, [], 1, 'admin', 'Test explicit version migration');
        assert_same($preview['applications'], $result['migrated'], 'Migration should apply the previewed application set');
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE workflow_version_id != {$targetVersionId}")->fetchColumn(), 'Migration should repin every selected application');
        assert_same($result['migrated'], (int) $pdo->query("SELECT COUNT(*) FROM workflow_transition_events WHERE transition_key = 'workflow_version_migration'")->fetchColumn(), 'Migration should create explicit workflow history events');
    } finally {
        $pdo->rollBack();
    }
});

test_case('installer refuses to mutate an already installed database', function (): void {
    $pdo = Database::connection();
    $userCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    try {
        (new Installer())->install([
            'college_name' => 'Second Install College',
            'timezone' => 'Asia/Kolkata',
            'admin_name' => 'Second Admin',
            'admin_email' => 'second-admin@example.test',
            'admin_password' => 'password123',
        ]);
        throw new RuntimeException('Expected installed database guard.');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'already installed'), 'Installer should explain the install lock');
    }
    assert_same($userCountBefore, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'Install lock should not create another user');
    assert_same('Test College', $pdo->query("SELECT value FROM settings WHERE key = 'college_name'")->fetchColumn(), 'Install lock should not overwrite settings');
});

test_case('first-run installer guides live dummy data and system cleanup clears only dummy rows', function (): void {
    $installHtml = render_view_for_test('install', [
        'requirements' => (new SystemRequirements())->checks(),
        'requirementsOk' => true,
        'databasePath' => Database::path(),
    ]);
    foreach (['Setup path', 'Start with a fully live dummy database', 'Clear dummy data from System', 'Install and open board'] as $snippet) {
        assert_true(str_contains($installHtml, $snippet), 'Installer should guide first-run setup with: ' . $snippet);
    }

    $pdo = Database::connection();
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'admin@test.local'")->fetchColumn();
    $_SESSION['user_id'] = $adminId;
    try {
        $systemHtml = render_view_for_test('system', [
            'dbPath' => Database::path(),
            'phpVersion' => PHP_VERSION,
            'databaseDriver' => Database::driver(),
            'databaseVersion' => Database::serverVersion(),
            'workflowErrors' => (new Workflow())->validate(),
            'readiness' => (new ReadinessService($pdo))->snapshot(),
            'demoData' => (new DemoDataService($pdo))->counts(),
            'audit' => [],
        ]);
    } finally {
        unset($_SESSION['user_id']);
    }
    foreach (['Dummy data cleanup', 'Clear dummy data', 'C001', 'QAC###', 'installed app'] as $snippet) {
        assert_true(str_contains($systemHtml, $snippet), 'System page should explain dummy cleanup with: ' . $snippet);
    }
});

test_case('demo data cleanup preserves install settings admin users and real records', function (): void {
    $oldDb = getenv('CPE_DB_PATH') ?: '';
    $db = sys_get_temp_dir() . '/cpe-demo-cleanup-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        putenv('CPE_DB_PATH=' . $db);
        Database::reset();
        $adminId = (new Installer())->install([
            'college_name' => 'Cleanup College',
            'admin_name' => 'Cleanup Admin',
            'admin_email' => 'cleanup-admin@example.test',
            'admin_password' => 'password123',
            'seed_demo' => '1',
        ]);
        $pdo = Database::connection();
        $service = new PlacementService($pdo);
        $service->seedLargeDemo(12, 3);
        $realCandidateId = $service->saveCandidate(['external_id' => 'REAL001', 'name' => 'Real Candidate', 'program' => 'MBA'], $adminId);
        $realCompanyId = $service->saveCompany(['code' => 'REAL', 'name' => 'Real Company'], $adminId);
        $service->saveApplication($realCandidateId, $realCompanyId, 'scheduled', null, $adminId);

        $demo = new DemoDataService($pdo);
        $before = $demo->counts();
        assert_true($before['candidates'] >= 17, 'Cleanup fixture should contain small and large dummy candidates');
        assert_true($before['companies'] >= 6, 'Cleanup fixture should contain small and large dummy companies');
        assert_true($before['demo_users'] >= 6, 'Cleanup fixture should contain demo users');

        $result = $demo->clear($adminId);
        assert_true($result['deleted']['candidates'] >= 17, 'Cleanup should remove dummy candidates');
        assert_true($result['deleted']['companies'] >= 6, 'Cleanup should remove dummy companies');
        assert_true($result['deleted']['demo_users'] >= 6, 'Cleanup should remove demo users');
        assert_same(0, array_sum($demo->counts()), 'Cleanup should leave no reserved dummy rows');
        assert_same('Cleanup College', $pdo->query("SELECT value FROM settings WHERE key = 'college_name'")->fetchColumn(), 'Cleanup should preserve install settings');
        assert_same('1', (string) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'cleanup-admin@example.test' AND role = 'admin'")->fetchColumn(), 'Cleanup should preserve admin user');
        assert_same('0', $pdo->query("SELECT value FROM settings WHERE key = 'synthetic_demo_data_loaded'")->fetchColumn(), 'Cleanup should mark dummy data unloaded');
        assert_same('1', (string) $pdo->query("SELECT COUNT(*) FROM candidates WHERE external_id = 'REAL001'")->fetchColumn(), 'Cleanup should preserve real candidate');
        assert_same('1', (string) $pdo->query("SELECT COUNT(*) FROM companies WHERE code = 'REAL'")->fetchColumn(), 'Cleanup should preserve real company');
        assert_same('1', (string) $pdo->query("SELECT COUNT(*) FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.external_id = 'REAL001'")->fetchColumn(), 'Cleanup should preserve real application');
        assert_same('0', (string) $pdo->query("SELECT COUNT(*) FROM candidates WHERE external_id IN ('C001', 'C002', 'C003', 'C004', 'C005') OR external_id GLOB 'QAC[0-9][0-9][0-9]'")->fetchColumn(), 'Cleanup should remove reserved dummy candidate ids');
        assert_same('0', (string) $pdo->query("SELECT COUNT(*) FROM companies WHERE code IN ('ATLAS', 'NOVA', 'RIVER') OR code GLOB 'QA[0-9][0-9]'")->fetchColumn(), 'Cleanup should remove reserved dummy company codes');
        assert_true((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'demo_data.clear'")->fetchColumn() >= 1, 'Cleanup should write an audit entry');
    } finally {
        putenv('CPE_DB_PATH=' . $oldDb);
        Database::reset();
        if (is_file($db)) {
            unlink($db);
        }
    }
});

test_case('workflow validation passes', function (): void {
    assert_same([], (new Workflow())->validate(), 'Workflow should be valid');
    $available = Workflow::available();
    foreach (['default', 'engineering_multi_branch', 'internship_season', 'simple_placement_cell', 'pooled_campus_drive', 'virtual_interview_process', 'walk_in_job_fair'] as $key) {
        assert_true(isset($available[$key]), "Missing starter workflow: {$key}");
        assert_same([], (new Workflow(null, $key))->validate(), "Workflow should be valid: {$key}");
    }
});

test_case('cycle settings migration backfills upgraded installs', function (): void {
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM settings WHERE key IN ('cycle_name', 'cycle_type', 'cycle_start_date', 'cycle_end_date')");
    $pdo->exec("DELETE FROM migrations WHERE migration = '020_cycle_settings.sql'");
    Database::migrate();
    assert_same('Test College Placement Cycle', $pdo->query("SELECT value FROM settings WHERE key = 'cycle_name'")->fetchColumn(), 'Cycle migration should derive name from college');
    assert_same('final', $pdo->query("SELECT value FROM settings WHERE key = 'cycle_type'")->fetchColumn(), 'Cycle migration should default type');
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'cycle_start_date'")->fetchColumn(), 'Cycle migration should create empty start date');
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'cycle_end_date'")->fetchColumn(), 'Cycle migration should create empty end date');
});

test_case('board refresh migration backfills upgraded installs', function (): void {
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM settings WHERE key = 'board_refresh_seconds'");
    $pdo->exec("DELETE FROM migrations WHERE migration = '022_board_refresh_setting.sql'");
    Database::migrate();
    assert_same('45', $pdo->query("SELECT value FROM settings WHERE key = 'board_refresh_seconds'")->fetchColumn(), 'Board refresh migration should default to 45 seconds');
});

test_case('board route field migration upgrades only known default card lists', function (): void {
    $pdo = Database::connection();
    $oldDefault = 'candidate_id,program,tags,company,process,tracker,active_cap,rounds,schedule,slot,panel,location,accommodation,waitlist';
    $newDefault = 'candidate_id,program,tags,company,process,tracker,active_cap,rounds,schedule,slot,panel,route,location,accommodation,waitlist';
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');

    $set->execute(['board_card_fields', $oldDefault]);
    $pdo->exec("DELETE FROM migrations WHERE migration = '032_board_route_field.sql'");
    Database::migrate();
    assert_same($newDefault, $pdo->query("SELECT value FROM settings WHERE key = 'board_card_fields'")->fetchColumn(), 'Board route migration should add route to old default card list');

    $set->execute(['board_card_fields', 'candidate_id,company,location']);
    $pdo->exec("DELETE FROM migrations WHERE migration = '032_board_route_field.sql'");
    Database::migrate();
    assert_same('candidate_id,company,location', $pdo->query("SELECT value FROM settings WHERE key = 'board_card_fields'")->fetchColumn(), 'Board route migration should preserve custom card lists');
});

test_case('custom fields migration adds candidate and company extension columns', function (): void {
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM migrations WHERE migration = '033_custom_fields_json.sql'");
    $pdo->exec('CREATE TABLE candidate_custom_field_backup AS SELECT id, custom_fields_json FROM candidates');
    $pdo->exec('CREATE TABLE company_custom_field_backup AS SELECT id, custom_fields_json FROM companies');
    $pdo->exec('ALTER TABLE candidates DROP COLUMN custom_fields_json');
    $pdo->exec('ALTER TABLE companies DROP COLUMN custom_fields_json');
    Database::migrate();
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('candidates') WHERE name = 'custom_fields_json'")->fetchColumn(), 'Custom fields migration should add candidate column');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('companies') WHERE name = 'custom_fields_json'")->fetchColumn(), 'Custom fields migration should add company column');
    assert_same('{}', $pdo->query("SELECT custom_fields_json FROM candidates ORDER BY id LIMIT 1")->fetchColumn(), 'Candidate custom fields should default to empty JSON');
    assert_same('{}', $pdo->query("SELECT custom_fields_json FROM companies ORDER BY id LIMIT 1")->fetchColumn(), 'Company custom fields should default to empty JSON');
    $pdo->exec('UPDATE candidates SET custom_fields_json = COALESCE((SELECT custom_fields_json FROM candidate_custom_field_backup b WHERE b.id = candidates.id), \'{}\')');
    $pdo->exec('UPDATE companies SET custom_fields_json = COALESCE((SELECT custom_fields_json FROM company_custom_field_backup b WHERE b.id = companies.id), \'{}\')');
    $pdo->exec('DROP TABLE candidate_custom_field_backup');
    $pdo->exec('DROP TABLE company_custom_field_backup');
});

test_case('idempotency key migration creates live action table', function (): void {
    $pdo = Database::connection();
    $pdo->exec('DROP TABLE IF EXISTS idempotency_keys');
    $pdo->exec("DELETE FROM migrations WHERE migration IN ('023_idempotency_keys.sql', '046_idempotency_results.sql')");
    Database::migrate();
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'idempotency_keys'")->fetchColumn(), 'Idempotency migration should create table');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('idempotency_keys') WHERE name = 'request_hash'")->fetchColumn(), 'Idempotency migration should add request hashes');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('idempotency_keys') WHERE name = 'result_json'")->fetchColumn(), 'Idempotency migration should add durable results');
});

test_case('configuration freeze migration defaults upgraded installs to mutable', function (): void {
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM settings WHERE key = 'configuration_freeze'");
    $pdo->exec("DELETE FROM migrations WHERE migration = '025_configuration_freeze.sql'");
    Database::migrate();
    assert_same('0', $pdo->query("SELECT value FROM settings WHERE key = 'configuration_freeze'")->fetchColumn(), 'Configuration freeze migration should default to off');
});

test_case('custom export profile migration defaults upgraded installs to summary handoff datasets', function (): void {
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM settings WHERE key = 'export_profile_custom_datasets'");
    $pdo->exec("DELETE FROM migrations WHERE migration = '026_custom_export_profile.sql'");
    Database::migrate();
    assert_same('placement_totals,application_status_counts,placements_by_company', $pdo->query("SELECT value FROM settings WHERE key = 'export_profile_custom_datasets'")->fetchColumn(), 'Custom export profile migration should default to safe summary datasets');
});

test_case('custom import aliases migration defaults upgraded installs to built-in mappings only', function (): void {
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM settings WHERE key = 'import_header_aliases_json'");
    $pdo->exec("DELETE FROM migrations WHERE migration = '027_custom_import_aliases.sql'");
    Database::migrate();
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'import_header_aliases_json'")->fetchColumn(), 'Custom import alias migration should default to empty JSON');
});

test_case('terminology labels migration defaults upgraded installs to generic vocabulary', function (): void {
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM settings WHERE key IN ('terminology_candidate_label', 'terminology_candidates_label', 'terminology_company_label', 'terminology_companies_label')");
    $pdo->exec("DELETE FROM migrations WHERE migration = '028_terminology_labels.sql'");
    Database::migrate();
    assert_same('Candidate', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_candidate_label'")->fetchColumn(), 'Terminology migration should default singular candidate label');
    assert_same('Candidates', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_candidates_label'")->fetchColumn(), 'Terminology migration should default plural candidate label');
    assert_same('Company', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_company_label'")->fetchColumn(), 'Terminology migration should default singular company label');
    assert_same('Companies', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_companies_label'")->fetchColumn(), 'Terminology migration should default plural company label');
});

test_case('text identity migration defaults upgraded installs to generic display names', function (): void {
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM settings WHERE key IN ('site_name', 'site_tagline', 'public_placements_title', 'candidate_status_title')");
    $pdo->exec("DELETE FROM migrations WHERE migration = '029_text_identity_settings.sql'");
    Database::migrate();
    assert_same('Campus Placement Engine', $pdo->query("SELECT value FROM settings WHERE key = 'site_name'")->fetchColumn(), 'Text identity migration should default site name');
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'site_tagline'")->fetchColumn(), 'Text identity migration should default empty tagline');
    assert_same('Public Placements', $pdo->query("SELECT value FROM settings WHERE key = 'public_placements_title'")->fetchColumn(), 'Text identity migration should default public placements title');
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'candidate_status_title'")->fetchColumn(), 'Text identity migration should default candidate title to dynamic');
});

test_case('calendar settings migration defaults upgraded installs to no calendar guardrails', function (): void {
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM settings WHERE key IN ('calendar_non_operating_weekdays', 'calendar_non_operating_dates')");
    $pdo->exec("DELETE FROM migrations WHERE migration = '030_calendar_settings.sql'");
    Database::migrate();
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'calendar_non_operating_weekdays'")->fetchColumn(), 'Calendar migration should default weekdays empty');
    assert_same('', $pdo->query("SELECT value FROM settings WHERE key = 'calendar_non_operating_dates'")->fetchColumn(), 'Calendar migration should default dates empty');
});

test_case('local terminology labels render without client-side dependencies', function (): void {
    $pdo = Database::connection();
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['terminology_candidate_label', 'Learner']);
    $set->execute(['terminology_candidates_label', 'Learners']);
    $set->execute(['terminology_company_label', 'Recruiter']);
    $set->execute(['terminology_companies_label', 'Recruiters']);

    assert_same('Learner', cpe_term('candidate'), 'Terminology helper should read custom singular candidate label');
    assert_same('Recruiters', cpe_term('companies'), 'Terminology helper should read custom plural company label');

    $html = render_view_for_test('student', [
        'externalId' => '',
        'studentData' => null,
        'workflow' => new Workflow(),
    ]);
    assert_true(str_contains($html, 'Learner Status'), 'Student status page should render custom candidate label');
    assert_true(str_contains($html, 'Learner ID'), 'Student status page should render custom candidate ID label');

    $html = render_view_for_test('public', [
        'placements' => [[
            'program' => 'MBA',
            'company_code' => 'REC',
            'company_name' => 'Example Recruiter',
            'placed_count' => 2,
        ]],
        'studentLookupAllowed' => false,
    ]);
    assert_true(str_contains($html, '<th>Recruiter</th>'), 'Public page should render custom company table label');
    assert_true(str_contains($html, '<th>Placements</th>'), 'Public page should render aggregate placement counts');
    assert_true(!str_contains($html, 'Example Learner') && !str_contains($html, 'L001'), 'Public page should not render candidate identities');

    $set->execute(['terminology_candidate_label', 'Candidate']);
    $set->execute(['terminology_candidates_label', 'Candidates']);
    $set->execute(['terminology_company_label', 'Company']);
    $set->execute(['terminology_companies_label', 'Companies']);
});

test_case('text identity labels render without frontend assets', function (): void {
    $pdo = Database::connection();
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['site_name', 'Placement Desk']);
    $set->execute(['site_tagline', 'Live operations']);
    $set->execute(['public_placements_title', 'Placement Results']);
    $set->execute(['candidate_status_title', '']);
    $set->execute(['terminology_candidate_label', 'Learner']);

    assert_same('Placement Desk', cpe_site_name(), 'Site helper should read custom site name');
    assert_same('Live operations', cpe_site_tagline(), 'Site helper should read custom tagline');
    assert_same('Placement Results', cpe_public_placements_title(), 'Public title helper should read custom title');
    assert_same('Learner Status', cpe_candidate_status_title(), 'Candidate status title should follow terminology when no override is set');

    $layout = render_layout_for_test(['title' => 'Identity Test', 'content' => '<p>Body</p>']);
    assert_true(str_contains($layout, 'Placement Desk / Live operations'), 'Layout should render text-only site identity');

    $publicHtml = render_view_for_test('public', ['placements' => [], 'studentLookupAllowed' => false]);
    assert_true(str_contains($publicHtml, '<h1>Placement Results</h1>'), 'Public page should render configured page title');
    assert_true(!str_contains($publicHtml, 'Learner Status'), 'Anonymous public results should not link to candidate status lookup');

    $pdo->beginTransaction();
    try {
        $candidateId = (int) $pdo->query('SELECT id FROM candidates ORDER BY id LIMIT 1')->fetchColumn();
        $companyId = (int) $pdo->query('SELECT id FROM companies ORDER BY id LIMIT 1')->fetchColumn();
        assert_true($candidateId > 0 && $companyId > 0, 'Demo fixture should include a candidate and company');
        $pdo->prepare('UPDATE candidates SET placed_company_id = ? WHERE id = ?')->execute([$companyId, $candidateId]);

        $placements = (new PlacementService($pdo))->publicPlacements();
        assert_true($placements !== [], 'Placed candidates should produce aggregate public placement results');
        assert_true(isset($placements[0]['placed_count']), 'Public placement results should include aggregate counts');
        assert_true(!isset($placements[0]['candidate_name']) && !isset($placements[0]['external_id']), 'Public placement query should exclude candidate identities');
    } finally {
        $pdo->rollBack();
    }

    $set->execute(['candidate_status_title', 'Participant Portal']);
    $studentHtml = render_view_for_test('student', [
        'externalId' => '',
        'studentData' => null,
        'workflow' => new Workflow(),
    ]);
    assert_true(str_contains($studentHtml, '<h1>Participant Portal</h1>'), 'Student status page should render configured page title');

    $set->execute(['site_name', 'Campus Placement Engine']);
    $set->execute(['site_tagline', '']);
    $set->execute(['public_placements_title', 'Public Placements']);
    $set->execute(['candidate_status_title', '']);
    $set->execute(['terminology_candidate_label', 'Candidate']);
});

test_case('session security defaults are hardened and HTTPS aware', function (): void {
    $root = dirname(__DIR__);
    $oldEnv = getenv('CPE_SESSION_SECURE');
    $oldProxyEnv = getenv('CPE_TRUST_PROXY_HEADERS');
    $oldServer = [
        'HTTPS' => $_SERVER['HTTPS'] ?? null,
        'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? null,
        'HTTP_X_FORWARDED_PROTO' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
    ];
    try {
        putenv('CPE_SESSION_SECURE');
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $options = cpe_session_cookie_options();
        assert_same(false, $options['secure'], 'Session secure cookie should default to HTTP-aware auto mode');
        assert_same(true, $options['httponly'], 'Session cookie should be HTTP only');
        assert_same('Lax', $options['samesite'], 'Session cookie should default to SameSite=Lax');
        assert_same('/', $options['path'], 'Session cookie should be scoped to the app root');

        $_SERVER['HTTPS'] = 'on';
        assert_same(true, cpe_session_cookie_options()['secure'], 'HTTPS requests should set secure session cookies');

        $_SERVER['HTTPS'] = '';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        assert_same(false, cpe_session_cookie_options()['secure'], 'Untrusted proxy headers should not affect secure-cookie detection');
        putenv('CPE_TRUST_PROXY_HEADERS=1');
        assert_same(true, cpe_session_cookie_options()['secure'], 'Trusted HTTPS proxy headers should set secure session cookies');

        putenv('CPE_SESSION_SECURE=force');
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        assert_same(true, cpe_session_cookie_options()['secure'], 'Operators should be able to force secure session cookies');

        putenv('CPE_SESSION_SECURE=never');
        $_SERVER['HTTPS'] = 'on';
        assert_same(false, cpe_session_cookie_options()['secure'], 'Operators should be able to disable secure cookies for local HTTP');

        $authSource = (string) file_get_contents($root . '/app/Support/Auth.php');
        assert_true(str_contains($authSource, 'session_regenerate_id(true)'), 'Successful login should rotate the PHP session id');
    } finally {
        if ($oldEnv === false) {
            putenv('CPE_SESSION_SECURE');
        } else {
            putenv('CPE_SESSION_SECURE=' . $oldEnv);
        }
        if ($oldProxyEnv === false) {
            putenv('CPE_TRUST_PROXY_HEADERS');
        } else {
            putenv('CPE_TRUST_PROXY_HEADERS=' . $oldProxyEnv);
        }
        foreach ($oldServer as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }
});

test_case('browser security headers are restrictive and dependency-free', function (): void {
    $headers = cpe_security_headers();
    assert_same('SAMEORIGIN', $headers['X-Frame-Options'] ?? '', 'Frame protection should default to same-origin');
    assert_same('nosniff', $headers['X-Content-Type-Options'] ?? '', 'MIME sniffing should be disabled');
    assert_same('strict-origin-when-cross-origin', $headers['Referrer-Policy'] ?? '', 'Referrer policy should be restrained');
    assert_same('camera=(), microphone=(), geolocation=()', $headers['Permissions-Policy'] ?? '', 'Browser device APIs should be denied by default');
    $csp = (string) ($headers['Content-Security-Policy'] ?? '');
    foreach (["default-src 'self'", "script-src 'self'", "object-src 'none'", "frame-ancestors 'self'", "form-action 'self'"] as $directive) {
        assert_true(str_contains($csp, $directive), 'CSP should include: ' . $directive);
    }
    assert_true(!str_contains($csp, 'https:'), 'CSP should not permit arbitrary external asset origins');
    assert_same('no-store, private', $headers['Cache-Control'] ?? '', 'Dynamic responses should not be cached');
    assert_same('no-cache', $headers['Pragma'] ?? '', 'Legacy caches should receive a no-cache directive');
});

test_case('outbound HTTP policy blocks SSRF targets and requires explicit local HTTP', function (): void {
    $oldHttp = getenv('CPE_TEST_ALLOW_HTTP');
    $oldPrivate = getenv('CPE_OUTBOUND_ALLOW_PRIVATE_NETWORK');
    try {
        putenv('CPE_TEST_ALLOW_HTTP');
        putenv('CPE_OUTBOUND_ALLOW_PRIVATE_NETWORK');
        $public = OutboundHttpPolicy::assertAllowed('https://8.8.8.8/hooks', 'CPE_TEST_ALLOW_HTTP');
        assert_same('8.8.8.8', $public['host'], 'Public HTTPS targets should pass URL policy');

        foreach ([
            'http://8.8.8.8/hooks',
            'https://127.0.0.1/hooks',
            'https://10.0.0.1/hooks',
            'https://169.254.169.254/latest/meta-data/',
            'https://[::1]/hooks',
            'https://user:password@8.8.8.8/hooks',
            'https://8.8.8.8/hooks#fragment',
        ] as $url) {
            try {
                OutboundHttpPolicy::assertAllowed($url, 'CPE_TEST_ALLOW_HTTP');
                throw new RuntimeException('Expected outbound URL policy failure for ' . $url);
            } catch (RuntimeException $e) {
                assert_true(
                    str_contains($e->getMessage(), 'Outbound'),
                    'Outbound policy should explain rejected target: ' . $url,
                );
            }
        }

        putenv('CPE_TEST_ALLOW_HTTP=1');
        $local = OutboundHttpPolicy::assertAllowed('http://127.0.0.1:8080/hooks', 'CPE_TEST_ALLOW_HTTP');
        assert_same('127.0.0.1', $local['host'], 'Explicit local HTTP should support development probes');
        assert_same(['127.0.0.1'], $local['addresses'], 'Outbound policy should return a pinned local address');
        try {
            OutboundHttpPolicy::assertAllowed('http://8.8.8.8/hooks', 'CPE_TEST_ALLOW_HTTP');
            throw new RuntimeException('Expected public cleartext HTTP policy failure.');
        } catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), 'Outbound HTTP'), 'Local HTTP override should not permit public cleartext delivery');
        }

        putenv('CPE_OUTBOUND_ALLOW_PRIVATE_NETWORK=1');
        $internal = OutboundHttpPolicy::assertAllowed('http://10.0.0.1/hooks', 'CPE_TEST_ALLOW_HTTP');
        assert_same(['10.0.0.1'], $internal['addresses'], 'Explicitly trusted internal HTTP should remain available for reviewed gateways');
    } finally {
        $oldHttp === false ? putenv('CPE_TEST_ALLOW_HTTP') : putenv('CPE_TEST_ALLOW_HTTP=' . $oldHttp);
        $oldPrivate === false ? putenv('CPE_OUTBOUND_ALLOW_PRIVATE_NETWORK') : putenv('CPE_OUTBOUND_ALLOW_PRIVATE_NETWORK=' . $oldPrivate);
    }
});

test_case('spreadsheet CSV cells neutralize formula prefixes', function (): void {
    foreach (['=1+1', '+SUM(A1:A2)', '-2+3', '@cmd', "\t=1+1", '  =1+1'] as $value) {
        assert_true(str_starts_with((string) Csv::safeCell($value), "'"), 'Formula-like CSV text should be prefixed safely');
    }
    assert_same('Candidate Name', Csv::safeCell('Candidate Name'), 'Ordinary CSV text should remain unchanged');
    assert_same(-5, Csv::safeCell(-5), 'Numeric CSV values should remain numeric');
});

test_case('structured logs keep request correlation while redacting common secrets', function (): void {
    $path = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/cpe-structured-log-' . bin2hex(random_bytes(4)) . '.jsonl';
    try {
        putenv('CPE_LOG_PATH=' . $path);
        StructuredLogger::log('error', 'security.contract', [
            'password' => 'plain-contract-password',
            'message' => 'postgresql://user:database-password@localhost/db token=contract-token Bearer contract-bearer-token',
        ]);
        $record = json_decode(trim((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
        assert_true(str_starts_with((string) $record['request_id'], 'req_'), 'Structured log should include a request correlation id');
        assert_same('[redacted]', $record['context']['password'], 'Secret-named log fields should be redacted');
        $serialized = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        assert_true(!str_contains($serialized, 'database-password'), 'Database URL passwords should be redacted from messages');
        assert_true(!str_contains($serialized, 'contract-token'), 'Token assignments should be redacted from messages');
        assert_true(!str_contains($serialized, 'contract-bearer-token'), 'Bearer credentials should be redacted from messages');
    } finally {
        putenv('CPE_LOG_PATH');
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test_case('starter configuration templates validate for every workflow', function (): void {
    $root = dirname(__DIR__);
    $service = new ConfigurationSnapshotService(Database::connection());
    $files = glob($root . '/examples/config-templates/*.json') ?: [];
    sort($files);
    $seen = [];
    foreach ($files as $file) {
        $payload = json_decode((string) file_get_contents($file), true);
        assert_true(is_array($payload), 'Starter config should be valid JSON: ' . basename($file));
        $workflow = (string) ($payload['settings']['workflow'] ?? '');
        assert_true($workflow !== '', 'Starter config should declare workflow: ' . basename($file));
        assert_true((string) ($payload['settings']['site_name'] ?? '') !== '', 'Starter config should declare site name: ' . basename($file));
        assert_true((string) ($payload['settings']['cycle_name'] ?? '') !== '', 'Starter config should declare cycle name: ' . basename($file));
        assert_true(in_array((string) ($payload['settings']['cycle_type'] ?? ''), ['final', 'internship', 'lateral', 'pooled', 'job_fair', 'other'], true), 'Starter config should declare supported cycle type: ' . basename($file));
        assert_true(array_key_exists('calendar_non_operating_weekdays', $payload['settings']), 'Starter config should declare calendar weekday guardrails: ' . basename($file));
        assert_true(array_key_exists('calendar_non_operating_dates', $payload['settings']), 'Starter config should declare calendar date guardrails: ' . basename($file));
        assert_same('none', (string) ($payload['settings']['audit_request_metadata'] ?? ''), 'Starter config should default audit request metadata retention to none: ' . basename($file));
        $result = $service->validate($file);
        assert_same($workflow, $result['workflow'], 'Starter config validator should report workflow: ' . basename($file));
        assert_true(!str_contains((string) file_get_contents($file), 'admin@test.local'), 'Starter config should not include demo users: ' . basename($file));
        assert_true(!str_contains((string) file_get_contents($file), 'notification_sms_to'), 'Starter config should not include delivery recipients: ' . basename($file));
        $seen[$workflow] = basename($file);
    }
    foreach (array_keys(Workflow::available()) as $workflow) {
        assert_true(isset($seen[$workflow]), 'Missing starter config template for workflow: ' . $workflow);
    }
});

test_case('open-source release governance files and ignore protections exist', function (): void {
    $root = dirname(__DIR__);
    assert_same('0.1.0-alpha.3', cpe_config('app.version'), 'Release package version');
    foreach ([
        'README.md',
        'LICENSE',
        'SECURITY.md',
        'CONTRIBUTING.md',
        'CODE_OF_CONDUCT.md',
        '.htaccess',
        'public/.htaccess',
        'data/.htaccess',
        'database/.htaccess',
        'tests/.htaccess',
        'docs/legacy-inventory.md',
        'docs/publication-risk-register.md',
        'docs/functional-spec.md',
        'docs/workflow-transition-matrix.md',
        'docs/glossary.md',
        'docs/environment.md',
        'docs/integrations/webhooks.md',
        'docs/api/authentication.md',
        'docs/api/v1.md',
        'docs/configuration-architecture.md',
        'docs/indian-college-template-notes.md',
        'docs/migration-from-legacy.md',
        'docs/privacy-retention.md',
        'docs/release-checklist.md',
        'docs/releases/v0.1.0-alpha.1.md',
        'docs/releases/v0.1.0-alpha.2.md',
        'docs/releases/v0.1.0-alpha.3.md',
        'INSTALL.md',
        'examples/csv-templates/README.md',
        'examples/csv-templates/candidate_unavailability_windows.csv',
        'examples/config-templates/default-placement-day.json',
        'examples/config-templates/engineering-multi-branch.json',
        'examples/config-templates/internship-season.json',
        'examples/config-templates/simple-placement-cell.json',
        'examples/config-templates/pooled-campus-drive.json',
        'examples/config-templates/virtual-interview-process.json',
        'examples/config-templates/walk-in-job-fair.json',
        'examples/env/local.env.example',
        'examples/integrations/verify-webhook.php',
        'examples/deployment/apache-vhost.conf',
        'examples/deployment/nginx-server.conf',
        'app/Core/Backup/BackupArtifact.php',
        'app/Core/Backup/BackupMetadata.php',
        'app/Core/Backup/LegacySqliteBackupConverter.php',
        'app/Core/Persistence/DatabaseConnectionInvalidException.php',
        'tests/alpha1_release_acceptance.php',
        'tests/backup_restore_contract.php',
        'tests/database_connection_cleanup_contract.php',
        'tests/legacy_backup_compatibility_contract.php',
        'tests/webhook_delivery_contract.php',
        'tests/webhook_delivery_concurrency_contract.php',
        'tests/webhook_delivery_concurrency_worker.php',
        'tests/webhook_revoke_completion_concurrency_contract.php',
        'tests/webhook_revoke_completion_concurrency_worker.php',
        'tests/webhook_receiver_example_contract.php',
        'tests/api_identity_contract.php',
        'tests/api_identity_rotation_concurrency_contract.php',
        'tests/api_identity_rotation_concurrency_worker.php',
        'tests/api_http_contract.php',
        'tests/application_transition_boundary_contract.php',
        'tests/api_application_transition_command_contract.php',
        'tests/api_application_transition_command_concurrency_contract.php',
        'tests/api_application_transition_command_concurrency_worker.php',
        'tests/validate_public_api_contracts.py',
        'contracts/openapi.v1.json',
        'public/router.php',
        '.github/workflows/ci.yml',
        '.github/workflows/release.yml',
        '.github/ISSUE_TEMPLATE/bug_report.md',
        '.github/ISSUE_TEMPLATE/feature_request.md',
    ] as $path) {
        assert_true(is_file($root . '/' . $path), "Missing release governance file: {$path}");
    }
    $ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
    foreach (['php tests/run.php', 'php tests/alpha1_release_acceptance.php', 'php tests/backup_restore_contract.php', 'php tests/database_connection_cleanup_contract.php', 'php tests/incident_boundary_contract.php', 'php tests/legacy_backup_compatibility_contract.php', 'php tests/worker_delivery_contract.php', 'php tests/public_event_contract.php', 'php tests/webhook_delivery_contract.php', 'php tests/webhook_delivery_concurrency_contract.php', 'php tests/webhook_revoke_completion_concurrency_contract.php', 'php tests/webhook_receiver_example_contract.php', 'php tests/api_identity_contract.php', 'php tests/api_identity_rotation_concurrency_contract.php', 'php tests/api_http_contract.php', 'php tests/application_transition_boundary_contract.php', 'php tests/api_application_transition_command_contract.php', 'php tests/api_application_transition_command_concurrency_contract.php', 'php tests/database_ownership_contract.php', 'php tests/migration_lock_contract.php', 'php tests/database_lock_release_contract.php', 'php tests/install_concurrency_contract.php', 'php tests/hosted_install_atomicity_contract.php', 'php tests/hosted_install_preflight_contract.php', 'php tests/setup_authorization_contract.php', 'php tests/hosted_install_contract.php', 'php tests/managed_hosting_contract.php', 'php placement publication-check', 'php placement package', 'php placement verify-package', 'php placement install', 'php placement upgrade', 'php placement setup --check', 'php placement serve --help', 'php placement install-demo', 'php placement seed-large-demo', 'php placement browser-qa-plan', 'php placement smoke-http', 'php placement readiness', 'php placement metrics', 'php placement placement-report', 'php placement privacy-report', 'php placement export', 'php placement rollback-import', 'php placement config-export', 'php placement config-validate', 'php placement config-import', 'php placement deliver-notifications', 'php placement work-integrations', 'php placement certify-notifications', 'php placement optimize-slots', 'php placement assign-optimized-slots', 'php -l placement'] as $command) {
        assert_true(str_contains($ci, $command), "Missing CI command: {$command}");
    }
    $releaseWorkflow = (string) file_get_contents($root . '/.github/workflows/release.yml');
    $releaseWorkflowWithoutIndent = preg_replace('/^[ \t]+/m', '', $releaseWorkflow) ?? $releaseWorkflow;
    $optionalReleaseTlsNotice = <<<'YAML'
if [[ -z "${CPE_POSTGRES_TLS_TEST_URL:-}" ]]; then
if [[ "${GITHUB_REF_NAME}" =~ ^v[0-9]+\.[0-9]+\.[0-9]+-alpha\.[0-9]+$ ]]; then
echo '::notice title=Live PostgreSQL TLS evidence skipped::CPE_POSTGRES_TLS_TEST_URL is not configured; production-endpoint negotiated TLS is not proven and this alpha remains evaluation-only.'
else
echo '::error title=Live PostgreSQL TLS evidence required::CPE_POSTGRES_TLS_TEST_URL may be omitted only for evaluation alpha tags shaped v<major>.<minor>.<patch>-alpha.<number>; beta, RC, stable, malformed, and other tag releases require live negotiated-TLS evidence.' >&2
exit 1
fi
fi
YAML;
    assert_true(
            str_contains($releaseWorkflow, 'CPE_POSTGRES_TLS_TEST_URL: ${{ secrets.CPE_POSTGRES_TLS_TEST_URL }}')
            && str_contains($releaseWorkflow, 'php tests/postgres_tls_contract.php')
            && str_contains($releaseWorkflowWithoutIndent, $optionalReleaseTlsNotice),
        'Only evaluation alpha tag releases may omit the mapped TLS endpoint; other tag classes must fail before publication.',
    );
    assert_true(
        !str_contains($releaseWorkflow, 'CPE_POSTGRES_TLS_TEST_URL must be configured for a tag release.'),
        'Tag releases must not silently restore the live PostgreSQL TLS secret as a hard prerequisite.',
    );
    $alphaTagPattern = '/\Av[0-9]+\.[0-9]+\.[0-9]+-alpha\.[0-9]+\z/D';
    assert_true(preg_match($alphaTagPattern, 'v0.1.0-alpha.3') === 1, 'Established evaluation alpha tag should match the TLS exemption.');
    foreach (['v1.0.0-beta.1-alpha.1', 'v1.0.0-not-alpha.2', 'v1.0.0-alpha.2-extra', '1.0.0-alpha.2'] as $nonAlphaTag) {
        assert_true(preg_match($alphaTagPattern, $nonAlphaTag) !== 1, "Malformed or non-alpha tag must not match the TLS exemption: {$nonAlphaTag}");
    }
    foreach ([$ci, $releaseWorkflow] as $workflow) {
        assert_true(
            str_contains(
                $workflow,
                'echo "CPE_TEST_SCHEMA_PYTHON=$RUNNER_TEMP/cpe-public-schema-validator/bin/python" >> "$GITHUB_ENV"',
            ) && str_contains($workflow, 'tests/validate_public_api_contracts.py'),
            'CI and release must persist the exact pinned schema-validator interpreter for extracted-package subprocesses.',
        );
        assert_true(
            str_contains($workflow, 'git archive --format=tar v0.1.0-alpha.1')
                && str_contains($workflow, 'CPE_ALPHA1_DATABASE_FIXTURE=')
                && str_contains($workflow, 'CPE_ALPHA1_BACKUP_FIXTURE=')
                && str_contains($workflow, 'php tests/alpha1_release_acceptance.php'),
            'CI and release must construct and test exact public alpha.1 artifacts.',
        );
        assert_true(
            str_contains($workflow, 'cpe_hosted_atomicity_contract')
                && str_contains($workflow, 'php tests/hosted_install_atomicity_contract.php'),
            'CI and release must run hosted install atomicity against a fresh dedicated PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_database_lock_release_contract')
                && str_contains($workflow, 'php tests/database_lock_release_contract.php'),
            'CI and release must fault checked PostgreSQL lock release in a fresh dedicated database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_backup_restore_contract')
                && str_contains($workflow, 'php tests/backup_restore_contract.php'),
            'CI and release must run backup/restore identity validation against a fresh dedicated PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_database_connection_cleanup_contract')
                && str_contains($workflow, 'php tests/database_connection_cleanup_contract.php'),
            'CI and release must run invalid-connection cleanup against a fresh dedicated PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_public_event_contract')
                && str_contains($workflow, 'php tests/public_event_contract.php'),
            'CI and release must run the public event contract against a fresh dedicated PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_webhook_delivery_contract')
                && str_contains($workflow, 'php tests/webhook_delivery_contract.php'),
            'CI and release must run the signed webhook delivery contract against a fresh dedicated PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_webhook_delivery_concurrency_contract')
                && str_contains($workflow, 'php tests/webhook_delivery_concurrency_contract.php'),
            'CI and release must run two-process webhook claim and circuit concurrency against a fresh PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_webhook_revoke_completion_concurrency_contract')
                && str_contains($workflow, 'php tests/webhook_revoke_completion_concurrency_contract.php'),
            'CI and release must run revoke/completion lock-order concurrency against a fresh PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_webhook_capture_revoke_concurrency_contract')
                && str_contains($workflow, 'php tests/webhook_capture_revoke_concurrency_contract.php'),
            'CI and release must run capture/revoke serialization and durable fairness against a fresh PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_api_identity_contract')
                && str_contains($workflow, 'php tests/api_identity_contract.php'),
            'CI and release must run API identity lifecycle parity against a fresh PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_application_transition_boundary_contract')
                && str_contains($workflow, 'php tests/application_transition_boundary_contract.php'),
            'CI and release must run the application transition boundary against a fresh PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_api_application_transition_command_contract')
                && str_contains($workflow, 'php tests/api_application_transition_command_contract.php'),
            'CI and release must run API command persistence parity against a fresh PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_api_application_transition_command_concurrency_contract')
                && str_contains($workflow, 'php tests/api_application_transition_command_concurrency_contract.php'),
            'CI and release must run API command same-key concurrency against a fresh PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_api_http_contract')
                && str_contains($workflow, 'php tests/api_http_contract.php'),
            'CI and release must run the public API HTTP contract against a fresh PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'cpe_api_rotation_concurrency_contract')
                && str_contains($workflow, 'php tests/api_identity_rotation_concurrency_contract.php'),
            'CI and release must run API token rotation concurrency against a fresh PostgreSQL database.',
        );
        assert_true(
            str_contains($workflow, 'CPE_WEBHOOK_TEST_PRIVILEGED_HEALTH: "1"'),
            'CI and release must prove unreadable webhook health storage with a disposable restricted PostgreSQL role.',
        );
    }
    $webhookDeliveryContract = (string) file_get_contents($root . '/tests/webhook_delivery_contract.php');
    $resetRoleOffset = strpos($webhookDeliveryContract, "\$pdo->exec('RESET ROLE')");
    $revokeGrantOffset = strpos($webhookDeliveryContract, "'REVOKE ALL PRIVILEGES ON TABLE '");
    $dropOwnedOffset = strpos($webhookDeliveryContract, "'DROP OWNED BY '");
    $dropRoleOffset = strpos($webhookDeliveryContract, "'DROP ROLE '");
    assert_true(
        is_int($resetRoleOffset)
            && is_int($revokeGrantOffset)
            && is_int($dropOwnedOffset)
            && is_int($dropRoleOffset)
            && $resetRoleOffset < $revokeGrantOffset
            && $revokeGrantOffset < $dropOwnedOffset
            && $dropOwnedOffset < $dropRoleOffset
            && str_contains($webhookDeliveryContract, 'if ($cleanupFailures !== [])'),
        'Privileged webhook health cleanup must reset safely, revoke grants, drop owned grants, drop the role, and fail on cleanup errors.',
    );
    $suiteSource = (string) file_get_contents(__FILE__);
    assert_true(
        str_contains(
            $suiteSource,
            "foreach (['CPE_DB_PATH', 'CPE_DB_DRIVER', 'CPE_DATABASE_URL', 'CPE_TEST_SCHEMA_PYTHON'] as \$key)",
        ),
        'Extracted-package PHP subprocesses must explicitly forward CPE_TEST_SCHEMA_PYTHON from GITHUB_ENV.',
    );
    $deployment = (string) file_get_contents($root . '/docs/deployment.md');
    foreach (['examples/deployment/apache-vhost.conf', 'examples/deployment/nginx-server.conf', 'point the virtual host document root at `public/`', 'public/.htaccess', 'CPE_SETUP_TOKEN', 'php placement setup', 'cpe_database_ownership', 'no force/rebind flag', 'cpe.engine-migrations', 'cpe.engine-installation'] as $snippet) {
        assert_true(str_contains($deployment, $snippet), "Missing deployment guidance: {$snippet}");
    }
    $managedHosting = (string) file_get_contents($root . '/docs/managed-hosting-contract.md');
    foreach (['DatabaseOwnership::claimOrVerify()', 'cpe.database-ownership', 'SqlMigrationRunner', 'cpe.engine-migrations', 'cpe.engine-installation', 'Contract version 2', '`installHosted()` is the only path', 'future pin update', 'CPE_DATABASE_OWNERSHIP_CONFLICT', 'CPE_DATABASE_OWNERSHIP_AMBIGUOUS', 'CPE_DATABASE_OWNERSHIP_CORRUPT', 'CPE_DATABASE_OWNERSHIP_VERSION_UNSUPPORTED'] as $snippet) {
        assert_true(str_contains($managedHosting, $snippet), "Missing database ownership contract guidance: {$snippet}");
    }
    $disasterRecovery = (string) file_get_contents($root . '/docs/disaster-recovery.md');
    foreach (['cpe_database_ownership', 'permanent recovery evidence', 'Never delete or rewrite the row', 'cpe.engine-migrations'] as $snippet) {
        assert_true(str_contains($disasterRecovery, $snippet), "Missing ownership recovery guidance: {$snippet}");
    }
    $environment = (string) file_get_contents($root . '/docs/environment.md');
    foreach (['CPE_DB_PATH', 'CPE_ADMIN_PASSWORD', 'CPE_SETUP_TOKEN', 'CPE_SESSION_SECURE', 'CPE_IMPORT_MAX_BYTES', 'CPE_NOTIFICATION_WEBHOOK_URL', 'examples/env/local.env.example'] as $snippet) {
        assert_true(str_contains($environment, $snippet), "Missing environment guidance: {$snippet}");
    }
    $envExample = (string) file_get_contents($root . '/examples/env/local.env.example');
    foreach (['CPE_DB_PATH=', 'CPE_ADMIN_PASSWORD=change-this-password', 'CPE_SESSION_SECURE=auto', 'replace-with-provider-token'] as $snippet) {
        assert_true(str_contains($envExample, $snippet), "Missing environment template value: {$snippet}");
    }
    $apache = (string) file_get_contents($root . '/examples/deployment/apache-vhost.conf');
    foreach (['DocumentRoot /var/www/campus-placement-engine/public', 'Require all denied', 'AllowOverride All'] as $snippet) {
        assert_true(str_contains($apache, $snippet), "Missing Apache deployment safeguard: {$snippet}");
    }
    $nginx = (string) file_get_contents($root . '/examples/deployment/nginx-server.conf');
    foreach (['root /var/www/campus-placement-engine/public', 'try_files $uri /index.php?$query_string', 'fastcgi_pass'] as $snippet) {
        assert_true(str_contains($nginx, $snippet), "Missing Nginx deployment safeguard: {$snippet}");
    }
    $gitignore = (string) file_get_contents($root . '/.gitignore');
    foreach (['/.legacy-private/', '/http/', '/dist/', '/website/node_modules/', '/website/dist/', '/website/coverage/', '/website/playwright-report/', '/website/test-results/', '/.env', '/.env.*', '/config/local.php', '/data/*.sqlite', '/data/backups/', '/data/config/', '/data/imports/', '/data/privacy/', '/data/restore-staging/', '/data/setup/', '*.zip', '*.7z', '*.rar', '*.xlsx', '*.docx', '*.sql', '!/database/migrations/*.sql', '!/database/migrations/pgsql/*.sql'] as $pattern) {
        assert_true(str_contains($gitignore, $pattern), "Missing .gitignore protection: {$pattern}");
    }
    $migrationGuide = (string) file_get_contents($root . '/docs/migration-from-legacy.md');
    foreach (['legacy_wide.csv', 'stat1', 'gd_round', 'Preview CSV', 'convert-legacy-backup', 'legacy_conversion_required', '.legacy-private/', 'Do not publish, commit, or attach historical raw data'] as $snippet) {
        assert_true(str_contains($migrationGuide, $snippet), "Missing migration-guide warning or mapping: {$snippet}");
    }
});

test_case('conditional PostgreSQL TLS contract skips only absent or empty endpoint', function (): void {
    $runContract = static function (bool $present, string $value = ''): array {
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        unset($environment['CPE_POSTGRES_TLS_TEST_URL']);
        if ($present) {
            $environment['CPE_POSTGRES_TLS_TEST_URL'] = $value;
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/postgres_tls_contract.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start conditional PostgreSQL TLS contract.');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    };

    foreach ([[false, 'absent'], [true, 'empty']] as [$present, $label]) {
        [$code, $stdout, $stderr] = $runContract($present);
        assert_same(0, $code, "{$label} TLS endpoint should skip successfully");
        assert_true(str_starts_with($stdout, 'SKIP negotiated PostgreSQL TLS contract:'), "{$label} TLS endpoint should report the conditional skip");
        assert_same('', $stderr, "{$label} TLS endpoint skip should not emit an error");
    }

    [$zeroCode, $zeroStdout, $zeroStderr] = $runContract(true, '0');
    assert_true($zeroCode !== 0, 'Literal zero TLS endpoint must enter strict validation and fail');
    assert_true(!str_contains($zeroStdout, 'SKIP negotiated PostgreSQL TLS contract:'), 'Literal zero TLS endpoint must not silently skip');
    assert_true(str_contains($zeroStderr, 'must be a postgresql:// URL'), 'Literal zero TLS endpoint should fail strict URL validation');
});

test_case('publication check rejects obvious committed secret patterns', function (): void {
    $root = dirname(__DIR__);
    $path = $root . '/tmp-publication-secret-smoke.txt';
    $apiTokenPath = $root . '/tmp-publication-api-token-smoke.txt';
    try {
        file_put_contents($path, 'OPENAI_API_KEY=sk-proj-' . str_repeat('A', 32) . "\n");
        [$code, $stdout, $stderr] = run_cli(['publication-check']);
        assert_same(1, $code, 'Publication check should fail when a public file contains a likely secret');
        $output = $stdout . $stderr;
        assert_true(str_contains($output, 'Potential secret'), 'Publication check should explain the secret finding');
        assert_true(str_contains($output, 'tmp-publication-secret-smoke.txt'), 'Publication check should report the offending file');
        unlink($path);

        $tokenPrefix = implode('_', ['cpe', 'live', 'apitok']) . '_';
        $tokenSecret = rtrim(strtr(base64_encode(str_repeat("\x5a", 32)), '+/', '-_'), '=');
        file_put_contents($apiTokenPath, $tokenPrefix . str_repeat('a', 32) . '.' . $tokenSecret . "\n");
        [$apiCode, $apiStdout, $apiStderr] = run_cli(['publication-check']);
        assert_same(1, $apiCode, 'Publication check should fail for a canonical Engine API token');
        $apiOutput = $apiStdout . $apiStderr;
        assert_true(str_contains($apiOutput, 'Campus Placement Engine API token'), 'Publication check should classify the Engine API token');
        assert_true(str_contains($apiOutput, 'tmp-publication-api-token-smoke.txt'), 'Publication check should report the Engine API token file');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        if (is_file($apiTokenPath)) {
            unlink($apiTokenPath);
        }
    }
});

test_case('slot CLI commands reject unknown company filters', function (): void {
    foreach (['suggest-slots', 'optimize-slots', 'assign-slots', 'assign-optimized-slots'] as $command) {
        [$code, $stdout, $stderr] = run_cli([$command, 'NO_SUCH_COMPANY']);
        assert_same(1, $code, "{$command} should fail for unknown company code");
        assert_same('', $stdout, "{$command} should not emit a misleading empty queue");
        assert_true(str_contains($stderr, 'Company code not found.'), "{$command} should explain the unknown company code without reflecting it");
    }
});

test_case('release package includes public source and excludes private runtime data', function (): void {
    $root = dirname(__DIR__);
    $target = sys_get_temp_dir() . '/cpe-package-' . bin2hex(random_bytes(4));
    $extractDir = sys_get_temp_dir() . '/cpe-package-extract-' . bin2hex(random_bytes(4));
    $tarExtractDir = sys_get_temp_dir() . '/cpe-package-tar-extract-' . bin2hex(random_bytes(4));
    $packageDb = sys_get_temp_dir() . '/cpe-package-db-' . bin2hex(random_bytes(4)) . '.sqlite';
    $runtimeStaging = $root . '/data/restore-staging/package-contract-' . bin2hex(random_bytes(4));
    try {
        if (!is_dir($runtimeStaging) && !mkdir($runtimeStaging, 0700, true)) {
            throw new RuntimeException('Could not create package runtime-data fixture.');
        }
        file_put_contents($runtimeStaging . '/runtime-only.sqlite', 'runtime-only');
        [$code, $stdout, $stderr] = run_cli(['package', '--target=' . $target]);
        assert_same(0, $code, 'Package command should succeed: ' . $stderr);
        assert_true(str_contains($stdout, 'Release package written'), 'Package command should report archive path');
        $rootName = 'campus-placement-engine-' . cpe_config('app.version', '0.1.0');
        $tarPath = $target . '/' . $rootName . '.tar.gz';
        $zipPath = $target . '/' . $rootName . '.zip';
        $checksumManifestPath = $target . '/SHA256SUMS';
        foreach ([$tarPath, $zipPath] as $archivePath) {
            $checksumPath = $archivePath . '.sha256';
            assert_true(is_file($archivePath), 'Release package archive should exist: ' . $archivePath);
            assert_true(is_file($checksumPath), 'Release package checksum should exist: ' . $checksumPath);
            assert_true(str_contains((string) file_get_contents($checksumPath), hash_file('sha256', $archivePath)), 'Release package checksum should match archive: ' . $archivePath);
        }
        assert_true(is_file($checksumManifestPath), 'Release checksum manifest should exist');
        $checksumManifest = (string) file_get_contents($checksumManifestPath);
        assert_true(str_contains($checksumManifest, hash_file('sha256', $tarPath) . '  ' . basename($tarPath)), 'Checksum manifest should include tarball');
        assert_true(str_contains($checksumManifest, hash_file('sha256', $zipPath) . '  ' . basename($zipPath)), 'Checksum manifest should include ZIP');

        $archive = new PharData($tarPath);
        $paths = [];
        foreach (new RecursiveIteratorIterator($archive) as $file) {
            if ($file instanceof SplFileInfo) {
                $paths[] = $file->getPathname();
            }
        }
        $joined = implode("\n", $paths);
        assert_true(str_contains($joined, '/README.md'), 'Package should include README');
        assert_true(str_contains($joined, '/INSTALL.md'), 'Package should include the short installation guide');
        assert_true(str_contains($joined, '/.htaccess'), 'Package should include root Apache fallback rules');
        assert_true(str_contains($joined, '/public/.htaccess'), 'Package should include public Apache rules');
        assert_true(str_contains($joined, '/data/.htaccess'), 'Package should deny direct access to runtime data');
        assert_true(str_contains($joined, '/database/.htaccess'), 'Package should deny direct access to database sources');
        assert_true(str_contains($joined, '/tests/.htaccess'), 'Package should deny direct access to test sources');
        assert_true(str_contains($joined, '/public/index.php'), 'Package should include web entrypoint');
        assert_true(str_contains($joined, '/app/Core/Persistence/DatabaseLock.php'), 'Package should include the database lock contract');
        assert_true(str_contains($joined, '/app/Core/Persistence/DatabaseLockException.php'), 'Package should include typed database lock failures');
        assert_true(str_contains($joined, '/app/Core/Persistence/DatabaseOwnership.php'), 'Package should include the database ownership contract');
        assert_true(str_contains($joined, '/app/Core/Persistence/SqlMigrationRunner.php'), 'Package should include the SQL migration runner contract');
        assert_true(str_contains($joined, '/app/Install/InstallationStepObserver.php'), 'Package should include the installation stage observer contract');
        assert_true(str_contains($joined, '/app/Security/OperationalBearerAuthorization.php'), 'Package should include operational Bearer authorization');
        assert_true(str_contains($joined, '/app/Security/SetupAuthorization.php'), 'Package should include the setup authorization core');
        assert_true(str_contains($joined, '/app/Security/SetupAuthorizationStageFailure.php'), 'Package should include fixed setup authorization stage failures');
        assert_true(str_contains($joined, '/app/Security/SetupHttp.php'), 'Package should include the setup HTTP boundary');
        assert_true(str_contains($joined, '/app/Security/SetupRecoveryAuthority.php'), 'Package should include target-bound setup recovery authority');
        assert_true(str_contains($joined, '/app/Views/setup-unlock.php'), 'Package should include the setup unlock view');
        assert_true(str_contains($joined, '/public/install.php'), 'Package should include the protected installer entrypoint');
        assert_true(str_contains($joined, '/public/health.php'), 'Package should include the health probe entrypoint');
        assert_true(str_contains($joined, '/public/metrics.php'), 'Package should include the metrics probe entrypoint');
        assert_true(str_contains($joined, '/app/Core/Backup/BackupArtifact.php'), 'Package should include the internal backup artifact contract');
        assert_true(str_contains($joined, '/app/Core/Backup/BackupMetadata.php'), 'Package should include checksum-bound backup metadata');
        assert_true(str_contains($joined, '/app/Core/Backup/LegacySqliteBackupConverter.php'), 'Package should include explicit legacy SQLite conversion');
        assert_true(str_contains($joined, '/app/Core/Events/InternalEventDeliveryWorker.php'), 'Package should include the post-commit observer worker');
        assert_true(str_contains($joined, '/app/Core/Events/InternalEventDeliveryReplayService.php'), 'Package should include audited internal observer replay');
        assert_true(str_contains($joined, '/app/Core/Events/InternalEventFanoutWorker.php'), 'Package should include post-commit declaration fanout');
        assert_true(str_contains($joined, '/app/Core/Events/InternalEventFanoutReplayService.php'), 'Package should include audited declaration fanout replay');
        assert_true(str_contains($joined, '/app/Core/Events/InternalEventSubscriberRegistry.php'), 'Package should include the internal observer registry');
        assert_true(str_contains($joined, '/app/Core/Events/InternalEventSubscription.php'), 'Package should include stable internal observer subscriptions');
        assert_true(str_contains($joined, '/app/Core/Events/PublicEventProjection.php'), 'Package should include the governed public projection');
        assert_true(str_contains($joined, '/app/Core/Events/PublicEventEnvelope.php'), 'Package should include the governed public envelope');
        assert_true(str_contains($joined, '/app/Core/Events/PublicEventDeadLetterReplayService.php'), 'Package should include audited public dead-letter replay');
        assert_true(str_contains($joined, '/app/Core/Events/ReplayOperatorAuthorization.php'), 'Package should include centralized replay operator authorization');
        assert_true(str_contains($joined, '/app/Modules/Placement/Application/ApplicationStatusWriter.php'), 'Package should include the shared application status writer');
        assert_true(str_contains($joined, '/app/Modules/Placement/Application/ApplicationTransitionActor.php'), 'Package should include the application transition actor boundary');
        assert_true(str_contains($joined, '/app/Modules/Placement/Application/ApplicationTransitionCommand.php'), 'Package should include the application transition command boundary');
        assert_true(str_contains($joined, '/app/Modules/Placement/Application/ApplicationTransitionResult.php'), 'Package should include the application transition result boundary');
        assert_true(str_contains($joined, '/app/Modules/Placement/Application/ApplicationTransitionService.php'), 'Package should include the application transition service boundary');
        assert_true(str_contains($joined, '/app/Controllers/WebhookController.php'), 'Package should include the institution webhook administration controller');
        assert_true(str_contains($joined, '/app/Integrations/Webhooks/WebhookDeliveryWorker.php'), 'Package should include the signed webhook delivery worker');
        assert_true(str_contains($joined, '/app/Integrations/Webhooks/WebhookDeliveryReplayService.php'), 'Package should include attributed exact webhook replay');
        assert_true(str_contains($joined, '/app/Integrations/Webhooks/WebhookSecretCipher.php'), 'Package should include encrypted webhook secret storage');
        assert_true(str_contains($joined, '/app/Integrations/Webhooks/WebhookSigner.php'), 'Package should include the public signing contract');
        assert_true(str_contains($joined, '/app/Views/webhooks.php'), 'Package should include the institution webhook workflow');
        assert_true(str_contains($joined, '/app/Controllers/ApiAccessController.php'), 'Package should include the institution API access controller');
        assert_true(str_contains($joined, '/app/Api/Security/ApiKeyring.php'), 'Package should include the API keyring');
        assert_true(str_contains($joined, '/app/Api/Security/ApiServiceAccountService.php'), 'Package should include API service-account lifecycle');
        assert_true(str_contains($joined, '/app/Api/Security/ApiTokenAuthenticator.php'), 'Package should include verifier-only API authentication');
        assert_true(str_contains($joined, '/app/Api/Security/ApiScopePolicy.php'), 'Package should include exact API scope policy');
        assert_true(str_contains($joined, '/app/Api/Operations/ApiRateLimiter.php'), 'Package should include API rate limiting');
        assert_true(str_contains($joined, '/app/Api/Operations/ApiRequestAuditService.php'), 'Package should include redacted API request audit');
        assert_true(str_contains($joined, '/app/Api/Operations/ApiHealthService.php'), 'Package should include aggregate API health');
        assert_true(str_contains($joined, '/app/Views/api-access.php'), 'Package should include the institution API access workflow');
        assert_true(str_contains($joined, '/app/Core/Persistence/DatabaseConnectionInvalidException.php'), 'Package should include typed invalid-connection cleanup failures');
        assert_true(str_contains($joined, '/app/Core/Security/AuthorizationUnavailable.php'), 'Package should include typed installed-runtime authorization failures');
        assert_true(str_contains($joined, '/app/Core/Modules/ModuleVersionIntegrity.php'), 'Package should include exact bundled module version integrity');
        assert_true(str_contains($joined, '/app/Core/Install/InstallationState.php'), 'Package should include typed installation state');
        assert_true(str_contains($joined, '/app/Core/Install/InstallationStateUnavailable.php'), 'Package should include redacted installation-state failures');
        assert_true(str_contains($joined, '/tests/alpha1_release_acceptance.php'), 'Package should include exact alpha.1 external acceptance');
        assert_true(str_contains($joined, '/tests/backup_restore_contract.php'), 'Package should include the backup/restore identity contract');
        assert_true(str_contains($joined, '/tests/database_connection_cleanup_contract.php'), 'Package should include the invalid-connection cleanup contract');
        assert_true(str_contains($joined, '/tests/legacy_backup_compatibility_contract.php'), 'Package should include the legacy backup compatibility contract');
        assert_true(str_contains($joined, '/tests/database_ownership_contract.php'), 'Package should include the database ownership regression contract');
        assert_true(str_contains($joined, '/tests/database_ownership_worker.php'), 'Package should include the database ownership race worker');
        assert_true(str_contains($joined, '/tests/migration_lock_contract.php'), 'Package should include the migration lock regression contract');
        assert_true(str_contains($joined, '/tests/migration_lock_worker.php'), 'Package should include the migration lock worker');
        assert_true(str_contains($joined, '/tests/database_lock_release_contract.php'), 'Package should include the checked lock-release contract');
        assert_true(str_contains($joined, '/tests/install_concurrency_contract.php'), 'Package should include the installation concurrency regression contract');
        assert_true(str_contains($joined, '/tests/install_concurrency_worker.php'), 'Package should include the installation concurrency worker');
        assert_true(str_contains($joined, '/tests/hosted_install_atomicity_contract.php'), 'Package should include the hosted installation atomicity contract');
        assert_true(str_contains($joined, '/tests/hosted_install_preflight_contract.php'), 'Package should include the hosted installation preflight contract');
        assert_true(str_contains($joined, '/tests/setup_authorization_contract.php'), 'Package should include the setup authorization regression contract');
        assert_true(str_contains($joined, '/tests/authorization_failure_contract.php'), 'Package should include the installed-runtime authorization regression contract');
        assert_true(str_contains($joined, '/tests/authorization_state_contract.php'), 'Package should include the portable authorization-state contract');
        assert_true(str_contains($joined, '/tests/installation_state_contract.php'), 'Package should include the portable installation-state contract');
        assert_true(str_contains($joined, '/tests/internal_event_delivery_contract.php'), 'Package should include the internal observer delivery contract');
        assert_true(str_contains($joined, '/tests/public_event_contract.php'), 'Package should include the public event contract');
        assert_true(str_contains($joined, '/tests/webhook_delivery_contract.php'), 'Package should include the signed webhook delivery contract');
        assert_true(str_contains($joined, '/tests/webhook_delivery_concurrency_contract.php'), 'Package should include the signed webhook concurrency contract');
        assert_true(str_contains($joined, '/tests/webhook_delivery_concurrency_worker.php'), 'Package should include the signed webhook concurrency worker');
        assert_true(str_contains($joined, '/tests/webhook_revoke_completion_concurrency_contract.php'), 'Package should include the webhook revoke/completion concurrency contract');
        assert_true(str_contains($joined, '/tests/webhook_revoke_completion_concurrency_worker.php'), 'Package should include the webhook revoke/completion concurrency worker');
        assert_true(str_contains($joined, '/tests/webhook_capture_revoke_concurrency_contract.php'), 'Package should include the webhook capture/revoke concurrency contract');
        assert_true(str_contains($joined, '/tests/webhook_capture_revoke_concurrency_worker.php'), 'Package should include the webhook capture/revoke concurrency worker');
        assert_true(str_contains($joined, '/tests/webhook_receiver_example_contract.php'), 'Package should include the bounded receiver example contract');
        assert_true(str_contains($joined, '/tests/api_identity_contract.php'), 'Package should include the API identity lifecycle contract');
        assert_true(str_contains($joined, '/tests/api_identity_rotation_concurrency_contract.php'), 'Package should include the API rotation concurrency contract');
        assert_true(str_contains($joined, '/tests/api_identity_rotation_concurrency_worker.php'), 'Package should include the API rotation concurrency worker');
        assert_true(str_contains($joined, '/tests/application_transition_boundary_contract.php'), 'Package should include the application transition boundary contract');
        assert_true(str_contains($joined, '/tests/api_application_transition_command_contract.php'), 'Package should include the API command persistence contract');
        assert_true(str_contains($joined, '/tests/api_application_transition_command_concurrency_contract.php'), 'Package should include the API command concurrency contract');
        assert_true(str_contains($joined, '/tests/api_application_transition_command_concurrency_worker.php'), 'Package should include the API command concurrency worker');
        foreach ([
            'app/Api/Commands/ApiCommandConflict.php',
            'app/Api/Commands/ApiCommandFingerprint.php',
            'app/Api/Commands/ApiCommandHasher.php',
            'app/Api/Commands/ApiCommandIdempotencyStore.php',
            'app/Api/Commands/ApiCommandReservation.php',
            'app/Api/Commands/ApiCommandUnavailable.php',
            'app/Api/Commands/InvalidApiCommandInput.php',
            'database/migrations/054_api_application_transition_commands.sql',
            'database/migrations/pgsql/018_api_application_transition_commands.sql',
        ] as $commandPackagePath) {
            assert_true(
                str_contains($joined, '/' . $commandPackagePath),
                'Package should include Phase 4B command foundation file: ' . $commandPackagePath,
            );
        }
        foreach ([
            'app/Api/Http/ApiCursorCodec.php',
            'app/Api/Http/ApiHttpException.php',
            'app/Api/Http/ApiHttpRequest.php',
            'app/Api/Http/ApiHttpResponse.php',
            'app/Api/Http/ApiReadService.php',
            'app/Api/Http/ApiStorageUnavailable.php',
            'app/Api/Http/ApiV1Kernel.php',
            'public/router.php',
            'database/migrations/053_api_read_pagination_indexes.sql',
            'database/migrations/pgsql/017_api_read_pagination_indexes.sql',
            'contracts/openapi.v1.json',
            'contracts/schemas/api-v1-opportunity.schema.json',
            'contracts/schemas/api-v1-application.schema.json',
            'contracts/schemas/api-v1-meta.schema.json',
            'contracts/schemas/api-v1-page.schema.json',
            'contracts/schemas/api-v1-error.schema.json',
            'contracts/schemas/api-v1-service.schema.json',
            'contracts/schemas/api-v1-opportunity-item.schema.json',
            'contracts/schemas/api-v1-application-item.schema.json',
            'contracts/schemas/api-v1-opportunity-collection.schema.json',
            'contracts/schemas/api-v1-application-collection.schema.json',
            'contracts/examples/api-v1-service.json',
            'contracts/examples/api-v1-opportunity-item.json',
            'contracts/examples/api-v1-opportunity-collection.json',
            'contracts/examples/api-v1-application-item.json',
            'contracts/examples/api-v1-application-collection.json',
            'contracts/examples/api-v1-error.json',
            'contracts/fixtures/api-v1-opportunity.consumer.json',
            'contracts/fixtures/api-v1-application.consumer.json',
            'contracts/fixtures/api-v1-opportunity.future-field.consumer.json',
            'docs/api/v1.md',
            'tests/api_http_contract.php',
            'tests/validate_public_api_contracts.py',
        ] as $apiPackagePath) {
            assert_true(
                str_contains($joined, '/' . $apiPackagePath),
                'Package should include public API v1 file: ' . $apiPackagePath,
            );
        }
        assert_true(str_contains($joined, '/tests/validate_public_event_schemas.py'), 'Package should include the independent Draft 2020-12 validator');
        assert_true(str_contains($joined, '/tests/requirements-public-event-schema.txt'), 'Package should include pinned schema-validator test dependencies');
        assert_true(str_contains($joined, '/tests/authorized_setup_recovery_fixture.php'), 'Package should include the authorized setup recovery test fixture');
        assert_true(str_contains($joined, '/tests/hosted_install_contract.php'), 'Package should include the hosted identity immutability contract');
        assert_true(str_contains($joined, '/tests/managed_hosting_contract.php'), 'Package should include the managed-hosting probe contract');
        assert_true(str_contains($joined, '/docs/environment.md'), 'Package should include environment variable guide');
        assert_true(str_contains($joined, '/docs/integrations/events.md'), 'Package should include the public event guide');
        assert_true(str_contains($joined, '/docs/integrations/webhooks.md'), 'Package should include signed webhook operations and consumer guidance');
        assert_true(str_contains($joined, '/docs/api/authentication.md'), 'Package should include API identity authentication guidance');
        assert_true(str_contains($joined, '/docs/compatibility.md'), 'Package should include public compatibility rules');
        assert_true(str_contains($joined, '/docs/security/integration-threat-model.md'), 'Package should include the integration threat model');
        assert_true(str_contains($joined, '/contracts/public-integration.v1.json'), 'Package should include the frozen public integration declaration');
        assert_true(str_contains($joined, '/contracts/schemas/application.status_changed.v1.schema.json'), 'Package should include the strict event schema');
        assert_true(str_contains($joined, '/contracts/examples/application.status_changed.v1.json'), 'Package should include the public event example');
        assert_true(str_contains($joined, '/contracts/fixtures/application.status_changed.v1.consumer.json'), 'Package should include the frozen consumer fixture');
        assert_true(str_contains($joined, '/docs/releases/v0.1.0-alpha.3.md'), 'Package should include current release notes');
        assert_true(str_contains($joined, '/examples/env/local.env.example'), 'Package should include synthetic env template');
        assert_true(str_contains($joined, '/examples/integrations/verify-webhook.php'), 'Package should include the dependency-light consumer verification example');
        assert_true(str_contains($joined, '/examples/deployment/apache-vhost.conf'), 'Package should include Apache deployment example');
        assert_true(str_contains($joined, '/examples/deployment/nginx-server.conf'), 'Package should include Nginx deployment example');
        assert_true(str_contains($joined, '/database/migrations/014_round_schedule_day.sql'), 'Package should include migrations');
        assert_true(str_contains($joined, '/database/migrations/047_internal_event_deliveries.sql'), 'Package should include SQLite internal observer delivery migration');
        assert_true(str_contains($joined, '/database/migrations/048_module_enabled_constraint.sql'), 'Package should include SQLite module enabled constraint migration');
        assert_true(str_contains($joined, '/database/migrations/049_public_event_projection.sql'), 'Package should include SQLite public event migration');
        assert_true(str_contains($joined, '/database/migrations/050_signed_webhook_integrations.sql'), 'Package should include SQLite signed webhook migration');
        assert_true(str_contains($joined, '/database/migrations/051_webhook_claim_cursor.sql'), 'Package should include SQLite webhook fairness cursor migration');
        assert_true(str_contains($joined, '/database/migrations/052_api_identity_foundation.sql'), 'Package should include SQLite API identity migration');
        assert_true(str_contains($joined, '/database/migrations/053_api_read_pagination_indexes.sql'), 'Package should include SQLite API pagination indexes');
        assert_true(str_contains($joined, '/database/migrations/pgsql/001_portal_baseline.sql'), 'Package should include PostgreSQL migrations');
        assert_true(str_contains($joined, '/database/migrations/pgsql/011_internal_event_deliveries.sql'), 'Package should include PostgreSQL internal observer delivery migration');
        assert_true(str_contains($joined, '/database/migrations/pgsql/012_module_enabled_constraint.sql'), 'Package should include PostgreSQL module enabled constraint migration');
        assert_true(str_contains($joined, '/database/migrations/pgsql/013_public_event_projection.sql'), 'Package should include PostgreSQL public event migration');
        assert_true(str_contains($joined, '/database/migrations/pgsql/014_signed_webhook_integrations.sql'), 'Package should include PostgreSQL signed webhook migration');
        assert_true(str_contains($joined, '/database/migrations/pgsql/015_webhook_claim_cursor.sql'), 'Package should include PostgreSQL webhook fairness cursor migration');
        assert_true(str_contains($joined, '/database/migrations/pgsql/016_api_identity_foundation.sql'), 'Package should include PostgreSQL API identity migration');
        assert_true(str_contains($joined, '/database/migrations/pgsql/017_api_read_pagination_indexes.sql'), 'Package should include PostgreSQL API pagination indexes');
        assert_true(str_contains($joined, '/data/.gitkeep'), 'Package should keep an empty data directory marker');
        assert_true(!str_contains($joined, '.legacy-private'), 'Package should exclude private archive material');
        assert_true(!str_contains($joined, 'data/app.sqlite'), 'Package should exclude runtime SQLite data');
        assert_true(!str_contains($joined, 'data/restore-staging'), 'Package should exclude restore staging data');
        assert_true(!str_contains($joined, 'config/local.php'), 'Package should exclude local configuration');
        assert_true(!str_contains($joined, '.playwright-cli'), 'Package should exclude local browser QA scratch files');
        assert_true(!str_contains($joined, '/website/'), 'Self-hosted package should exclude the public website build project');

        foreach ([$tarPath, $zipPath] as $archivePath) {
            [$verifyCode, $verifyOut, $verifyErr] = run_cli(['verify-package', $archivePath]);
            assert_same(0, $verifyCode, 'Package verifier should accept matching checksum: ' . $archivePath . ' ' . $verifyErr);
            assert_true(str_contains($verifyOut, 'Package checksum verified'), 'Package verifier should report checksum verification');
            assert_true(str_contains($verifyOut, 'Package archive inspected'), 'Package verifier should inspect archive structure');
            assert_true(str_contains($verifyOut, 'Package integration JSON verified'), 'Package verifier should validate public integration JSON');
        }

        $tarArchive = new PharData($tarPath);
        $tarArchive->extractTo($tarExtractDir);
        $tarPackageRoot = $tarExtractDir . '/' . $rootName;
        [$tarPublicationCode, $tarPublicationOut, $tarPublicationErr] = run_cli_from($tarPackageRoot, ['publication-check']);
        assert_same(0, $tarPublicationCode, 'Git-free extracted tarball publication check should pass: ' . $tarPublicationErr);
        assert_true(str_contains($tarPublicationOut, 'Public integration JSON'), 'Extracted tarball should validate public integration JSON');
        $tarContractTmp = $tarExtractDir . '/public-event-tmp';
        assert_true(mkdir($tarContractTmp, 0700, true), 'Could not create extracted tarball contract temp directory');
        [$tarContractCode, $tarContractOut, $tarContractErr] = run_php_from($tarPackageRoot, 'tests/public_event_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $tarContractTmp,
        ]);
        assert_same(0, $tarContractCode, 'Git-free extracted tarball public event contract should pass: ' . $tarContractErr);
        assert_true(str_contains($tarContractOut, 'PASS public event contract (sqlite'), 'Extracted tarball should run the portable public event contract');
        $tarWebhookTmp = $tarExtractDir . '/webhook-delivery-tmp';
        assert_true(mkdir($tarWebhookTmp, 0700, true), 'Could not create extracted tarball webhook contract temp directory');
        [$tarWebhookCode, $tarWebhookOut, $tarWebhookErr] = run_php_from($tarPackageRoot, 'tests/webhook_delivery_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $tarWebhookTmp,
        ]);
        assert_same(0, $tarWebhookCode, 'Git-free extracted tarball webhook delivery contract should pass: ' . $tarWebhookErr);
        assert_true(str_contains($tarWebhookOut, 'PASS signed webhook delivery contract (sqlite'), 'Extracted tarball should run the portable webhook delivery contract');
        $tarWebhookConcurrencyTmp = $tarExtractDir . '/webhook-concurrency-tmp';
        assert_true(mkdir($tarWebhookConcurrencyTmp, 0700, true), 'Could not create extracted tarball webhook concurrency temp directory');
        [$tarWebhookConcurrencyCode, $tarWebhookConcurrencyOut, $tarWebhookConcurrencyErr] = run_php_from($tarPackageRoot, 'tests/webhook_delivery_concurrency_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $tarWebhookConcurrencyTmp,
        ]);
        assert_same(0, $tarWebhookConcurrencyCode, 'Git-free extracted tarball webhook concurrency contract should pass: ' . $tarWebhookConcurrencyErr);
        assert_true(str_contains($tarWebhookConcurrencyOut, 'PASS webhook delivery concurrency contract (sqlite'), 'Extracted tarball should run the portable webhook concurrency contract');
        $tarWebhookRevokeTmp = $tarExtractDir . '/webhook-revoke-concurrency-tmp';
        assert_true(mkdir($tarWebhookRevokeTmp, 0700, true), 'Could not create extracted tarball webhook revoke concurrency temp directory');
        [$tarWebhookRevokeCode, $tarWebhookRevokeOut, $tarWebhookRevokeErr] = run_php_from($tarPackageRoot, 'tests/webhook_revoke_completion_concurrency_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $tarWebhookRevokeTmp,
        ]);
        assert_same(0, $tarWebhookRevokeCode, 'Git-free extracted tarball webhook revoke concurrency contract should pass: ' . $tarWebhookRevokeErr);
        assert_true(str_contains($tarWebhookRevokeOut, 'PASS webhook revoke/completion concurrency contract (sqlite'), 'Extracted tarball should run portable webhook revoke/completion fencing');
        $tarWebhookCaptureTmp = $tarExtractDir . '/webhook-capture-revoke-tmp';
        assert_true(mkdir($tarWebhookCaptureTmp, 0700, true), 'Could not create extracted tarball webhook capture/revoke temp directory');
        [$tarWebhookCaptureCode, $tarWebhookCaptureOut, $tarWebhookCaptureErr] = run_php_from($tarPackageRoot, 'tests/webhook_capture_revoke_concurrency_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $tarWebhookCaptureTmp,
        ]);
        assert_same(0, $tarWebhookCaptureCode, 'Git-free extracted tarball webhook capture/revoke contract should pass: ' . $tarWebhookCaptureErr);
        assert_true(str_contains($tarWebhookCaptureOut, 'PASS webhook capture/revoke and fairness contract (sqlite'), 'Extracted tarball should run portable capture/revoke serialization and durable fairness');
        $tarReceiverTmp = $tarExtractDir . '/webhook-receiver-tmp';
        assert_true(mkdir($tarReceiverTmp, 0700, true), 'Could not create extracted tarball receiver contract temp directory');
        [$tarReceiverCode, $tarReceiverOut, $tarReceiverErr] = run_php_from(
            $tarPackageRoot,
            'tests/webhook_receiver_example_contract.php',
            ['TMPDIR' => $tarReceiverTmp],
        );
        assert_same(0, $tarReceiverCode, 'Git-free extracted tarball receiver example contract should pass: ' . $tarReceiverErr);
        assert_true(str_contains($tarReceiverOut, 'PASS webhook receiver bounded-input example contract'), 'Extracted tarball should run the bounded receiver example contract');
        $tarApiTmp = $tarExtractDir . '/api-identity-tmp';
        assert_true(mkdir($tarApiTmp, 0700, true), 'Could not create extracted tarball API identity temp directory');
        [$tarApiCode, $tarApiOut, $tarApiErr] = run_php_from($tarPackageRoot, 'tests/api_identity_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $tarApiTmp,
        ]);
        assert_same(0, $tarApiCode, 'Git-free extracted tarball API identity contract should pass: ' . $tarApiErr);
        assert_true(str_contains($tarApiOut, 'PASS API identity contract (sqlite'), 'Extracted tarball should run the API identity contract');
        [$tarApiConcurrencyCode, $tarApiConcurrencyOut, $tarApiConcurrencyErr] = run_php_from($tarPackageRoot, 'tests/api_identity_rotation_concurrency_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $tarApiTmp,
        ]);
        assert_same(0, $tarApiConcurrencyCode, 'Git-free extracted tarball API rotation concurrency contract should pass: ' . $tarApiConcurrencyErr);
        assert_true(str_contains($tarApiConcurrencyOut, 'PASS API token rotation concurrency (sqlite'), 'Extracted tarball should run API rotation concurrency');
        $tarApiHttpTmp = $tarExtractDir . '/api-http-tmp';
        assert_true(mkdir($tarApiHttpTmp, 0700, true), 'Could not create extracted tarball API HTTP temp directory');
        [$tarApiHttpCode, $tarApiHttpOut, $tarApiHttpErr] = run_php_from($tarPackageRoot, 'tests/api_http_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $tarApiHttpTmp,
        ]);
        assert_same(0, $tarApiHttpCode, 'Git-free extracted tarball API HTTP contract should pass: ' . $tarApiHttpErr);
        assert_true(str_contains($tarApiHttpOut, 'PASS public API HTTP contract (sqlite'), 'Extracted tarball should run the public API HTTP contract');
        $tarTransitionTmp = $tarExtractDir . '/application-transition-boundary-tmp';
        assert_true(mkdir($tarTransitionTmp, 0700, true), 'Could not create extracted tarball application transition boundary temp directory');
        [$tarTransitionCode, $tarTransitionOut, $tarTransitionErr] = run_php_from($tarPackageRoot, 'tests/application_transition_boundary_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $tarTransitionTmp,
        ]);
        assert_same(0, $tarTransitionCode, 'Git-free extracted tarball application transition boundary contract should pass: ' . $tarTransitionErr);
        assert_true(str_contains($tarTransitionOut, 'PASS application transition boundary contract (sqlite shared transaction'), 'Extracted tarball should run the application transition boundary contract');
        $tarCommandTmp = $tarExtractDir . '/api-command-tmp';
        assert_true(mkdir($tarCommandTmp, 0700, true), 'Could not create extracted tarball API command temp directory');
        [$tarCommandCode, $tarCommandOut, $tarCommandErr] = run_php_from($tarPackageRoot, 'tests/api_application_transition_command_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $tarCommandTmp,
        ]);
        assert_same(0, $tarCommandCode, 'Git-free extracted tarball API command contract should pass: ' . $tarCommandErr);
        assert_true(str_contains($tarCommandOut, 'PASS API application transition command contract (sqlite'), 'Extracted tarball should run the API command contract');
        [$tarCommandRaceCode, $tarCommandRaceOut, $tarCommandRaceErr] = run_php_from($tarPackageRoot, 'tests/api_application_transition_command_concurrency_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $tarCommandTmp,
        ]);
        assert_same(0, $tarCommandRaceCode, 'Git-free extracted tarball API command concurrency contract should pass: ' . $tarCommandRaceErr);
        assert_true(str_contains($tarCommandRaceOut, 'PASS API application transition command concurrency (sqlite'), 'Extracted tarball should run API command same-key concurrency');

        $zipArchive = new PharData($zipPath);
        $zipArchive->extractTo($extractDir);
        $packageRoot = $extractDir . '/' . $rootName;
        assert_true(is_file($packageRoot . '/placement'), 'Extracted package should include CLI entrypoint');
        assert_true(is_file($packageRoot . '/public/index.php'), 'Extracted package should include web entrypoint');

        [$publicationCode, $publicationOut, $publicationErr] = run_cli_from($packageRoot, ['publication-check']);
        assert_same(0, $publicationCode, 'Git-free extracted package publication check should pass: ' . $publicationErr);
        assert_true(str_contains($publicationOut, 'OK: Required release files are present.'), 'Extracted package publication check should report success');
        $zipContractTmp = $extractDir . '/public-event-tmp';
        assert_true(mkdir($zipContractTmp, 0700, true), 'Could not create extracted ZIP contract temp directory');
        [$packageContractCode, $packageContractOut, $packageContractErr] = run_php_from($packageRoot, 'tests/public_event_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $zipContractTmp,
        ]);
        assert_same(0, $packageContractCode, 'Git-free extracted ZIP public event contract should pass: ' . $packageContractErr);
        assert_true(str_contains($packageContractOut, 'PASS public event contract (sqlite'), 'Extracted ZIP should run the portable public event contract');
        $zipWebhookTmp = $extractDir . '/webhook-delivery-tmp';
        assert_true(mkdir($zipWebhookTmp, 0700, true), 'Could not create extracted ZIP webhook contract temp directory');
        [$packageWebhookCode, $packageWebhookOut, $packageWebhookErr] = run_php_from($packageRoot, 'tests/webhook_delivery_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $zipWebhookTmp,
        ]);
        assert_same(0, $packageWebhookCode, 'Git-free extracted ZIP webhook delivery contract should pass: ' . $packageWebhookErr);
        assert_true(str_contains($packageWebhookOut, 'PASS signed webhook delivery contract (sqlite'), 'Extracted ZIP should run the portable webhook delivery contract');
        $zipWebhookConcurrencyTmp = $extractDir . '/webhook-concurrency-tmp';
        assert_true(mkdir($zipWebhookConcurrencyTmp, 0700, true), 'Could not create extracted ZIP webhook concurrency temp directory');
        [$packageWebhookConcurrencyCode, $packageWebhookConcurrencyOut, $packageWebhookConcurrencyErr] = run_php_from($packageRoot, 'tests/webhook_delivery_concurrency_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $zipWebhookConcurrencyTmp,
        ]);
        assert_same(0, $packageWebhookConcurrencyCode, 'Git-free extracted ZIP webhook concurrency contract should pass: ' . $packageWebhookConcurrencyErr);
        assert_true(str_contains($packageWebhookConcurrencyOut, 'PASS webhook delivery concurrency contract (sqlite'), 'Extracted ZIP should run the portable webhook concurrency contract');
        $zipWebhookRevokeTmp = $extractDir . '/webhook-revoke-concurrency-tmp';
        assert_true(mkdir($zipWebhookRevokeTmp, 0700, true), 'Could not create extracted ZIP webhook revoke concurrency temp directory');
        [$packageWebhookRevokeCode, $packageWebhookRevokeOut, $packageWebhookRevokeErr] = run_php_from($packageRoot, 'tests/webhook_revoke_completion_concurrency_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $zipWebhookRevokeTmp,
        ]);
        assert_same(0, $packageWebhookRevokeCode, 'Git-free extracted ZIP webhook revoke concurrency contract should pass: ' . $packageWebhookRevokeErr);
        assert_true(str_contains($packageWebhookRevokeOut, 'PASS webhook revoke/completion concurrency contract (sqlite'), 'Extracted ZIP should run portable webhook revoke/completion fencing');
        $zipWebhookCaptureTmp = $extractDir . '/webhook-capture-revoke-tmp';
        assert_true(mkdir($zipWebhookCaptureTmp, 0700, true), 'Could not create extracted ZIP webhook capture/revoke temp directory');
        [$packageWebhookCaptureCode, $packageWebhookCaptureOut, $packageWebhookCaptureErr] = run_php_from($packageRoot, 'tests/webhook_capture_revoke_concurrency_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $zipWebhookCaptureTmp,
        ]);
        assert_same(0, $packageWebhookCaptureCode, 'Git-free extracted ZIP webhook capture/revoke contract should pass: ' . $packageWebhookCaptureErr);
        assert_true(str_contains($packageWebhookCaptureOut, 'PASS webhook capture/revoke and fairness contract (sqlite'), 'Extracted ZIP should run portable capture/revoke serialization and durable fairness');
        $zipReceiverTmp = $extractDir . '/webhook-receiver-tmp';
        assert_true(mkdir($zipReceiverTmp, 0700, true), 'Could not create extracted ZIP receiver contract temp directory');
        [$packageReceiverCode, $packageReceiverOut, $packageReceiverErr] = run_php_from(
            $packageRoot,
            'tests/webhook_receiver_example_contract.php',
            ['TMPDIR' => $zipReceiverTmp],
        );
        assert_same(0, $packageReceiverCode, 'Git-free extracted ZIP receiver example contract should pass: ' . $packageReceiverErr);
        assert_true(str_contains($packageReceiverOut, 'PASS webhook receiver bounded-input example contract'), 'Extracted ZIP should run the bounded receiver example contract');
        $zipApiTmp = $extractDir . '/api-identity-tmp';
        assert_true(mkdir($zipApiTmp, 0700, true), 'Could not create extracted ZIP API identity temp directory');
        [$packageApiCode, $packageApiOut, $packageApiErr] = run_php_from($packageRoot, 'tests/api_identity_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $zipApiTmp,
        ]);
        assert_same(0, $packageApiCode, 'Git-free extracted ZIP API identity contract should pass: ' . $packageApiErr);
        assert_true(str_contains($packageApiOut, 'PASS API identity contract (sqlite'), 'Extracted ZIP should run the API identity contract');
        [$packageApiConcurrencyCode, $packageApiConcurrencyOut, $packageApiConcurrencyErr] = run_php_from($packageRoot, 'tests/api_identity_rotation_concurrency_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $zipApiTmp,
        ]);
        assert_same(0, $packageApiConcurrencyCode, 'Git-free extracted ZIP API rotation concurrency contract should pass: ' . $packageApiConcurrencyErr);
        assert_true(str_contains($packageApiConcurrencyOut, 'PASS API token rotation concurrency (sqlite'), 'Extracted ZIP should run API rotation concurrency');
        $zipApiHttpTmp = $extractDir . '/api-http-tmp';
        assert_true(mkdir($zipApiHttpTmp, 0700, true), 'Could not create extracted ZIP API HTTP temp directory');
        [$packageApiHttpCode, $packageApiHttpOut, $packageApiHttpErr] = run_php_from($packageRoot, 'tests/api_http_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $zipApiHttpTmp,
        ]);
        assert_same(0, $packageApiHttpCode, 'Git-free extracted ZIP API HTTP contract should pass: ' . $packageApiHttpErr);
        assert_true(str_contains($packageApiHttpOut, 'PASS public API HTTP contract (sqlite'), 'Extracted ZIP should run the public API HTTP contract');
        $zipTransitionTmp = $extractDir . '/application-transition-boundary-tmp';
        assert_true(mkdir($zipTransitionTmp, 0700, true), 'Could not create extracted ZIP application transition boundary temp directory');
        [$packageTransitionCode, $packageTransitionOut, $packageTransitionErr] = run_php_from($packageRoot, 'tests/application_transition_boundary_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $zipTransitionTmp,
        ]);
        assert_same(0, $packageTransitionCode, 'Git-free extracted ZIP application transition boundary contract should pass: ' . $packageTransitionErr);
        assert_true(str_contains($packageTransitionOut, 'PASS application transition boundary contract (sqlite shared transaction'), 'Extracted ZIP should run the application transition boundary contract');
        $zipCommandTmp = $extractDir . '/api-command-tmp';
        assert_true(mkdir($zipCommandTmp, 0700, true), 'Could not create extracted ZIP API command temp directory');
        [$packageCommandCode, $packageCommandOut, $packageCommandErr] = run_php_from($packageRoot, 'tests/api_application_transition_command_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $zipCommandTmp,
        ]);
        assert_same(0, $packageCommandCode, 'Git-free extracted ZIP API command contract should pass: ' . $packageCommandErr);
        assert_true(str_contains($packageCommandOut, 'PASS API application transition command contract (sqlite'), 'Extracted ZIP should run the API command contract');
        [$packageCommandRaceCode, $packageCommandRaceOut, $packageCommandRaceErr] = run_php_from($packageRoot, 'tests/api_application_transition_command_concurrency_contract.php', [
            'CPE_DB_DRIVER' => '',
            'CPE_DATABASE_URL' => '',
            'TMPDIR' => $zipCommandTmp,
        ]);
        assert_same(0, $packageCommandRaceCode, 'Git-free extracted ZIP API command concurrency contract should pass: ' . $packageCommandRaceErr);
        assert_true(str_contains($packageCommandRaceOut, 'PASS API application transition command concurrency (sqlite'), 'Extracted ZIP should run API command same-key concurrency');

        $packageRuntimeFixture = $packageRoot . '/data/restore-staging/package-contract.sqlite';
        try {
            if (!is_dir(dirname($packageRuntimeFixture)) && !mkdir(dirname($packageRuntimeFixture), 0700, true)) {
                throw new RuntimeException('Could not create extracted-package runtime-data fixture directory.');
            }
            assert_true(file_put_contents($packageRuntimeFixture, 'runtime-only') !== false, 'Could not create extracted-package runtime-data fixture');
            [$runtimePublicationCode, $runtimePublicationOut, $runtimePublicationErr] = run_cli_from($packageRoot, ['publication-check']);
            assert_same(1, $runtimePublicationCode, 'Git-free extracted package publication check should reject runtime data');
            assert_true(
                str_contains($runtimePublicationOut . $runtimePublicationErr, 'data/restore-staging/package-contract.sqlite'),
                'Git-free extracted package publication check should report the deterministic runtime-data path',
            );
        } finally {
            remove_tree($packageRoot . '/data/restore-staging');
        }
        assert_true(!file_exists($packageRuntimeFixture), 'Extracted-package runtime-data fixture should be cleaned up');

        $packageSymlinkFixture = $packageRoot . '/data/runtime-link';
        try {
            assert_true(symlink($packageRoot . '/README.md', $packageSymlinkFixture), 'Could not create extracted-package data symlink fixture');
            [$symlinkPublicationCode, $symlinkPublicationOut, $symlinkPublicationErr] = run_cli_from($packageRoot, ['publication-check']);
            assert_same(1, $symlinkPublicationCode, 'Git-free extracted package publication check should reject data symlinks');
            assert_true(
                str_contains($symlinkPublicationOut . $symlinkPublicationErr, 'data/runtime-link'),
                'Git-free extracted package publication check should report the symlink path without following it',
            );
        } finally {
            if (is_link($packageSymlinkFixture)) {
                unlink($packageSymlinkFixture);
            }
        }
        assert_true(!is_link($packageSymlinkFixture), 'Extracted-package data symlink fixture should be cleaned up');

        [$doctorCode, $doctorOut, $doctorErr] = run_cli_from($packageRoot, ['doctor'], ['CPE_DB_PATH' => $packageDb]);
        assert_same(0, $doctorCode, 'Extracted package doctor should run: ' . $doctorErr);
        assert_true(str_contains($doctorOut, 'pdo_sqlite: yes'), 'Extracted package doctor should detect SQLite extension');

        [$installCode, $installOut, $installErr] = run_cli_from($packageRoot, [
            'install',
            '--college=Package Smoke College',
            '--timezone=Asia/Kolkata',
            '--workflow=default',
            '--admin-name=Package Admin',
            '--admin-email=package-admin@example.test',
        ], [
            'CPE_DB_PATH' => $packageDb,
            'CPE_ADMIN_PASSWORD' => 'password123',
        ]);
        assert_same(0, $installCode, 'Extracted package install should succeed: ' . $installErr);
        assert_true(str_contains($installOut, 'Installed app.'), 'Extracted package install should report success');

        [$readyCode, $readyOut, $readyErr] = run_cli_from($packageRoot, ['readiness'], ['CPE_DB_PATH' => $packageDb]);
        assert_same(0, $readyCode, 'Extracted package readiness should run: ' . $readyErr);
        assert_true(str_contains($readyOut, 'Workflow configuration'), 'Extracted package readiness should validate workflow');

        $packageExport = $extractDir . '/export-smoke';
        [$exportCode, $exportOut, $exportErr] = run_cli_from($packageRoot, ['export', $packageExport], ['CPE_DB_PATH' => $packageDb]);
        assert_same(0, $exportCode, 'Extracted package export should run: ' . $exportErr);
        assert_true(str_contains($exportOut, 'Export written'), 'Extracted package export should report output');
        assert_true(is_file($packageExport . '/manifest.csv'), 'Extracted package export should write manifest');

        $tarChecksumPath = $tarPath . '.sha256';
        file_put_contents($tarChecksumPath, str_repeat('0', 64) . '  ' . basename($tarPath) . "\n");
        [$badVerifyCode, $badVerifyOut, $badVerifyErr] = run_cli(['verify-package', $tarPath]);
        assert_same(1, $badVerifyCode, 'Package verifier should reject mismatched checksum');
        assert_true(str_contains($badVerifyErr, 'Release package checksum verification failed'), 'Package verifier should explain checksum failure');
    } finally {
        remove_tree($target);
        remove_tree($extractDir);
        remove_tree($tarExtractDir);
        if (is_file($packageDb)) {
            unlink($packageDb);
        }
        remove_tree($runtimeStaging);
    }
});

test_case('cli install creates a non-demo app from supplied setup options', function (): void {
    $db = sys_get_temp_dir() . '/cpe-cli-install-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        [$code, $stdout, $stderr] = run_cli([
            'install',
            '--college=CLI College',
            '--site-name=CLI Placement Desk',
            '--site-tagline=CLI Live Ops',
            '--timezone=Asia/Kolkata',
            '--cycle-name=CLI Final Cycle',
            '--cycle-type=pooled',
            '--cycle-start-date=2026-07-01',
            '--cycle-end-date=2026-07-03',
            '--non-operating-weekdays=sat,sun',
            '--non-operating-dates=2026-07-02',
            '--audit-request-metadata=ip',
            '--workflow=pooled_campus_drive',
            '--candidate-label=Learner',
            '--candidates-label=Learners',
            '--company-label=Recruiter',
            '--companies-label=Recruiters',
            '--admin-name=CLI Admin',
            '--admin-email=cli-admin@example.test',
        ], [
            'CPE_DB_PATH' => $db,
            'CPE_ADMIN_PASSWORD' => 'password123',
        ]);
        assert_same(0, $code, 'CLI install should exit cleanly: ' . $stderr);
        assert_true(str_contains($stdout, 'Installed app.'), 'CLI install should report success');

        [$repeatCode, $repeatOut, $repeatErr] = run_cli([
            'install',
            '--college=Overwrite College',
            '--admin-name=Overwrite Admin',
            '--admin-email=overwrite@example.test',
            '--admin-password=password123',
        ], ['CPE_DB_PATH' => $db]);
        assert_true($repeatCode !== 0, 'Repeated CLI install should fail closed');
        assert_same('', $repeatOut, 'Repeated CLI install should not report success');
        assert_true(str_contains($repeatErr, 'already installed'), 'Repeated CLI install should explain the install lock');

        [$repeatDemoCode, $repeatDemoOut, $repeatDemoErr] = run_cli(['install-demo'], ['CPE_DB_PATH' => $db]);
        assert_true($repeatDemoCode !== 0, 'Repeated demo install should fail closed');
        assert_same('', $repeatDemoOut, 'Repeated demo install should not report success');
        assert_true(str_contains($repeatDemoErr, 'already installed'), 'Repeated demo install should explain the install lock');

        [$doctorCode, $doctorOut, $doctorErr] = run_cli(['doctor'], ['CPE_DB_PATH' => $db]);
        assert_same(0, $doctorCode, 'Doctor should run against CLI-installed database: ' . $doctorErr);
        assert_true(str_contains($doctorOut, 'installed: yes'), 'Doctor should report installed database');

        $pdo = new PDO('sqlite:' . $db);
        $college = $pdo->query("SELECT value FROM settings WHERE key = 'college_name'")->fetchColumn();
        $siteName = $pdo->query("SELECT value FROM settings WHERE key = 'site_name'")->fetchColumn();
        $siteTagline = $pdo->query("SELECT value FROM settings WHERE key = 'site_tagline'")->fetchColumn();
        $cycleName = $pdo->query("SELECT value FROM settings WHERE key = 'cycle_name'")->fetchColumn();
        $cycleType = $pdo->query("SELECT value FROM settings WHERE key = 'cycle_type'")->fetchColumn();
        $cycleStart = $pdo->query("SELECT value FROM settings WHERE key = 'cycle_start_date'")->fetchColumn();
        $cycleEnd = $pdo->query("SELECT value FROM settings WHERE key = 'cycle_end_date'")->fetchColumn();
        $nonOperatingWeekdays = $pdo->query("SELECT value FROM settings WHERE key = 'calendar_non_operating_weekdays'")->fetchColumn();
        $nonOperatingDates = $pdo->query("SELECT value FROM settings WHERE key = 'calendar_non_operating_dates'")->fetchColumn();
        $auditMetadata = $pdo->query("SELECT value FROM settings WHERE key = 'audit_request_metadata'")->fetchColumn();
        $workflow = $pdo->query("SELECT value FROM settings WHERE key = 'workflow'")->fetchColumn();
        $candidateLabel = $pdo->query("SELECT value FROM settings WHERE key = 'terminology_candidate_label'")->fetchColumn();
        $candidatesLabel = $pdo->query("SELECT value FROM settings WHERE key = 'terminology_candidates_label'")->fetchColumn();
        $companyLabel = $pdo->query("SELECT value FROM settings WHERE key = 'terminology_company_label'")->fetchColumn();
        $companiesLabel = $pdo->query("SELECT value FROM settings WHERE key = 'terminology_companies_label'")->fetchColumn();
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'cli-admin@example.test' AND role = 'admin'")->fetchColumn();
        $candidateCount = (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn();
        assert_same('CLI College', $college, 'CLI install should save college name');
        assert_same('CLI Placement Desk', $siteName, 'CLI install should save site name');
        assert_same('CLI Live Ops', $siteTagline, 'CLI install should save site tagline');
        assert_same('CLI Final Cycle', $cycleName, 'CLI install should save cycle name');
        assert_same('pooled', $cycleType, 'CLI install should save cycle type');
        assert_same('2026-07-01', $cycleStart, 'CLI install should save cycle start date');
        assert_same('2026-07-03', $cycleEnd, 'CLI install should save cycle end date');
        assert_same('sat,sun', $nonOperatingWeekdays, 'CLI install should save non-operating weekdays');
        assert_same('2026-07-02', $nonOperatingDates, 'CLI install should save non-operating dates');
        assert_same('ip', $auditMetadata, 'CLI install should save audit request metadata mode');
        assert_same('pooled_campus_drive', $workflow, 'CLI install should save selected starter workflow');
        assert_same('Learner', $candidateLabel, 'CLI install should save candidate singular terminology');
        assert_same('Learners', $candidatesLabel, 'CLI install should save candidate plural terminology');
        assert_same('Recruiter', $companyLabel, 'CLI install should save company singular terminology');
        assert_same('Recruiters', $companiesLabel, 'CLI install should save company plural terminology');
        assert_same(1, $adminCount, 'CLI install should create supplied administrator');
        assert_same(0, $candidateCount, 'CLI install should not seed demo data unless requested');
    } finally {
        if (is_file($db)) {
            unlink($db);
        }
    }
});

test_case('upgrade command backs up migrates and runs readiness', function (): void {
    $db = sys_get_temp_dir() . '/cpe-cli-upgrade-' . bin2hex(random_bytes(4)) . '.sqlite';
    $backupDirectory = sys_get_temp_dir() . '/cpe-cli-upgrade-backups-' . bin2hex(random_bytes(4));
    $backup = null;
    try {
        [$installCode, $installOut, $installErr] = run_cli(['install-demo'], ['CPE_DB_PATH' => $db]);
        assert_same(0, $installCode, 'Demo install should prepare upgrade database: ' . $installErr);
        assert_true(str_contains($installOut, 'Installed demo app.'), 'Demo install should report success');

        [$helpCode, $helpOut, $helpErr] = run_cli(['upgrade', '--help'], ['CPE_DB_PATH' => $db]);
        assert_same(0, $helpCode, 'Upgrade help should exit cleanly: ' . $helpErr);
        assert_true(str_contains($helpOut, 'writes a timestamped database backup'), 'Upgrade help should describe backup-first behavior');

        [$code, $stdout, $stderr] = run_cli(['upgrade'], [
            'CPE_DB_PATH' => $db,
            'CPE_BACKUP_DIR' => $backupDirectory,
        ]);
        assert_same(0, $code, 'Upgrade should exit cleanly: ' . $stderr);
        assert_true(str_contains($stdout, 'Upgrade backup written.'), 'Upgrade should write a backup before migrations');
        assert_true(str_contains($stdout, 'Migrations applied.'), 'Upgrade should apply migrations');
        assert_true(str_contains($stdout, 'Workflow configuration'), 'Upgrade should print readiness checks');
        assert_true(str_contains($stdout, 'Upgrade complete.'), 'Upgrade should report completion');
        assert_true(!str_contains($stdout, $backupDirectory), 'Upgrade output should not expose the recovery directory');
        $backups = glob($backupDirectory . '/upgrade-*.sqlite') ?: [];
        assert_same(1, count($backups), 'Upgrade should create exactly one safety backup');
        $backup = $backups[0];
        assert_true(is_file($backup), 'Upgrade backup file should exist');
        assert_true(is_file($backup . '.sha256'), 'Upgrade backup checksum should exist');
        assert_true(is_file($backup . '.metadata.json'), 'Upgrade backup metadata should exist');
        assert_true(str_contains((string) file_get_contents($backup . '.sha256'), hash_file('sha256', $backup)), 'Upgrade backup checksum should match backup file');
    } finally {
        if (is_file($db)) {
            unlink($db);
        }
        if (is_string($backup) && is_file($backup . '.sha256')) {
            unlink($backup . '.sha256');
        }
        if (is_string($backup) && is_file($backup . '.metadata.json')) {
            unlink($backup . '.metadata.json');
        }
        if (is_string($backup) && is_file($backup)) {
            unlink($backup);
        }
        if (is_dir($backupDirectory)) {
            rmdir($backupDirectory);
        }
    }
});

test_case('backup and restore verify checksum sidecars', function (): void {
    $db = sys_get_temp_dir() . '/cpe-cli-backup-' . bin2hex(random_bytes(4)) . '.sqlite';
    $backupDirectory = sys_get_temp_dir() . '/cpe-cli-backup-files-' . bin2hex(random_bytes(4));
    $backup = null;
    $unverified = null;
    try {
        [$installCode, $installOut, $installErr] = run_cli(['install-demo'], ['CPE_DB_PATH' => $db]);
        assert_same(0, $installCode, 'Demo install should prepare backup database: ' . $installErr);

        [$backupCode, $backupOut, $backupErr] = run_cli(['backup'], [
            'CPE_DB_PATH' => $db,
            'CPE_BACKUP_DIR' => $backupDirectory,
        ]);
        assert_same(0, $backupCode, 'Backup command should exit cleanly: ' . $backupErr);
        assert_true((bool) preg_match('/Backup written: backup_[a-f0-9]{24}/', $backupOut), 'Backup output should include an opaque backup reference');
        assert_true(!str_contains($backupOut, $backupDirectory), 'Backup output should not expose the recovery directory');
        $backups = glob($backupDirectory . '/app-*.sqlite') ?: [];
        assert_same(1, count($backups), 'Backup command should create one database backup');
        $backup = $backups[0];
        assert_true(is_file($backup), 'Backup file should exist');
        assert_true(is_file($backup . '.sha256'), 'Backup checksum should exist');
        assert_true(is_file($backup . '.metadata.json'), 'Backup identity metadata should exist');
        assert_true(str_contains((string) file_get_contents($backup . '.sha256'), hash_file('sha256', $backup)), 'Backup checksum should match backup file');

        $pdo = new PDO('sqlite:' . $db);
        $before = (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn();
        $pdo->exec("DELETE FROM candidates WHERE external_id = 'C001'");
        $afterDelete = (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn();
        assert_true($afterDelete < $before, 'Test mutation should change database before restore');
        $pdo = null;

        [$restoreCode, $restoreOut, $restoreErr] = run_cli(['restore', $backup], [
            'CPE_DB_PATH' => $db,
            'CPE_BACKUP_DIR' => $backupDirectory,
        ]);
        assert_same(0, $restoreCode, 'Restore command should exit cleanly with valid checksum: ' . $restoreErr);
        assert_true(str_contains($restoreOut, 'Restored database from'), 'Restore should report success');
        assert_true(!str_contains($restoreOut, $backupDirectory), 'Restore output should not expose the recovery directory');
        $pdo = new PDO('sqlite:' . $db);
        assert_same($before, (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn(), 'Restore should restore database from backup');
        $pdo = null;

        $unverified = sys_get_temp_dir() . '/cpe-unverified-backup-' . bin2hex(random_bytes(4)) . '.sqlite';
        copy($backup, $unverified);
        [$unverifiedCode, $unverifiedOut, $unverifiedErr] = run_cli(['restore', $unverified], [
            'CPE_DB_PATH' => $db,
            'CPE_BACKUP_DIR' => $backupDirectory,
        ]);
        assert_same(1, $unverifiedCode, 'Restore should fail when the checksum sidecar is missing');
        assert_true(str_contains($unverifiedErr, 'checksum file is required'), 'Missing checksum failure should be explicit');

        $badChecksumLines = file($backup . '.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $badChecksumLines[0] = str_repeat('0', 64) . '  ' . basename($backup);
        file_put_contents($backup . '.sha256', implode("\n", $badChecksumLines) . "\n");
        [$badRestoreCode, $badRestoreOut, $badRestoreErr] = run_cli(['restore', $backup], [
            'CPE_DB_PATH' => $db,
            'CPE_BACKUP_DIR' => $backupDirectory,
        ]);
        assert_same(1, $badRestoreCode, 'Restore should fail when checksum does not match');
        assert_true(str_contains($badRestoreErr, 'checksum verification failed'), 'Restore failure should explain checksum mismatch');
    } finally {
        if (is_file($db)) {
            unlink($db);
        }
        foreach (glob($backupDirectory . '/*') ?: [] as $backupFile) {
            if (is_file($backupFile)) {
                unlink($backupFile);
            }
        }
        if (is_dir($backupDirectory)) {
            rmdir($backupDirectory);
        }
        if (is_string($unverified) && is_file($unverified)) {
            unlink($unverified);
        }
    }
});

test_case('doctor enforces local runtime requirements before install', function (): void {
    $db = sys_get_temp_dir() . '/cpe-doctor-preflight-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        [$code, $stdout, $stderr] = run_cli(['doctor'], ['CPE_DB_PATH' => $db]);
        assert_same(0, $code, 'Doctor should pass when PHP and SQLite requirements are met: ' . $stderr);
        foreach (['OK PHP:', 'OK mbstring: yes', 'OK pdo_sqlite: yes', 'OK sqlite3: yes', 'OK data_writable: yes', 'OK database_directory_writable: yes', 'INFO installed: no'] as $expected) {
            assert_true(str_contains($stdout, $expected), 'Doctor output should include: ' . $expected);
        }
    } finally {
        if (is_file($db)) {
            unlink($db);
        }
    }
});

test_case('doctor reports pending migrations before querying newer health schemas', function (): void {
    $db = sys_get_temp_dir() . '/cpe-doctor-pending-migrations-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        [$installCode, , $installError] = run_cli(['install-demo'], ['CPE_DB_PATH' => $db]);
        assert_same(0, $installCode, 'Pending-migration doctor fixture should install: ' . $installError);

        $pdo = new PDO('sqlite:' . $db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("DELETE FROM migrations WHERE migration = '052_api_identity_foundation.sql'");
        $pdo->exec('DROP TABLE api_access_tokens');
        $pdo = null;

        [$code, $stdout, $stderr] = run_cli(['doctor'], ['CPE_DB_PATH' => $db]);
        assert_same(1, $code, 'Doctor should fail while installed migrations are pending');
        assert_true(str_contains($stdout, 'INFO installed: yes'), 'Doctor should recognize the installed pending-migration database');
        assert_true(str_contains($stderr, 'Database migrations are pending. Run `php placement upgrade`.'), 'Doctor should emit fixed upgrade guidance');
        foreach (['no such table', 'SQLSTATE', 'CPE_CLI_COMMAND_FAILED', 'Reference:'] as $rawFailure) {
            assert_true(!str_contains($stdout . $stderr, $rawFailure), 'Doctor exposed a raw health-query failure while migrations were pending: ' . $rawFailure);
        }

        $pdo = new PDO('sqlite:' . $db);
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = '052_api_identity_foundation.sql'")->fetchColumn(), 'Doctor applied a pending migration');
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'api_access_tokens'")->fetchColumn(), 'Doctor recreated pending API storage');
    } finally {
        if (is_file($db)) {
            unlink($db);
        }
    }
});

test_case('system requirements preflight fails closed for installer reuse', function (): void {
    $requirements = new SystemRequirements();
    assert_true($requirements->isReady(), 'Current test runtime should satisfy install requirements');

    $future = new SystemRequirements('999.0.0');
    assert_true(!$future->isReady(), 'Impossible PHP minimum should fail the preflight');
    assert_true(in_array('PHP', $future->failures(), true), 'PHP failure should be explicit');

    try {
        $future->assertReady();
        throw new RuntimeException('System requirement assertion should have failed.');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'System requirements are not met: PHP'), 'Failure message should name PHP');
    }
});

test_case('serve command documents the local PHP server wrapper', function (): void {
    [$helpCode, $helpOut, $helpErr] = run_cli(['serve', '--help']);
    assert_same(0, $helpCode, 'Serve help should exit cleanly: ' . $helpErr);
    assert_true(str_contains($helpOut, 'php placement serve'), 'Serve help should show wrapper command');
    assert_true(str_contains($helpOut, 'php -S 127.0.0.1:8000 -t public public/router.php'), 'Serve help should disclose the clean-path development-router equivalent');

    [$badCode, $badOut, $badErr] = run_cli(['serve', 'not-a-port']);
    assert_same(1, $badCode, 'Invalid serve address should fail');
    assert_true(str_contains($badOut, 'Start the local PHP development server'), 'Invalid serve address should print help');
    assert_true(str_contains($badErr, 'Serve address'), 'Invalid serve address should explain failure');
});

test_case('setup command provides a non-destructive guided-install preflight', function (): void {
    [$helpCode, $helpOut, $helpErr] = run_cli(['setup', '--help']);
    assert_same(0, $helpCode, 'Setup help should exit cleanly: ' . $helpErr);
    assert_true(str_contains($helpOut, 'php placement setup'), 'Setup help should show the one-command path');
    assert_true(str_contains($helpOut, 'does not install silently'), 'Setup help should state the mutation boundary');

    [$checkCode, $checkOut, $checkErr] = run_cli(['setup', '--check']);
    assert_same(0, $checkCode, 'Setup preflight should exit cleanly: ' . $checkErr);
    assert_true(str_contains($checkOut, 'Checking this computer before setup'), 'Setup preflight should explain its check');
    assert_true(str_contains($checkOut, 'Setup check complete'), 'Setup preflight should stop before launching a server');
});

test_case('http smoke cli documents options and validates base url before network access', function (): void {
    [$helpCode, $helpOut, $helpErr] = run_cli(['smoke-http', '--help']);
    assert_same(0, $helpCode, 'HTTP smoke help should exit cleanly: ' . $helpErr);
    assert_true(str_contains($helpOut, 'CPE_SMOKE_BASE_URL'), 'HTTP smoke help should document environment fallback');
    assert_true(str_contains($helpOut, '--restricted-email'), 'HTTP smoke help should document restricted-role access checks');
    assert_true(str_contains($helpOut, 'CPE_SMOKE_RESTRICTED_EMAIL'), 'HTTP smoke help should document restricted-role environment fallback');
    [$badCode, $badOut, $badErr] = run_cli(['smoke-http', '--base-url=not-a-url']);
    assert_true($badCode !== 0, 'Invalid smoke base URL should fail before network access');
    assert_true(str_contains($badErr, 'valid http(s) --base-url'), 'Invalid smoke base URL should explain the expected option');
    assert_same('', $badOut, 'Invalid smoke base URL should not print success output');
    [$badRestrictedCode, $badRestrictedOut, $badRestrictedErr] = run_cli(['smoke-http', '--base-url=http://127.0.0.1:8000', '--restricted-email=not-an-email']);
    assert_true($badRestrictedCode !== 0, 'Invalid restricted smoke email should fail before network access');
    assert_true(str_contains($badRestrictedErr, 'valid --restricted-email'), 'Invalid restricted smoke email should explain the expected option');
    assert_same('', $badRestrictedOut, 'Invalid restricted smoke email should not print success output');
});

test_case('load smoke cli is dependency-light and validates targets before issuing requests', function (): void {
    $probeSource = (string) file_get_contents(dirname(__DIR__) . '/app/Operations/HttpLoadProbe.php');
    assert_true(!str_contains($probeSource, 'curl_close('), 'Load probe should not call deprecated curl_close on PHP 8.5+');
    [$helpCode, $helpOut, $helpErr] = run_cli(['load-smoke', '--help']);
    assert_same(0, $helpCode, 'Load smoke help should exit cleanly: ' . $helpErr);
    assert_true(str_contains($helpOut, 'curl_multi'), 'Load smoke help should document its optional concurrent transport');
    assert_true(str_contains($helpOut, 'PHP streams fallback'), 'Load smoke help should document the dependency-free fallback');
    [$badCode, $badOut, $badErr] = run_cli(['load-smoke', '--base-url=not-a-url']);
    assert_true($badCode !== 0, 'Invalid load target should fail before network access');
    assert_true(str_contains($badErr, 'valid http(s) base URL'), 'Invalid load target should explain the expected option');
    assert_same('', $badOut, 'Invalid load target should not print measurements');
});

test_case('browser QA plan documents cross-browser manual checks without browser dependencies', function (): void {
    $db = sys_get_temp_dir() . '/cpe-browser-qa-plan-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        [$helpCode, $helpOut, $helpErr] = run_cli(['browser-qa-plan', '--help']);
        assert_same(0, $helpCode, 'Browser QA plan help should exit cleanly: ' . $helpErr);
        assert_true(str_contains($helpOut, 'CPE_QA_BASE_URL'), 'Browser QA help should document environment fallback');

        [$badCode, $badOut, $badErr] = run_cli(['browser-qa-plan', '--format=json']);
        assert_true($badCode !== 0, 'Unsupported Browser QA plan format should fail');
        assert_true(str_contains($badErr, 'format must be text or markdown'), 'Unsupported Browser QA format should explain valid formats');
        assert_same('', $badOut, 'Unsupported Browser QA format should not print a partial plan');

        [$installCode, $installOut, $installErr] = run_cli(['install-demo'], ['CPE_DB_PATH' => $db]);
        assert_same(0, $installCode, 'Demo install should prepare browser QA plan database: ' . $installErr);
        assert_true(str_contains($installOut, 'Installed demo app.'), 'Demo install should report success');
        [$seedCode, $seedOut, $seedErr] = run_cli(['seed-large-demo', '80', '10'], ['CPE_DB_PATH' => $db]);
        assert_same(0, $seedCode, 'Large seed should prepare dense browser QA data: ' . $seedErr);
        assert_true(str_contains($seedOut, 'Large synthetic QA data ready.'), 'Large seed should report success');

        [$code, $stdout, $stderr] = run_cli([
            'browser-qa-plan',
            '--base-url=http://127.0.0.1:8010',
            '--format=markdown',
        ], ['CPE_DB_PATH' => $db]);
        assert_same(0, $code, 'Browser QA plan should render against installed app: ' . $stderr);
        foreach ([
            '# Browser QA Plan',
            'OK dense dataset',
            'Safari',
            'Firefox',
            'Board - compact mode',
            'Public placements',
            'Board routes include the configured refresh interval',
            'No browser console errors',
            'php placement smoke-http --base-url=http://127.0.0.1:8010',
            '--restricted-email=atlas@example.test',
        ] as $expected) {
            assert_true(str_contains($stdout, $expected), 'Browser QA plan should include: ' . $expected);
        }
    } finally {
        if (is_file($db)) {
            unlink($db);
        }
    }
});

test_case('transition engine records event and updates status', function (): void {
    $pdo = Database::connection();
    $appId = (int) $pdo->query("SELECT id FROM applications WHERE current_status = 'scheduled' LIMIT 1")->fetchColumn();
    (new PlacementService($pdo))->moveNext($appId, 1, 'admin', 'test move');
    $status = $pdo->query("SELECT current_status FROM applications WHERE id = {$appId}")->fetchColumn();
    assert_same('intransit', $status, 'Application should move to next status');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM events WHERE application_id = {$appId}")->fetchColumn(), 'Event should be recorded');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM workflow_transition_events WHERE application_id = {$appId}")->fetchColumn(), 'Versioned workflow event should be recorded');
    assert_same('intransit', $pdo->query("SELECT current_state_key FROM workflow_instances WHERE application_id = {$appId}")->fetchColumn(), 'Workflow instance should advance with the application');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM domain_event_outbox WHERE aggregate_type = 'placement_application' AND event_name = 'placement.application.transitioned' AND payload_json LIKE '%\"application_id\":{$appId}%'")->fetchColumn(), 'Transition should publish a transactional domain event');
});

test_case('transition permissions reject invalid role', function (): void {
    $pdo = Database::connection();
    $appId = (int) $pdo->query("SELECT id FROM applications WHERE current_status = 'requested' LIMIT 1")->fetchColumn();
    assert_true($appId > 0, 'Expected a requested demo application');
    try {
        (new PlacementService($pdo))->moveNext($appId, null, 'company');
        throw new RuntimeException('Expected permission failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'cannot move'), 'Expected permission message');
    }
});

test_case('stale board moves are rejected before transition', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = $service->saveCandidate(['external_id' => 'STL001', 'name' => 'Stale Candidate'], 1);
    $companyId = $service->saveCompany(['code' => 'STL', 'name' => 'Stale Company'], 1);
    $service->saveApplication($candidateId, $companyId, 'scheduled', null, 1);
    $appId = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$companyId}")->fetchColumn();
    $pdo->exec("UPDATE applications SET current_status = 'intransit', aggregate_version = aggregate_version + 1 WHERE id = {$appId}");
    try {
        $service->moveNext($appId, 1, 'admin', 'stale form submit', 'scheduled');
        throw new RuntimeException('Expected stale board failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'board card is stale'), 'Expected stale board message');
    }
    assert_same('intransit', $pdo->query("SELECT current_status FROM applications WHERE id = {$appId}")->fetchColumn(), 'Stale submit should not move application');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM events WHERE application_id = {$appId}")->fetchColumn(), 'Stale submit should not create event');
});

test_case('idempotency keys prevent repeated live board submissions', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $appId = (int) $pdo->query('SELECT id FROM applications LIMIT 1')->fetchColumn();
    $key = bin2hex(random_bytes(16));

    assert_true($service->consumeIdempotencyKey($key, 1, 'board.move', $appId), 'First idempotency key use should be accepted');
    assert_true(!$service->consumeIdempotencyKey($key, 1, 'board.move', $appId), 'Repeated idempotency key use should be rejected');
    assert_true($service->consumeIdempotencyKey('', 1, 'board.move', $appId), 'Missing key should stay backward-compatible');

    try {
        $service->consumeIdempotencyKey('not-a-key', 1, 'board.move', $appId);
        throw new RuntimeException('Expected bad idempotency key failure.');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'Invalid form submission key'), 'Bad idempotency key should fail clearly');
    }
});

test_case('atomic board requests couple idempotency state results and transition evidence', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = $service->saveCandidate(['external_id' => 'ATM001', 'name' => 'Atomic Candidate'], 1);
    $companyId = $service->saveCompany(['code' => 'ATM', 'name' => 'Atomic Company'], 1);
    $service->saveApplication($candidateId, $companyId, 'idle', null, 1);
    $appId = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$companyId}")->fetchColumn();
    $key = bin2hex(random_bytes(16));

    $pdo->exec(
        "CREATE TRIGGER fail_atomic_board_completion BEFORE UPDATE OF result_json ON idempotency_keys
         WHEN NEW.key = '{$key}'
         BEGIN SELECT RAISE(ABORT, 'forced atomic board rollback'); END"
    );
    try {
        $service->applyBoardMove($appId, 1, 'admin', 'scheduled', '', 'atomic move', 'idle', $key);
        throw new RuntimeException('Expected forced atomic board rollback.');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'forced atomic board rollback'), 'Expected forced completion failure');
    } finally {
        $pdo->exec('DROP TRIGGER fail_atomic_board_completion');
    }
    assert_same('idle', $pdo->query("SELECT current_status FROM applications WHERE id = {$appId}")->fetchColumn(), 'Failed board mutation should roll back application state');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM idempotency_keys WHERE key = " . $pdo->quote($key))->fetchColumn(), 'Failed board mutation should roll back key reservation');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM events WHERE application_id = {$appId}")->fetchColumn(), 'Failed board mutation should roll back event evidence');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM domain_event_outbox WHERE payload_json LIKE '%\"application_id\":{$appId}%'")->fetchColumn(), 'Failed board mutation should roll back outbox evidence');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'transition' AND subject_type = 'application' AND subject_id = {$appId}")->fetchColumn(), 'Failed board mutation should roll back audit evidence');

    $first = $service->applyBoardMove($appId, 1, 'admin', 'scheduled', '', 'atomic move', 'idle', $key);
    $duplicate = $service->applyBoardMove($appId, 1, 'admin', 'scheduled', '', 'atomic move', 'idle', $key);
    assert_same(['duplicate' => false, 'status' => 'scheduled'], $first, 'First atomic board request should apply');
    assert_same(['duplicate' => true, 'status' => 'scheduled'], $duplicate, 'Duplicate atomic board request should return its durable result');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM events WHERE application_id = {$appId} AND from_status = 'idle' AND to_status = 'scheduled'")->fetchColumn(), 'Duplicate should not repeat transition event');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM workflow_transition_events WHERE application_id = {$appId} AND from_state_key = 'idle' AND to_state_key = 'scheduled'")->fetchColumn(), 'Duplicate should not repeat workflow event');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM domain_event_outbox WHERE payload_json LIKE '%\"application_id\":{$appId}%'")->fetchColumn(), 'Duplicate should not repeat outbox event');
    $stored = $pdo->query("SELECT request_hash, result_json FROM idempotency_keys WHERE key = " . $pdo->quote($key))->fetch();
    assert_true(preg_match('/^[a-f0-9]{64}$/', (string) $stored['request_hash']) === 1, 'Atomic request should store a request hash');
    assert_same(['status' => 'scheduled'], json_decode((string) $stored['result_json'], true, 512, JSON_THROW_ON_ERROR), 'Atomic request should store its result');

    try {
        $service->applyBoardMove($appId, 1, 'admin', 'scheduled', '', 'different request', 'idle', $key);
        throw new RuntimeException('Expected form submission key conflict.');
    } catch (UserVisibleException $e) {
        assert_same('FORM_SUBMISSION_KEY_CONFLICT', $e->publicCode(), 'Same key with a different request should conflict');
    }

    $returnKey = bin2hex(random_bytes(16));
    $returned = $service->applyBoardReturnToIdle($appId, 1, 'admin', 'operator_return', 'atomic correction', 'scheduled', $returnKey);
    $returnDuplicate = $service->applyBoardReturnToIdle($appId, 1, 'admin', 'operator_return', 'atomic correction', 'scheduled', $returnKey);
    assert_same(['duplicate' => false, 'status' => 'idle'], $returned, 'Atomic return should apply');
    assert_same(['duplicate' => true, 'status' => 'idle'], $returnDuplicate, 'Atomic return duplicate should reuse the durable result');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM events WHERE application_id = {$appId} AND from_status = 'scheduled' AND to_status = 'idle'")->fetchColumn(), 'Return duplicate should not repeat event evidence');
});

test_case('company users cannot act outside their assigned company scope', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $atlasAppId = (int) $pdo->query("SELECT a.id FROM applications a JOIN companies co ON co.id = a.company_id WHERE co.code = 'ATLAS' LIMIT 1")->fetchColumn();
    $riverAppId = (int) $pdo->query("SELECT a.id FROM applications a JOIN companies co ON co.id = a.company_id WHERE co.code = 'RIVER' LIMIT 1")->fetchColumn();
    $service->assertCanActOnApplication($atlasAppId, ['role' => 'company', 'scope_value' => 'ATLAS']);
    try {
        $service->assertCanActOnApplication($riverAppId, ['role' => 'company', 'scope_value' => 'ATLAS']);
        throw new RuntimeException('Expected company scope failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'outside their assigned company scope'), 'Expected scope message');
    }

    $beforeStatus = (string) $pdo->query("SELECT current_status FROM applications WHERE id = {$riverAppId}")->fetchColumn();
    $key = bin2hex(random_bytes(16));
    try {
        $service->applyBoardMove(
            $riverAppId,
            1,
            'company',
            '',
            '',
            'out-of-scope atomic attempt',
            $beforeStatus,
            $key,
            ['id' => 1, 'role' => 'company', 'scope_type' => 'company', 'scope_value' => 'ATLAS'],
        );
        throw new RuntimeException('Expected atomic company scope failure');
    } catch (UserVisibleException $e) {
        assert_same('PLACEMENT_COMPANY_SCOPE_FORBIDDEN', $e->publicCode(), 'Atomic scope check should fail with the fixed code');
    }
    assert_same($beforeStatus, $pdo->query("SELECT current_status FROM applications WHERE id = {$riverAppId}")->fetchColumn(), 'Atomic scope denial should not mutate application state');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM idempotency_keys WHERE key = " . $pdo->quote($key))->fetchColumn(), 'Atomic scope denial should not retain an idempotency reservation');
});

test_case('candidate detail access follows the requesting role visibility', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = $service->saveCandidate(['external_id' => 'VIS001', 'name' => 'Visibility Candidate'], 1);
    $companyId = $service->saveCompany(['code' => 'VIS', 'name' => 'Visibility Company'], 1);
    $service->saveApplication($candidateId, $companyId, 'idle', null, 1);

    assert_same(
        null,
        $service->candidate($candidateId, ['id' => 4, 'role' => 'mobile', 'active' => 1]),
        'Movement roles should not enumerate candidates with no application visible to that role',
    );
    assert_true(
        $service->candidate($candidateId, ['id' => 2, 'role' => 'control', 'active' => 1]) !== null,
        'Sensitive placement roles should retain full candidate trace access',
    );
});

test_case('sensitive operational pages are hidden from restricted roles', function (): void {
    $pdo = Database::connection();
    $companyUserId = (int) $pdo->query("SELECT id FROM users WHERE role = 'company' AND active = 1 LIMIT 1")->fetchColumn();
    $mobileUserId = (int) $pdo->query("SELECT id FROM users WHERE role = 'mobile' AND active = 1 LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = 1 LIMIT 1")->fetchColumn();
    $advisorUserId = Auth::createUser('Route Gate Advisor', 'route-gate-advisor@example.test', 'password123', 'advisor');
    $previousSessionUser = $_SESSION['user_id'] ?? null;
    try {
        $_SESSION['user_id'] = $companyUserId;
        try {
            Auth::requireRole(['admin'], 'Only administrators can open Admin.');
            throw new RuntimeException('Expected company role to be rejected from admin-only page.');
        } catch (RuntimeException $e) {
            assert_true($e instanceof AuthorizationException, 'Role gates should use the authorization exception type');
            assert_true(str_contains($e->getMessage(), 'Only administrators'), 'Role gate should explain admin-only denial');
        }
        $companyNav = render_layout_for_test(['title' => 'Company Nav', 'content' => '']);
        foreach (['?r=records', '?r=reports', '?r=import', '?r=preferences', '?r=wanted', '?r=admin', '?r=integrations', '?r=system'] as $forbiddenLink) {
            assert_true(!str_contains($companyNav, $forbiddenLink), 'Company nav should hide ' . $forbiddenLink);
        }
        assert_true(str_contains($companyNav, '?r=notifications'), 'Company nav should keep notifications');
        assert_true(str_contains($companyNav, '?r=public'), 'Company nav should keep public page link');

        $_SESSION['user_id'] = $mobileUserId;
        $mobileNav = render_layout_for_test(['title' => 'Mobile Nav', 'content' => '']);
        assert_true(str_contains($mobileNav, '?r=wanted'), 'Mobile nav should expose wanted alerts');
        assert_true(!str_contains($mobileNav, '?r=admin'), 'Mobile nav should hide Admin');
        assert_true(!str_contains($mobileNav, '?r=records'), 'Mobile nav should hide Records');

        $_SESSION['user_id'] = $advisorUserId;
        foreach ([
            fn (): mixed => (new \App\Controllers\BoardController())->index(),
            fn (): mixed => (new \App\Controllers\CandidateController())->show(),
            fn (): mixed => (new \App\Controllers\NotificationController())->show(),
            fn (): mixed => (new \App\Controllers\PublicController())->student(),
        ] as $openRestrictedRoute) {
            try {
                $openRestrictedRoute();
                throw new RuntimeException('Expected route capability denial.');
            } catch (AuthorizationException) {
            }
        }

        $_SESSION['user_id'] = $adminUserId;
        $adminNav = render_layout_for_test(['title' => 'Admin Nav', 'content' => '']);
        foreach (['?r=records', '?r=reports', '?r=import', '?r=preferences', '?r=wanted', '?r=admin', '?r=system'] as $expectedLink) {
            assert_true(str_contains($adminNav, $expectedLink), 'Admin nav should include ' . $expectedLink);
        }
    } finally {
        if ($previousSessionUser === null) {
            unset($_SESSION['user_id']);
        } else {
            $_SESSION['user_id'] = $previousSessionUser;
        }
    }
});

test_case('synthetic placement-day scenario reaches placed through every default transition', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = $service->saveCandidate([
        'external_id' => 'SCN001',
        'name' => 'Scenario Candidate',
        'program' => 'MBA',
        'current_location' => 'CP',
    ], 1);
    $companyId = $service->saveCompany([
        'code' => 'SCN',
        'name' => 'Scenario Company',
        'slot' => 'Scenario Slot',
    ], 1);
    $service->saveApplication($candidateId, $companyId, 'idle', null, 1);
    $appId = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$companyId}")->fetchColumn();

    $steps = [
        ['control', 'scheduled'],
        ['mobile', 'intransit'],
        ['floor', 'arrived'],
        ['company', 'requested'],
        ['control', 'sendin'],
        ['company', 'inside'],
        ['company', 'exit'],
        ['company', 'requestaway'],
        ['placement', 'sendaway'],
        ['company', 'sent'],
        ['placement', 'placed'],
    ];
    foreach ($steps as [$role, $expectedStatus]) {
        $status = $service->moveNext($appId, 1, $role, 'scenario step');
        assert_same($expectedStatus, $status, 'Unexpected scenario transition');
    }
    assert_same('placed', $pdo->query("SELECT current_status FROM applications WHERE id = {$appId}")->fetchColumn(), 'Scenario should end placed');
    assert_same($companyId, (int) $pdo->query("SELECT placed_company_id FROM candidates WHERE id = {$candidateId}")->fetchColumn(), 'Candidate should be placed at scenario company');
    assert_same(count($steps), (int) $pdo->query("SELECT COUNT(*) FROM events WHERE application_id = {$appId}")->fetchColumn(), 'Every scenario step should create an event');
});

test_case('placement report summarizes counters companies programs and locations', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = $service->saveCandidate(['external_id' => 'RPT001', 'name' => 'Report Candidate', 'program' => 'Report Program'], 1);
    $companyId = $service->saveCompany(['code' => 'RPT', 'name' => 'Report Company'], 1);
    $service->saveApplication($candidateId, $companyId, 'sent', null, 1);
    $appId = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$companyId}")->fetchColumn();
    $service->transition($appId, 'placed', 1, 'placement', 'report smoke');

    $summary = $service->placementSummary();
    assert_true($summary['totals']['placed_candidates'] >= 1, 'Report should count placed candidates');

    $companyRows = array_values(array_filter($summary['placementsByCompany'], fn (array $row): bool => $row['code'] === 'RPT'));
    assert_same(1, count($companyRows), 'Report should include placed company row');
    assert_same(1, $companyRows[0]['placed_count'], 'Report should count company placements');

    $programRows = array_values(array_filter($summary['candidatesByProgram'], fn (array $row): bool => $row['program'] === 'Report Program'));
    assert_same(1, count($programRows), 'Report should include program row');
    assert_same(1, $programRows[0]['candidate_count'], 'Report should count program candidates');
    assert_same(1, $programRows[0]['placed_count'], 'Report should count program placements');

    $locationRows = array_values(array_filter($summary['candidatesByLocation'], fn (array $row): bool => $row['current_location'] === 'RPT'));
    assert_same(1, count($locationRows), 'Report should include placement location row');
    assert_same(1, $locationRows[0]['candidate_count'], 'Report should count candidates at placement company location');

    [$code, $stdout, $stderr] = run_cli(['placement-report'], ['CPE_DB_PATH' => Database::path()]);
    assert_same(0, $code, 'Placement report CLI should run: ' . $stderr);
    assert_true(str_contains($stdout, 'placements_by_company,RPT') && str_contains($stdout, 'Report Company') && str_contains($stdout, ',1,1,'), 'CLI report should include company placement count');
    assert_true(str_contains($stdout, 'candidates_by_program') && str_contains($stdout, 'Report Program'), 'CLI report should include program placement count');
});

test_case('sent handoff moves next scheduled company into transit', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = $service->saveCandidate(['external_id' => 'HND001', 'name' => 'Handoff Candidate'], 1);
    $firstCompany = $service->saveCompany(['code' => 'HNA', 'name' => 'Handoff A'], 1);
    $secondCompany = $service->saveCompany(['code' => 'HNB', 'name' => 'Handoff B'], 1);
    $service->saveApplication($candidateId, $firstCompany, 'sendaway', null, 1);
    $service->saveApplication($candidateId, $secondCompany, 'scheduled', null, 1);
    $firstApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$firstCompany}")->fetchColumn();
    $secondApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$secondCompany}")->fetchColumn();

    $service->transition($firstApp, 'sent', 1, 'company', 'synthetic handoff');

    $first = $pdo->query("SELECT current_status, next_company_id FROM applications WHERE id = {$firstApp}")->fetch();
    $second = $pdo->query("SELECT current_status, previous_company_id FROM applications WHERE id = {$secondApp}")->fetch();
    $candidateLocation = $pdo->query("SELECT current_location FROM candidates WHERE id = {$candidateId}")->fetchColumn();
    assert_same('sent', $first['current_status'], 'Original company should be sent');
    assert_same($secondCompany, (int) $first['next_company_id'], 'Original company should record next company');
    assert_same('intransit', $second['current_status'], 'Next scheduled company should move into transit');
    assert_same($firstCompany, (int) $second['previous_company_id'], 'Next company should remember previous company');
    assert_same('HNB', $candidateLocation, 'Candidate location should move toward the next company');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM events WHERE application_id = {$secondApp} AND from_status = 'scheduled' AND to_status = 'intransit' AND note LIKE 'Auto-started after send-away%'")->fetchColumn(), 'Auto-handoff should write an event for the next company');
    $handoffApps = collect_apps($service->dashboard(['role' => 'admin'], ['q' => 'Handoff Candidate']));
    $firstRoute = array_values(array_filter($handoffApps, fn (array $app): bool => $app['id'] === $firstApp))[0]['route_summary'] ?? '';
    $secondRoute = array_values(array_filter($handoffApps, fn (array $app): bool => $app['id'] === $secondApp))[0]['route_summary'] ?? '';
    assert_same('HNA -> HNB', $firstRoute, 'Sent company card should show outgoing route');
    assert_same('HNA -> HNB', $secondRoute, 'Next company in-transit card should show incoming route');
    $candidateTrace = $service->candidate($candidateId);
    $traceRoutes = array_column($candidateTrace['applications'] ?? [], 'route_summary', 'company_code');
    assert_same('HNA -> HNB', $traceRoutes['HNA'] ?? '', 'Candidate trace should show outgoing handoff route');
    assert_same('HNA -> HNB', $traceRoutes['HNB'] ?? '', 'Candidate trace should show incoming handoff route');
    $conflictRows = collect_apps($service->dashboard(['role' => 'admin'], ['flag' => 'conflict', 'q' => 'Handoff Candidate']));
    assert_same(0, count(array_filter($conflictRows, fn (array $app): bool => $app['external_id'] === 'HND001')), 'Completed handoff should not create a false active-company conflict');
    $readiness = (new ReadinessService($pdo))->snapshot();
    assert_same(0, count(array_filter($readiness['activeConflicts']['rows'], fn (array $row): bool => $row['external_id'] === 'HND001')), 'Readiness should ignore sent rows that have a next-company handoff');
});

test_case('placement clears competing active applications unless upgrades are allowed', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = $service->saveCandidate(['external_id' => 'PLC001', 'name' => 'Placement Cleanup Candidate'], 1);
    $firstCompany = $service->saveCompany(['code' => 'PCA', 'name' => 'Placement Cleanup A'], 1);
    $secondCompany = $service->saveCompany(['code' => 'PCB', 'name' => 'Placement Cleanup B'], 1);
    $service->saveApplication($candidateId, $firstCompany, 'sent', null, 1);
    $service->saveApplication($candidateId, $secondCompany, 'sent', null, 1);
    $firstApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$firstCompany}")->fetchColumn();
    $secondApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$secondCompany}")->fetchColumn();
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('allow_offer_upgrade', '0') ON CONFLICT(key) DO UPDATE SET value = excluded.value");

    $service->moveNext($firstApp, 1, 'placement', 'cleanup test');
    assert_same('placed', $pdo->query("SELECT current_status FROM applications WHERE id = {$firstApp}")->fetchColumn(), 'Chosen application should be placed');
    assert_same('idle', $pdo->query("SELECT current_status FROM applications WHERE id = {$secondApp}")->fetchColumn(), 'Competing active application should be cleared');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM events WHERE application_id = {$secondApp} AND from_status = 'sent' AND to_status = 'idle' AND note LIKE 'Auto-cleared after placement at PCA.%'")->fetchColumn(), 'Cleanup should record an event on the competing application');
    assert_true((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'placement.cleanup_competing_applications' AND subject_id = {$candidateId}")->fetchColumn() >= 1, 'Cleanup should write an audit summary');
    $conflictRows = collect_apps($service->dashboard(['role' => 'admin'], ['flag' => 'conflict']));
    assert_same(0, count(array_filter($conflictRows, fn (array $app): bool => $app['external_id'] === 'PLC001')), 'Placed candidate should no longer appear in active conflict filter');

    $upgradeCandidateId = $service->saveCandidate(['external_id' => 'PLC002', 'name' => 'Upgrade Candidate'], 1);
    $upgradeFirst = $service->saveCompany(['code' => 'PCU', 'name' => 'Upgrade A'], 1);
    $upgradeSecond = $service->saveCompany(['code' => 'PCV', 'name' => 'Upgrade B'], 1);
    $service->saveApplication($upgradeCandidateId, $upgradeFirst, 'sent', null, 1);
    $service->saveApplication($upgradeCandidateId, $upgradeSecond, 'sent', null, 1);
    $upgradeFirstApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$upgradeCandidateId} AND company_id = {$upgradeFirst}")->fetchColumn();
    $upgradeSecondApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$upgradeCandidateId} AND company_id = {$upgradeSecond}")->fetchColumn();
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('allow_offer_upgrade', '1') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $service->moveNext($upgradeFirstApp, 1, 'placement', 'upgrade policy test');
    assert_same('sent', $pdo->query("SELECT current_status FROM applications WHERE id = {$upgradeSecondApp}")->fetchColumn(), 'Upgrade policy should preserve competing active application');
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('allow_offer_upgrade', '0') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
});

test_case('csv imports upsert data and create shortlist', function (): void {
    $pdo = Database::connection();
    $importer = new CsvImporter($pdo);
    $candidateCsv = implode("\n", [
        'external_id,name,program,tags,current_location,accommodation_notes,custom_fields_json',
        'T001,Test Candidate,MBA,"tag-one,tag-two",CP,Ground-floor room,"{""branch"":""Finance"",""cgpa"":9.1}"',
        '',
    ]);
    $companyCsv = implode("\n", [
        'code,name,slot,offer_tier,process_type,room,tracker_name,max_active,deadline_day,deadline_at,process_notes,tags,custom_fields_json',
        'TEST,Test Company,Day 1,dream,Interview,Room T,Tracker One,2,2,17:00,Bring resumes,"consulting,priority","{""mode"":""hybrid"",""stipend"":50000}"',
        '',
    ]);
    assert_same(1, $importer->candidates($candidateCsv));
    assert_same(1, $importer->companies($companyCsv));
    assert_same(2, $importer->companyRounds("company_code,sequence,label,round_type,room,duration_minutes,instructions\nTEST,1,Screen,test,Lab T,30,Bring laptop\nTEST,2,Panel,interview,Room T,45,Two interviewers\n"));
    assert_same(2, $importer->roundSchedules("company_code,round_sequence,round_label,sequence,room,schedule_day,starts_at,ends_at,capacity,schedule_status,notes\nTEST,1,Screen,1,Lab T,1,09:00,09:30,4,active,Run written screen\nTEST,2,Panel,1,Room T,2,10:00,10:45,2,paused,Panel queue\n"));
    assert_same(2, $importer->roundPanelists("company_code,round_sequence,round_label,sequence,name,role,affiliation,contact,availability_status,notes\nTEST,1,Screen,1,Panel One,Lead,Test Co,,active,Runs screen\nTEST,2,Panel,1,Panel Two,Interviewer,Test Co,,break,Final panel\n"));
    assert_same(1, $importer->shortlists("candidate_external_id,company_code,status\nT001,TEST,scheduled\n"));
    assert_same(1, $importer->slotAssignments("candidate_external_id,company_code,round_sequence,round_label,schedule_sequence,room,schedule_day,starts_at,assignment_sequence,assignment_status,notes\nT001,TEST,1,Screen,1,Lab T,1,09:00,1,assigned,Seat 1\n"));
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.external_id = 'T001'")->fetchColumn());
    $company = $pdo->query("SELECT process_type, room, tracker_name, max_active, deadline_day, deadline_at, process_notes, tags, custom_fields_json FROM companies WHERE code = 'TEST'")->fetch();
    assert_same('Interview', $company['process_type'], 'Company process type should import');
    assert_same('Room T', $company['room'], 'Company room should import');
    assert_same('Tracker One', $company['tracker_name'], 'Company tracker should import');
    assert_same(2, (int) $company['max_active'], 'Company active cap should import');
    assert_same('2', $company['deadline_day'], 'Company deadline day should import');
    assert_same('17:00', $company['deadline_at'], 'Company deadline time should import');
    assert_same('Bring resumes', $company['process_notes'], 'Company process notes should import');
    assert_same('consulting,priority', $company['tags'], 'Company tags should import');
    assert_same('{"mode":"hybrid","stipend":50000}', $company['custom_fields_json'], 'Company custom fields should import as normalized JSON');
    $candidateImport = $pdo->query("SELECT tags, accommodation_notes, custom_fields_json FROM candidates WHERE external_id = 'T001'")->fetch();
    assert_same('tag-one,tag-two', $candidateImport['tags'], 'Candidate tags should import');
    assert_same('Ground-floor room', $candidateImport['accommodation_notes'], 'Candidate accommodation notes should import');
    assert_same('{"branch":"Finance","cgpa":9.1}', $candidateImport['custom_fields_json'], 'Candidate custom fields should import as normalized JSON');
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM company_rounds cr JOIN companies co ON co.id = cr.company_id WHERE co.code = 'TEST'")->fetchColumn(), 'Company rounds should import');
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM round_schedules rs JOIN company_rounds cr ON cr.id = rs.round_id JOIN companies co ON co.id = cr.company_id WHERE co.code = 'TEST'")->fetchColumn(), 'Round schedules should import');
    assert_same('2', $pdo->query("SELECT rs.schedule_day FROM round_schedules rs JOIN company_rounds cr ON cr.id = rs.round_id JOIN companies co ON co.id = cr.company_id WHERE co.code = 'TEST' AND cr.sequence = 2")->fetchColumn(), 'Round schedules should import schedule day');
    assert_same('paused', $pdo->query("SELECT rs.schedule_status FROM round_schedules rs JOIN company_rounds cr ON cr.id = rs.round_id JOIN companies co ON co.id = cr.company_id WHERE co.code = 'TEST' AND cr.sequence = 2")->fetchColumn(), 'Round schedules should import panel break status');
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM round_panelists rp JOIN company_rounds cr ON cr.id = rp.round_id JOIN companies co ON co.id = cr.company_id WHERE co.code = 'TEST'")->fetchColumn(), 'Round panelists should import');
    assert_same('break', $pdo->query("SELECT rp.availability_status FROM round_panelists rp JOIN company_rounds cr ON cr.id = rp.round_id JOIN companies co ON co.id = cr.company_id WHERE co.code = 'TEST' AND cr.sequence = 2")->fetchColumn(), 'Round panelists should import availability status');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM application_slot_assignments asa JOIN applications a ON a.id = asa.application_id JOIN candidates c ON c.id = a.candidate_id WHERE c.external_id = 'T001'")->fetchColumn(), 'Slot assignments should import');
});

test_case('candidate unavailable windows import and block slot suggestions', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $importer = new CsvImporter($pdo);
    $candidateId = $service->saveCandidate(['external_id' => 'UNAV001', 'name' => 'Unavailable Candidate'], 1);
    $companyId = $service->saveCompany(['code' => 'UNAV', 'name' => 'Unavailable Test Company'], 1);
    $roundId = $service->saveCompanyRound([
        'company_id' => $companyId,
        'sequence' => '1',
        'label' => 'Screen',
        'round_type' => 'interview',
        'room' => 'Room U',
        'duration_minutes' => '30',
    ], 1);
    $service->saveRoundSchedule([
        'round_id' => $roundId,
        'sequence' => '1',
        'room' => 'Room U1',
        'schedule_day' => '1',
        'starts_at' => '09:00',
        'ends_at' => '09:30',
        'capacity' => '1',
        'schedule_status' => 'active',
    ], 1);
    $service->saveRoundSchedule([
        'round_id' => $roundId,
        'sequence' => '2',
        'room' => 'Room U2',
        'schedule_day' => '1',
        'starts_at' => '09:40',
        'ends_at' => '10:10',
        'capacity' => '1',
        'schedule_status' => 'active',
    ], 1);
    $service->saveApplication($candidateId, $companyId, 'scheduled', null, 1);

    $preview = $importer->preview('unavailability', "Student ID,Label,Day,Start Time,End Time,Notes\nUNAV001,Exam block,1,09:00,09:30,Entrance exam\n");
    assert_true($preview['valid'], 'Unavailable-window preview should accept common aliases');
    assert_same(1, $preview['creates'], 'Unavailable-window preview should count create');
    assert_same(1, $importer->candidateUnavailability("Student ID,Label,Day,Start Time,End Time,Notes\nUNAV001,Exam block,1,09:00,09:30,Entrance exam\n"));
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM candidate_unavailability_windows cuw JOIN candidates c ON c.id = cuw.candidate_id WHERE c.external_id = 'UNAV001'")->fetchColumn(), 'Unavailable window should import');

    $suggestions = $service->slotAssignmentSuggestions('UNAV');
    $row = current(array_values(array_filter($suggestions, fn (array $row): bool => $row['candidate_external_id'] === 'UNAV001')));
    assert_true(is_array($row), 'Planner should emit a row for unavailable candidate');
    assert_same('09:40', $row['starts_at'], 'Planner should skip blocked first slot');
    assert_same('Room U2', $row['room'], 'Planner should choose the later safe room');

    $blockedCompanyId = $service->saveCompany(['code' => 'BLK', 'name' => 'Blocked Company'], 1);
    $blockedRoundId = $service->saveCompanyRound([
        'company_id' => $blockedCompanyId,
        'sequence' => '1',
        'label' => 'Only Slot',
        'round_type' => 'interview',
    ], 1);
    $service->saveRoundSchedule([
        'round_id' => $blockedRoundId,
        'sequence' => '1',
        'room' => 'Room Block',
        'schedule_day' => '1',
        'starts_at' => '09:00',
        'ends_at' => '09:30',
        'capacity' => '1',
        'schedule_status' => 'active',
    ], 1);
    $service->saveApplication($candidateId, $blockedCompanyId, 'scheduled', null, 1);
    $blocked = $service->slotAssignmentSuggestions('BLK');
    $blockedRow = current(array_values(array_filter($blocked, fn (array $row): bool => $row['candidate_external_id'] === 'UNAV001')));
    assert_true(is_array($blockedRow), 'Planner should emit a blocked row');
    assert_same('', $blockedRow['starts_at'], 'Blocked row should not suggest a schedule');
    assert_true(str_contains($blockedRow['reason'], "candidate's existing or suggested slots"), 'Blocked reason should explain candidate conflict');
    assert_true(str_contains($blockedRow['reason'], 'Exam block'), 'Blocked reason should include unavailable-window label');

    $bad = $importer->preview('unavailability', "candidate_external_id,starts_at,ends_at\nUNAV001,99:00,10:00\n");
    assert_true(!$bad['valid'], 'Bad unavailable-window time should fail preview');
    assert_true(str_contains(implode(' ', $bad['errors']), 'starts_at must use HH:MM'), 'Bad unavailable-window time should be explained');

    $badRange = $importer->preview('unavailability', "candidate_external_id,starts_at,ends_at\nUNAV001,10:00,10:00\n");
    assert_true(!$badRange['valid'], 'Zero-length unavailable window should fail preview');
    assert_true(str_contains(implode(' ', $badRange['errors']), 'ends_at must be after starts_at'), 'Zero-length unavailable window should be explained');
});

test_case('csv imports accept common college header aliases', function (): void {
    $pdo = Database::connection();
    $importer = new CsvImporter($pdo);
    assert_same(1, $importer->candidates("Student ID,Full Name,Branch,Cohort,Location,Accommodation,Opt Out\nAL001,Alias Candidate,Computer Science,Women-in-tech,Desk A,Wheelchair access,no\n"));
    assert_same(1, $importer->companies("Company Code,Company Name,Day Slot,Tier,Category,Process,Venue,Tracker,Active Cap,Finish Day,Finish By,Company Notes\nALCO,Alias Recruiter,Day 2,core,SaaS,Technical,Room Alias,Alias Tracker,3,2,18:00,Alias process\n"));
    assert_same(1, $importer->companyRounds("Company Code,Order,Round Name,Type,Venue,Duration,Instructions\nALCO,1,Technical Screen,technical,Lab Alias,30,Bring laptop\n"));
    assert_same(1, $importer->roundSchedules("Company Code,Round No,Round Name,Order,Venue,Day,Start Time,End Time,Seats,Schedule Status,Notes\nALCO,1,Technical Screen,1,Lab Alias,2,11:00,11:30,2,active,Alias schedule\n"));
    assert_same(1, $importer->roundPanelists("Company Code,Round No,Round Name,Order,Panelist Name,Role,Organisation,Phone,Availability,Notes\nALCO,1,Technical Screen,1,Panel Alias,Lead,Alias Recruiter,555,active,Alias panel\n"));
    assert_same(1, $importer->shortlists("Roll Number,Employer Code,Status,List Rank\nAL001,ALCO,scheduled,1\n", array_keys((new Workflow())->statuses())));
    assert_same(1, $importer->slotAssignments("Student ID,Employer Code,Round No,Round Name,Schedule Sequence,Venue,Day,Start Time,Assignment Order,Assignment Status,Notes\nAL001,ALCO,1,Technical Screen,1,Lab Alias,2,11:00,1,assigned,Alias slot\n"));

    $candidate = $pdo->query("SELECT program, tags, current_location, accommodation_notes, opted_out FROM candidates WHERE external_id = 'AL001'")->fetch();
    assert_same('Computer Science', $candidate['program'], 'Alias candidate branch should map to program');
    assert_same('Women-in-tech', $candidate['tags'], 'Alias candidate cohort should map to tags');
    assert_same('Desk A', $candidate['current_location'], 'Alias candidate location should map to current location');
    assert_same('Wheelchair access', $candidate['accommodation_notes'], 'Alias accommodation should map to accommodation notes');
    assert_same(0, (int) $candidate['opted_out'], 'Alias opt-out should parse false-like value');

    $company = $pdo->query("SELECT name, slot, offer_tier, tags, process_type, room, tracker_name, max_active, deadline_day, deadline_at, process_notes FROM companies WHERE code = 'ALCO'")->fetch();
    assert_same('Alias Recruiter', $company['name'], 'Alias company name should map to name');
    assert_same('Day 2', $company['slot'], 'Alias day slot should map to slot');
    assert_same('core', $company['offer_tier'], 'Alias tier should map to offer tier');
    assert_same('SaaS', $company['tags'], 'Alias company category should map to tags');
    assert_same('Technical', $company['process_type'], 'Alias process should map to process type');
    assert_same('Room Alias', $company['room'], 'Alias venue should map to room');
    assert_same('Alias Tracker', $company['tracker_name'], 'Alias tracker should map to tracker name');
    assert_same(3, (int) $company['max_active'], 'Alias active cap should map to max active');
    assert_same('2', $company['deadline_day'], 'Alias finish day should map to deadline day');
    assert_same('18:00', $company['deadline_at'], 'Alias finish by should map to deadline time');
    assert_same('Alias process', $company['process_notes'], 'Alias company notes should map to process notes');

    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM company_rounds cr JOIN companies co ON co.id = cr.company_id WHERE co.code = 'ALCO' AND cr.label = 'Technical Screen'")->fetchColumn(), 'Alias round import should create round');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM round_schedules rs JOIN company_rounds cr ON cr.id = rs.round_id JOIN companies co ON co.id = cr.company_id WHERE co.code = 'ALCO' AND rs.starts_at = '11:00'")->fetchColumn(), 'Alias schedule import should create schedule');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM round_panelists rp JOIN company_rounds cr ON cr.id = rp.round_id JOIN companies co ON co.id = cr.company_id WHERE co.code = 'ALCO' AND rp.name = 'Panel Alias'")->fetchColumn(), 'Alias panelist import should create panelist');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM application_slot_assignments asa JOIN applications a ON a.id = asa.application_id JOIN candidates c ON c.id = a.candidate_id WHERE c.external_id = 'AL001'")->fetchColumn(), 'Alias slot assignment import should create assignment');

    try {
        $importer->preview('candidates', "external_id,Student ID,name\nDUP001,DUP002,Duplicate Candidate\n");
        throw new RuntimeException('Expected duplicate normalized header failure.');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'duplicate column after header normalization'), 'Duplicate alias header should fail clearly without reflecting input');
    }
});

test_case('csv imports accept configured local header aliases', function (): void {
    $pdo = Database::connection();
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $aliases = json_encode([
        'external_id' => ['Campus UID'],
        'candidate_external_id' => ['Campus UID'],
        'name' => ['Legal Student Name', 'Legal Recruiter Name'],
        'program' => ['Local Program'],
        'code' => ['Recruiter Short Code'],
        'company_code' => ['Recruiter Short Code'],
        'status' => ['Process State'],
    ], JSON_UNESCAPED_SLASHES);
    try {
        $set->execute(['import_header_aliases_json', $aliases ?: '']);
        $importer = new CsvImporter($pdo);
        assert_same(1, $importer->candidates("Campus UID,Legal Student Name,Local Program\nCUST001,Custom Alias Candidate,Design\n"));
        assert_same(1, $importer->companies("Recruiter Short Code,Legal Recruiter Name\nCUSTCO,Custom Alias Recruiter\n"));
        assert_same(1, $importer->shortlists("Campus UID,Recruiter Short Code,Process State\nCUST001,CUSTCO,scheduled\n", array_keys((new Workflow())->statuses())));

        $candidate = $pdo->query("SELECT name, program FROM candidates WHERE external_id = 'CUST001'")->fetch();
        assert_same('Custom Alias Candidate', $candidate['name'], 'Configured alias should map local candidate name header');
        assert_same('Design', $candidate['program'], 'Configured alias should map local program header');
        $company = $pdo->query("SELECT name FROM companies WHERE code = 'CUSTCO'")->fetch();
        assert_same('Custom Alias Recruiter', $company['name'], 'Configured alias should map local company name header');
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM applications a JOIN candidates c ON c.id = a.candidate_id JOIN companies co ON co.id = a.company_id WHERE c.external_id = 'CUST001' AND co.code = 'CUSTCO' AND a.current_status = 'scheduled'")->fetchColumn(), 'Configured aliases should support shortlist imports');
    } finally {
        $set->execute(['import_header_aliases_json', '']);
    }
});

test_case('csv preview validates without mutating data', function (): void {
    $pdo = Database::connection();
    $importer = new CsvImporter($pdo);
    $before = (int) $pdo->query("SELECT COUNT(*) FROM candidates WHERE external_id = 'PV001'")->fetchColumn();
    $report = $importer->preview('candidates', "external_id,name,program\nPV001,Preview Candidate,MBA\n");
    assert_true($report['valid'], 'Candidate preview should be valid');
    assert_same(1, $report['rows'], 'Preview should count rows');
    assert_same(1, $report['creates'], 'Preview should count candidate creates');
    assert_same($before, (int) $pdo->query("SELECT COUNT(*) FROM candidates WHERE external_id = 'PV001'")->fetchColumn(), 'Preview should not insert candidate');

    $bad = $importer->preview('shortlists', "candidate_external_id,company_code,status\nPV001,NOCO,notastatus\n", array_keys((new Workflow())->statuses()));
    assert_true(!$bad['valid'], 'Bad shortlist preview should fail');
    $errors = implode("\n", $bad['errors']);
    assert_true(str_contains($errors, 'Missing candidate: PV001'), 'Preview should report missing candidate');
    assert_true(str_contains($errors, 'Missing company: NOCO'), 'Preview should report missing company');
    assert_true(str_contains($errors, 'Unknown workflow status: notastatus'), 'Preview should report bad status');

    $badSchedule = $importer->preview('schedules', "company_code,round_sequence,round_label,sequence,room\nNOCO,1,Screen,1,Room X\n");
    assert_true(!$badSchedule['valid'], 'Bad schedule preview should fail');
    assert_true(str_contains(implode("\n", $badSchedule['errors']), 'Missing round: NOCO 1. Screen'), 'Preview should report missing schedule round');

    $badPanel = $importer->preview('panelists', "company_code,round_sequence,round_label,sequence,name\nNOCO,1,Screen,1,Panel One\n");
    assert_true(!$badPanel['valid'], 'Bad panelist preview should fail');
    assert_true(str_contains(implode("\n", $badPanel['errors']), 'Missing round: NOCO 1. Screen'), 'Preview should report missing round');

    $badPanelStatus = $importer->preview('panelists', "company_code,round_sequence,round_label,sequence,name,availability_status\nATLAS,1,Case Discussion,1,Panel One,busy\n");
    assert_true(!$badPanelStatus['valid'], 'Invalid panelist availability should fail preview');
    assert_true(str_contains(implode(' ', $badPanelStatus['errors']), 'availability_status'), 'Invalid panelist availability should be explained');

    $badSchedule = $importer->preview('schedules', "company_code,round_sequence,round_label,sequence,room,starts_at,ends_at,capacity,schedule_status\nPREV,1,Screen,1,Room X,09:00,09:30,1,unknown\n");
    assert_true(!$badSchedule['valid'], 'Invalid schedule status should fail preview');
    assert_true(str_contains(implode(' ', $badSchedule['errors']), 'schedule_status'), 'Invalid schedule status should be explained');
    $badAssignment = $importer->preview('assignments', "candidate_external_id,company_code,round_sequence,round_label,schedule_sequence,room,starts_at\nPV001,NOCO,1,Screen,1,Room X,09:00\n");
    assert_true(!$badAssignment['valid'], 'Bad assignment preview should fail');
    $assignmentErrors = implode("\n", $badAssignment['errors']);
    assert_true(str_contains($assignmentErrors, 'Missing application: PV001 / NOCO'), 'Preview should report missing application');
    assert_true(str_contains($assignmentErrors, 'Missing schedule: NOCO 1. Screen / Room X 09:00'), 'Preview should report missing schedule');
});

test_case('csv imports reject oversized pasted input before mutation', function (): void {
    $pdo = Database::connection();
    $importer = new CsvImporter($pdo);
    $originalMaxBytes = getenv('CPE_IMPORT_MAX_BYTES');
    $originalMaxRows = getenv('CPE_IMPORT_MAX_ROWS');
    try {
        putenv('CPE_IMPORT_MAX_ROWS=1');
        try {
            $importer->preview('candidates', "external_id,name\nLIMIT001,Limit One\nLIMIT002,Limit Two\n");
            throw new RuntimeException('Expected CSV row limit failure');
        } catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), 'too many data rows'), 'CSV row limit should fail clearly');
        }

        putenv('CPE_IMPORT_MAX_ROWS');
        putenv('CPE_IMPORT_MAX_BYTES=64');
        try {
            $importer->preview('candidates', "external_id,name\nLIMIT003," . str_repeat('A', 80) . "\n");
            throw new RuntimeException('Expected CSV byte limit failure');
        } catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), 'too large'), 'CSV byte limit should fail clearly');
        }

        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM candidates WHERE external_id LIKE 'LIMIT%'")->fetchColumn(), 'Rejected oversized CSV should not mutate data');
    } finally {
        if ($originalMaxRows === false) {
            putenv('CPE_IMPORT_MAX_ROWS');
        } else {
            putenv('CPE_IMPORT_MAX_ROWS=' . $originalMaxRows);
        }
        if ($originalMaxBytes === false) {
            putenv('CPE_IMPORT_MAX_BYTES');
        } else {
            putenv('CPE_IMPORT_MAX_BYTES=' . $originalMaxBytes);
        }
    }
});

test_case('shortlist import validates all rows before writing', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $importer = new CsvImporter($pdo);
    $candidateId = $service->saveCandidate(['external_id' => 'TX001', 'name' => 'Transactional Candidate'], 1);
    $companyId = $service->saveCompany(['code' => 'TXCO', 'name' => 'Transactional Company'], 1);
    try {
        $importer->shortlists("candidate_external_id,company_code,status\nTX001,TXCO,scheduled\nTX001,MISSING,scheduled\n", array_keys((new Workflow())->statuses()));
        throw new RuntimeException('Expected import validation failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'Missing companies.code: MISSING'), 'Expected missing company message');
    }
    assert_true(!$service->applicationExists($candidateId, $companyId), 'Valid earlier row should not be written when later row fails');
});

test_case('snapshot export writes portable csv files without password hashes', function (): void {
    $pdo = Database::connection();
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'admin@test.local'")->fetchColumn();
    (new PlacementService($pdo))->saveBoardPreference($adminId, ['flag' => 'wanted', 'compact' => '1']);
    $originalCandidateName = (string) $pdo->query("SELECT name FROM candidates WHERE external_id = 'C001'")->fetchColumn();
    $formulaName = '=HYPERLINK("https://example.invalid","Candidate")';
    $nameUpdate = $pdo->prepare("UPDATE candidates SET name = ? WHERE external_id = 'C001'");
    $nameUpdate->execute([$formulaName]);
    $dir = sys_get_temp_dir() . '/cpe-export-' . bin2hex(random_bytes(4));
    try {
        $result = (new SnapshotExporter($pdo))->export($dir);
        assert_true(preg_match('/^export_[a-f0-9]{24}$/', (string) $result['export_reference']) === 1, 'Export should return an opaque reference');
        assert_same('full', $result['profile'], 'Default export profile should be full');
        assert_true(is_file($dir . '/manifest.csv'), 'Manifest should be written');
        assert_true(is_file($dir . '/placement_totals.csv'), 'Full export should include summary totals');
        assert_true(is_file($dir . '/user_board_preferences.csv'), 'Board preferences CSV should be written');
        assert_true(is_file($dir . '/notifications.csv'), 'Notifications CSV should be written');
        assert_true(is_file($dir . '/notification_deliveries.csv'), 'Notification deliveries CSV should be written');
        assert_true(is_file($dir . '/candidates.csv'), 'Candidates CSV should be written');
        assert_true(is_file($dir . '/applications.csv'), 'Applications CSV should be written');
        assert_true(is_file($dir . '/round_schedules.csv'), 'Round schedules CSV should be written');
        assert_true(is_file($dir . '/round_panelists.csv'), 'Round panelists CSV should be written');
        assert_true(is_file($dir . '/application_slot_assignments.csv'), 'Slot assignments CSV should be written');
        assert_true(is_file($dir . '/candidate_unavailability_windows.csv'), 'Candidate unavailable windows CSV should be written');
        assert_true(is_file($dir . '/users.csv'), 'Users CSV should be written');
        $manifest = file_get_contents($dir . '/manifest.csv') ?: '';
        $totals = file_get_contents($dir . '/placement_totals.csv') ?: '';
        $boardPreferences = file_get_contents($dir . '/user_board_preferences.csv') ?: '';
        $notifications = file_get_contents($dir . '/notifications.csv') ?: '';
        $notificationDeliveries = file_get_contents($dir . '/notification_deliveries.csv') ?: '';
        $auditLogs = file_get_contents($dir . '/audit_logs.csv') ?: '';
        $users = file_get_contents($dir . '/users.csv') ?: '';
        $candidates = file_get_contents($dir . '/candidates.csv') ?: '';
        $companies = file_get_contents($dir . '/companies.csv') ?: '';
        $applications = file_get_contents($dir . '/applications.csv') ?: '';
        $schedules = file_get_contents($dir . '/round_schedules.csv') ?: '';
        $panelists = file_get_contents($dir . '/round_panelists.csv') ?: '';
        $assignments = file_get_contents($dir . '/application_slot_assignments.csv') ?: '';
        $unavailability = file_get_contents($dir . '/candidate_unavailability_windows.csv') ?: '';
        assert_true(str_contains($manifest, 'candidates.csv'), 'Manifest should list candidates');
        assert_true(str_contains($manifest, 'placement_totals.csv'), 'Manifest should list summary totals');
        assert_true(str_contains($manifest, 'user_board_preferences.csv'), 'Manifest should list board preferences');
        assert_true(str_contains($manifest, 'notifications.csv'), 'Manifest should list notifications');
        assert_true(str_contains($manifest, 'notification_deliveries.csv'), 'Manifest should list notification deliveries');
        assert_true(str_contains($manifest, 'company_rounds.csv'), 'Manifest should list company rounds');
        assert_true(str_contains($manifest, 'round_schedules.csv'), 'Manifest should list round schedules');
        assert_true(str_contains($manifest, 'round_panelists.csv'), 'Manifest should list round panelists');
        assert_true(str_contains($manifest, 'application_slot_assignments.csv'), 'Manifest should list slot assignments');
        assert_true(str_contains($manifest, 'candidate_unavailability_windows.csv'), 'Manifest should list unavailable windows');
        assert_true(str_contains($schedules, 'room,schedule_day,starts_at'), 'Round schedule export should include schedule day');
        assert_true(str_contains($schedules, 'capacity,schedule_status,notes'), 'Round schedule export should include schedule status');
        assert_true(str_contains($assignments, 'room,schedule_day,starts_at'), 'Slot assignment export should include schedule day');
        assert_true(str_contains($totals, 'placed_candidates'), 'Summary totals should include placed candidate count');
        assert_true(!str_contains($users, 'password_hash'), 'User export should not include password hashes');
        assert_true(str_contains($boardPreferences, 'user_email,q,company,status,flag,actionable,compact,stale_minutes'), 'Board preference export should use readable keys');
        assert_true(str_contains($notifications, 'recipient_role,recipient_scope_type,recipient_scope_value,channel,template_key,subject'), 'Notification export should use readable keys');
        assert_true(str_contains($notificationDeliveries, 'notification_id,channel,status,attempt_count,last_error,delivered_to,payload_json'), 'Notification delivery export should include fixed destination and payload status');
        assert_true(!str_contains($notificationDeliveries, 'target'), 'Notification delivery export should not expose delivery targets');
        assert_true(str_contains($auditLogs, 'detail,ip_address,user_agent,created_at'), 'Audit export should include optional request metadata fields');
        assert_true(str_contains($boardPreferences, 'admin@test.local'), 'Board preference export should include user email');
        assert_true(str_contains($users, 'admin@test.local'), 'User export should include email identities');
        assert_true(str_contains($candidates, 'C001'), 'Candidate export should include demo candidates');
        assert_true(str_contains($candidates, "'=HYPERLINK"), 'Candidate export should neutralize spreadsheet formula cells');
        assert_true(!str_contains($candidates, ',' . $formulaName . ','), 'Candidate export should not emit an executable formula prefix');
        assert_true(str_contains($candidates, 'program,tags,custom_fields_json,current_location,accommodation_notes,opted_out,anonymized_at'), 'Candidate export should include tags, custom fields, accommodation, and anonymization fields');
        assert_true(str_contains($companies, 'deadline_day,deadline_at,process_notes,tags,custom_fields_json'), 'Company export should include deadline fields, tags, and custom fields');
        assert_true(str_contains($applications, 'candidate_external_id,company_code,current_status'), 'Application export should use readable keys');
        assert_true(str_contains($applications, 'previous_company_code,next_company_code'), 'Application export should include handoff route context');
        assert_true(str_contains($schedules, 'company_code,round_sequence,round_label,schedule_sequence,room'), 'Schedule export should use readable keys');
        assert_true(str_contains($panelists, 'company_code,round_sequence,round_label,panel_sequence,name'), 'Panelist export should use readable keys');
        assert_true(str_contains($panelists, 'contact,availability_status,notes'), 'Panelist export should include availability status');
        assert_true(str_contains($assignments, 'candidate_external_id,company_code,round_sequence,round_label,schedule_sequence,room'), 'Assignment export should use readable keys');
        assert_true(str_contains($unavailability, 'candidate_external_id,label,schedule_day,starts_at,ends_at,notes'), 'Unavailable-window export should use readable keys');
        assert_true(str_contains($unavailability, 'UNAV001'), 'Unavailable-window export should include imported candidate ID');
        assert_true(str_contains($unavailability, 'Exam block'), 'Unavailable-window export should include imported label');
        assert_true(str_contains($unavailability, '09:00') && str_contains($unavailability, '09:30'), 'Unavailable-window export should include imported times');

        $operationsDir = sys_get_temp_dir() . '/cpe-export-operations-' . bin2hex(random_bytes(4));
        $operations = (new SnapshotExporter($pdo))->export($operationsDir, 'operations');
        assert_same('operations', $operations['profile'], 'Operations export should report selected profile');
        assert_true(is_file($operationsDir . '/candidates.csv'), 'Operations export should include candidates');
        assert_true(is_file($operationsDir . '/candidate_unavailability_windows.csv'), 'Operations export should include unavailable windows');
        assert_true(is_file($operationsDir . '/events.csv'), 'Operations export should include events');
        assert_true(!is_file($operationsDir . '/users.csv'), 'Operations export should exclude users');
        assert_true(!is_file($operationsDir . '/notification_deliveries.csv'), 'Operations export should exclude notification deliveries');
        assert_true(!is_file($operationsDir . '/audit_logs.csv'), 'Operations export should exclude audit logs');

        $summaryDir = sys_get_temp_dir() . '/cpe-export-summary-' . bin2hex(random_bytes(4));
        $summary = (new SnapshotExporter($pdo))->export($summaryDir, 'summary');
        assert_same('summary', $summary['profile'], 'Summary export should report selected profile');
        assert_true(is_file($summaryDir . '/placement_totals.csv'), 'Summary export should include totals');
        assert_true(is_file($summaryDir . '/placements_by_company.csv'), 'Summary export should include company placement counts');
        assert_true(!is_file($summaryDir . '/candidates.csv'), 'Summary export should exclude identifiable candidate rows');
        assert_true(str_contains(file_get_contents($summaryDir . '/manifest.csv') ?: '', 'candidates_by_program.csv'), 'Summary manifest should list program counts');

        [$helpCode, $helpOut, $helpErr] = run_cli(['export', '--help']);
        assert_same(0, $helpCode, 'Export help should exit cleanly: ' . $helpErr);
        assert_true(str_contains($helpOut, '--profile=summary'), 'Export help should document summary profile');
        assert_true(str_contains($helpOut, 'custom'), 'Export help should document custom profile');

        $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $set->execute(['export_profile_custom_datasets', 'placement_totals,candidates,companies']);
        $customDir = sys_get_temp_dir() . '/cpe-export-custom-' . bin2hex(random_bytes(4));
        $custom = (new SnapshotExporter($pdo))->export($customDir, 'custom');
        assert_same('custom', $custom['profile'], 'Custom export should report selected profile');
        assert_true(is_file($customDir . '/placement_totals.csv'), 'Custom export should include configured summary dataset');
        assert_true(is_file($customDir . '/candidates.csv'), 'Custom export should include configured candidate dataset');
        assert_true(is_file($customDir . '/companies.csv'), 'Custom export should include configured company dataset');
        assert_true(!is_file($customDir . '/applications.csv'), 'Custom export should exclude unconfigured datasets');
        $customManifest = file_get_contents($customDir . '/manifest.csv') ?: '';
        assert_true(str_contains($customManifest, 'placement_totals.csv'), 'Custom manifest should list configured totals');
        assert_true(str_contains($customManifest, 'candidates.csv'), 'Custom manifest should list configured candidates');
        assert_true(!str_contains($customManifest, 'applications.csv'), 'Custom manifest should omit unconfigured applications');

        $set->execute(['export_profile_custom_datasets', 'unknown_dataset']);
        $badCustomDir = sys_get_temp_dir() . '/cpe-export-custom-bad-' . bin2hex(random_bytes(4));
        try {
            (new SnapshotExporter($pdo))->export($badCustomDir, 'custom');
            throw new RuntimeException('Expected bad custom export profile failure');
        } catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), 'Unknown export dataset'), 'Custom export should reject unknown datasets');
        }
        $set->execute(['export_profile_custom_datasets', 'placement_totals,application_status_counts,placements_by_company']);

        $cliDir = sys_get_temp_dir() . '/cpe-export-cli-summary-' . bin2hex(random_bytes(4));
        [$code, $stdout, $stderr] = run_cli(['export', $cliDir, '--profile=summary']);
        assert_same(0, $code, 'Summary export CLI should exit cleanly: ' . $stderr);
        assert_true(str_contains($stdout, 'Profile: summary'), 'Summary export CLI should print selected profile');
        assert_true(is_file($cliDir . '/placement_totals.csv'), 'Summary export CLI should write summary totals');
    } finally {
        $nameUpdate->execute([$originalCandidateName]);
        if (is_dir($dir)) {
            remove_tree($dir);
        }
        if (isset($operationsDir) && is_dir($operationsDir)) {
            remove_tree($operationsDir);
        }
        if (isset($summaryDir) && is_dir($summaryDir)) {
            remove_tree($summaryDir);
        }
        if (isset($customDir) && is_dir($customDir)) {
            remove_tree($customDir);
        }
        if (isset($badCustomDir) && is_dir($badCustomDir)) {
            remove_tree($badCustomDir);
        }
        if (isset($cliDir) && is_dir($cliDir)) {
            remove_tree($cliDir);
        }
    }
});

test_case('import rollback snapshots restore the database to pre-import state', function (): void {
    $pdo = Database::connection();
    $service = new ImportRollbackService();
    $importer = new CsvImporter($pdo);
    $before = (int) $pdo->query("SELECT COUNT(*) FROM candidates WHERE external_id = 'RB001'")->fetchColumn();
    $report = $importer->preview('candidates', "external_id,name,program\nRB001,Rollback Candidate,MBA\n");
    $snapshot = $service->createSnapshot('candidates', 1, $report);
    $rollbackDir = (string) getenv('CPE_IMPORT_ROLLBACK_DIR');
    assert_true(is_file($rollbackDir . '/' . (string) $snapshot['backup_file']), 'Rollback snapshot database should be copied');

    $importer->candidates("external_id,name,program\nRB001,Rollback Candidate,MBA\n");
    assert_same($before + 1, (int) $pdo->query("SELECT COUNT(*) FROM candidates WHERE external_id = 'RB001'")->fetchColumn(), 'Candidate should import before rollback');

    $restored = $service->restore((string) $snapshot['id']);
    assert_true(preg_match('/^backup_[a-f0-9]{24}$/', (string) $restored['restore_safety_reference']) === 1, 'Rollback should return an opaque safety reference');
    $pdo = Database::connection();
    assert_same($before, (int) $pdo->query("SELECT COUNT(*) FROM candidates WHERE external_id = 'RB001'")->fetchColumn(), 'Rollback should restore pre-import candidate state');

    $recent = $service->recent(1);
    assert_same($snapshot['id'], $recent[0]['id'], 'Recent rollback list should include restored snapshot');
    assert_true($recent[0]['restored_at'] !== '', 'Recent rollback list should mark restored snapshot');

    [$listCode, $listOut, $listErr] = run_cli(['rollback-import', '--list']);
    assert_same(0, $listCode, 'Rollback list CLI should exit cleanly: ' . $listErr);
    assert_true(str_contains($listOut, (string) $snapshot['id']), 'Rollback list CLI should include snapshot id');

    $snapshot = $service->createSnapshot('candidates', null, $report);
    $importer = new CsvImporter(Database::connection());
    $importer->candidates("external_id,name,program\nRB001,Rollback Candidate,MBA\n");
    unset($importer, $pdo);
    Database::reset();
    [$restoreCode, $restoreOut, $restoreErr] = run_cli(['rollback-import', (string) $snapshot['id']]);
    assert_same(0, $restoreCode, 'Rollback restore CLI should exit cleanly: ' . $restoreErr);
    assert_true(str_contains($restoreOut, 'Restored import rollback snapshot'), 'Rollback restore CLI should report success');
    Database::reset();
    $pdo = Database::connection();
    assert_same($before, (int) $pdo->query("SELECT COUNT(*) FROM candidates WHERE external_id = 'RB001'")->fetchColumn(), 'CLI rollback should restore pre-import candidate state');
});

test_case('configuration export imports portable settings without operational data', function (): void {
    $pdo = Database::connection();
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $aliasJson = (new CsvImporter($pdo))->normalizeHeaderAliasJson(json_encode([
        'external_id' => ['Campus UID'],
        'candidate_external_id' => ['Campus UID'],
        'company_code' => ['Recruiter Short Code'],
        'status' => ['Process State'],
    ], JSON_UNESCAPED_SLASHES) ?: '');
    foreach ([
        'college_name' => 'Portable Config College',
        'site_name' => 'Portable Placement Desk',
        'site_tagline' => 'Portable live operations',
        'public_placements_title' => 'Portable Placement Results',
        'candidate_status_title' => 'Portable Participant Portal',
        'timezone' => 'Asia/Kolkata',
        'cycle_name' => 'Portable Final Cycle',
        'cycle_type' => 'lateral',
        'cycle_start_date' => '2026-05-01',
        'cycle_end_date' => '2026-05-20',
        'calendar_non_operating_weekdays' => 'sat,sun',
        'calendar_non_operating_dates' => '2026-05-05,2026-05-12',
        'audit_request_metadata' => 'both',
        'workflow' => 'default',
        'configuration_freeze' => '0',
        'terminology_candidate_label' => 'Learner',
        'terminology_candidates_label' => 'Learners',
        'terminology_company_label' => 'Recruiter',
        'terminology_companies_label' => 'Recruiters',
        'notification_sms_to' => '+910000000000',
        'notification_email_subject_template' => '{{college_name}} / {{subject}}',
        'scheduling_buffer_minutes' => '25',
        'slot_planner_strategy' => 'earliest',
        'slot_optimizer_exact_limit' => '7',
        'board_refresh_seconds' => '90',
        'board_card_fields' => 'candidate_id,company,location',
        'export_profile_custom_datasets' => 'placement_totals,candidates,companies',
        'import_header_aliases_json' => $aliasJson,
        'allow_offer_upgrade' => '1',
    ] as $key => $value) {
        $set->execute([$key, $value]);
    }
    $pdo->exec('DELETE FROM workflow_status_overrides');
    $pdo->exec('DELETE FROM workflow_transition_overrides');
    $pdo->exec("INSERT INTO workflow_status_overrides (status_key, label, color) VALUES ('scheduled', 'Config Ready', '#123456')");
    $pdo->exec("INSERT INTO workflow_transition_overrides (from_status, to_status, roles_csv) VALUES ('scheduled', 'intransit', 'mobile')");

    $service = new ConfigurationSnapshotService($pdo);
    $target = sys_get_temp_dir() . '/cpe-config-' . bin2hex(random_bytes(4)) . '.json';
    $result = $service->export($target);
    assert_true(preg_match('/^config_[a-f0-9]{24}$/', (string) $result['file_reference']) === 1, 'Configuration export should return an opaque reference');
    assert_true(is_file($target), 'Configuration export should write JSON');

    $json = file_get_contents($target) ?: '';
    assert_true(str_contains($json, 'Portable Config College'), 'Configuration export should include portable college setting');
    assert_true(str_contains($json, 'Portable Placement Desk'), 'Configuration export should include portable site identity settings');
    assert_true(str_contains($json, 'Portable Final Cycle'), 'Configuration export should include portable cycle setting');
    assert_true(str_contains($json, 'calendar_non_operating_weekdays'), 'Configuration export should include portable calendar guardrail settings');
    assert_true(str_contains($json, 'audit_request_metadata'), 'Configuration export should include portable audit metadata retention setting');
    assert_true(str_contains($json, 'Learners'), 'Configuration export should include portable terminology labels');
    assert_true(str_contains($json, 'board_refresh_seconds'), 'Configuration export should include board refresh settings');
    assert_true(str_contains($json, 'board_card_fields'), 'Configuration export should include board card field settings');
    assert_true(str_contains($json, 'configuration_freeze'), 'Configuration export should include configuration freeze setting');
    assert_true(str_contains($json, 'export_profile_custom_datasets'), 'Configuration export should include custom export profile settings');
    assert_true(str_contains($json, 'import_header_aliases_json'), 'Configuration export should include custom import alias settings');
    assert_true(str_contains($json, 'Campus UID'), 'Configuration export should include configured custom import aliases');
    assert_true(str_contains($json, 'notification_email_subject_template'), 'Configuration export should include portable notification templates');
    foreach (['+910000000000', 'notification_sms_to', 'admin@test.local', 'password_hash', 'C001', 'installed_at'] as $forbidden) {
        assert_true(!str_contains($json, $forbidden), "Configuration export should not include {$forbidden}");
    }

    $validated = $service->validate($target);
    assert_same($result['file_reference'], $validated['file_reference'], 'Configuration validation should report the same opaque reference');
    assert_same('default', $validated['workflow'], 'Configuration validation should report workflow key');
    assert_true($validated['settings'] >= 9, 'Configuration validation should count portable settings');
    assert_same(1, $validated['status_overrides'], 'Configuration validation should count status overrides');
    assert_same(1, $validated['transition_overrides'], 'Configuration validation should count transition overrides');

    $set->execute(['college_name', 'Mutated College']);
    $set->execute(['site_name', 'Mutated Desk']);
    $set->execute(['site_tagline', 'Mutated tagline']);
    $set->execute(['public_placements_title', 'Mutated Results']);
    $set->execute(['candidate_status_title', 'Mutated Portal']);
    $set->execute(['cycle_name', 'Mutated Cycle']);
    $set->execute(['cycle_type', 'final']);
    $set->execute(['cycle_start_date', '']);
    $set->execute(['cycle_end_date', '']);
    $set->execute(['calendar_non_operating_weekdays', '']);
    $set->execute(['calendar_non_operating_dates', '']);
    $set->execute(['audit_request_metadata', 'none']);
    $set->execute(['configuration_freeze', '0']);
    $set->execute(['terminology_candidate_label', 'Candidate']);
    $set->execute(['terminology_candidates_label', 'Candidates']);
    $set->execute(['terminology_company_label', 'Company']);
    $set->execute(['terminology_companies_label', 'Companies']);
    $set->execute(['scheduling_buffer_minutes', '0']);
    $set->execute(['slot_planner_strategy', 'sequence']);
    $set->execute(['board_refresh_seconds', '0']);
    $set->execute(['board_card_fields', 'candidate_id,program']);
    $set->execute(['export_profile_custom_datasets', 'placement_totals']);
    $set->execute(['import_header_aliases_json', '']);
    $set->execute(['allow_offer_upgrade', '0']);
    $pdo->exec('DELETE FROM workflow_status_overrides');
    $pdo->exec('DELETE FROM workflow_transition_overrides');

    $service->validate($target);
    assert_same('Mutated College', $pdo->query("SELECT value FROM settings WHERE key = 'college_name'")->fetchColumn(), 'Configuration validation should not mutate settings');
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM workflow_status_overrides')->fetchColumn(), 'Configuration validation should not mutate status overrides');

    $imported = $service->import($target);
    assert_true(preg_match('/^backup_[a-f0-9]{24}$/', (string) $imported['safety_reference']) === 1, 'Configuration import should return an opaque safety reference');
    assert_same('Portable Config College', $pdo->query("SELECT value FROM settings WHERE key = 'college_name'")->fetchColumn(), 'Configuration import should restore college name');
    assert_same('Portable Placement Desk', $pdo->query("SELECT value FROM settings WHERE key = 'site_name'")->fetchColumn(), 'Configuration import should restore site name');
    assert_same('Portable live operations', $pdo->query("SELECT value FROM settings WHERE key = 'site_tagline'")->fetchColumn(), 'Configuration import should restore site tagline');
    assert_same('Portable Placement Results', $pdo->query("SELECT value FROM settings WHERE key = 'public_placements_title'")->fetchColumn(), 'Configuration import should restore public page title');
    assert_same('Portable Participant Portal', $pdo->query("SELECT value FROM settings WHERE key = 'candidate_status_title'")->fetchColumn(), 'Configuration import should restore candidate status title');
    assert_same('Portable Final Cycle', $pdo->query("SELECT value FROM settings WHERE key = 'cycle_name'")->fetchColumn(), 'Configuration import should restore cycle name');
    assert_same('lateral', $pdo->query("SELECT value FROM settings WHERE key = 'cycle_type'")->fetchColumn(), 'Configuration import should restore cycle type');
    assert_same('2026-05-01', $pdo->query("SELECT value FROM settings WHERE key = 'cycle_start_date'")->fetchColumn(), 'Configuration import should restore cycle start date');
    assert_same('2026-05-20', $pdo->query("SELECT value FROM settings WHERE key = 'cycle_end_date'")->fetchColumn(), 'Configuration import should restore cycle end date');
    assert_same('sat,sun', $pdo->query("SELECT value FROM settings WHERE key = 'calendar_non_operating_weekdays'")->fetchColumn(), 'Configuration import should restore non-operating weekdays');
    assert_same('2026-05-05,2026-05-12', $pdo->query("SELECT value FROM settings WHERE key = 'calendar_non_operating_dates'")->fetchColumn(), 'Configuration import should restore non-operating dates');
    assert_same('both', $pdo->query("SELECT value FROM settings WHERE key = 'audit_request_metadata'")->fetchColumn(), 'Configuration import should restore audit request metadata mode');
    assert_same('0', $pdo->query("SELECT value FROM settings WHERE key = 'configuration_freeze'")->fetchColumn(), 'Configuration import should restore configuration freeze setting');
    assert_same('Learner', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_candidate_label'")->fetchColumn(), 'Configuration import should restore singular candidate terminology');
    assert_same('Learners', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_candidates_label'")->fetchColumn(), 'Configuration import should restore plural candidate terminology');
    assert_same('Recruiter', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_company_label'")->fetchColumn(), 'Configuration import should restore singular company terminology');
    assert_same('Recruiters', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_companies_label'")->fetchColumn(), 'Configuration import should restore plural company terminology');
    assert_same('25', $pdo->query("SELECT value FROM settings WHERE key = 'scheduling_buffer_minutes'")->fetchColumn(), 'Configuration import should restore scheduling buffer');
    assert_same('earliest', $pdo->query("SELECT value FROM settings WHERE key = 'slot_planner_strategy'")->fetchColumn(), 'Configuration import should restore planner strategy');
    assert_same('90', $pdo->query("SELECT value FROM settings WHERE key = 'board_refresh_seconds'")->fetchColumn(), 'Configuration import should restore board refresh interval');
    assert_same('candidate_id,company,location', $pdo->query("SELECT value FROM settings WHERE key = 'board_card_fields'")->fetchColumn(), 'Configuration import should restore board card fields');
    assert_same('placement_totals,candidates,companies', $pdo->query("SELECT value FROM settings WHERE key = 'export_profile_custom_datasets'")->fetchColumn(), 'Configuration import should restore custom export profile settings');
    assert_same($aliasJson, $pdo->query("SELECT value FROM settings WHERE key = 'import_header_aliases_json'")->fetchColumn(), 'Configuration import should restore custom import alias settings');
    assert_same('1', $pdo->query("SELECT value FROM settings WHERE key = 'allow_offer_upgrade'")->fetchColumn(), 'Configuration import should restore policy settings');
    $workflow = new Workflow();
    assert_same('Config Ready', $workflow->statusLabel('scheduled'), 'Configuration import should restore status override');
    assert_true($workflow->canTransition('scheduled', 'intransit', 'mobile'), 'Configuration import should restore transition override');
    assert_true(!$workflow->canTransition('scheduled', 'intransit', 'company'), 'Configuration import should replace transition role override');

    $cliTarget = sys_get_temp_dir() . '/cpe-config-cli-' . bin2hex(random_bytes(4)) . '.json';
    [$exportCode, $exportOut, $exportErr] = run_cli(['config-export', $cliTarget]);
    assert_same(0, $exportCode, 'Config export CLI should exit cleanly: ' . $exportErr);
    assert_true(str_contains($exportOut, 'Configuration export written'), 'Config export CLI should report success');
    assert_true(is_file($cliTarget), 'Config export CLI should write JSON');
    $set->execute(['college_name', 'CLI Mutated College']);
    [$validateCode, $validateOut, $validateErr] = run_cli(['config-validate', $cliTarget]);
    assert_same(0, $validateCode, 'Config validate CLI should exit cleanly: ' . $validateErr);
    assert_true(str_contains($validateOut, 'Configuration valid'), 'Config validate CLI should report success');
    assert_true(str_contains($validateOut, 'Workflow: default'), 'Config validate CLI should report workflow');
    Database::reset();
    $pdo = Database::connection();
    assert_same('CLI Mutated College', $pdo->query("SELECT value FROM settings WHERE key = 'college_name'")->fetchColumn(), 'Config validate CLI should not import settings');
    [$importCode, $importOut, $importErr] = run_cli(['config-import', $cliTarget]);
    assert_same(0, $importCode, 'Config import CLI should exit cleanly: ' . $importErr);
    assert_true(str_contains($importOut, 'Configuration imported from'), 'Config import CLI should report success');
    Database::reset();
    $pdo = Database::connection();
    assert_same('Portable Config College', $pdo->query("SELECT value FROM settings WHERE key = 'college_name'")->fetchColumn(), 'Config import CLI should restore settings');
    assert_same('Portable Placement Desk', $pdo->query("SELECT value FROM settings WHERE key = 'site_name'")->fetchColumn(), 'Config import CLI should restore site identity settings');
    assert_same('sat,sun', $pdo->query("SELECT value FROM settings WHERE key = 'calendar_non_operating_weekdays'")->fetchColumn(), 'Config import CLI should restore calendar guardrail settings');
    assert_same('both', $pdo->query("SELECT value FROM settings WHERE key = 'audit_request_metadata'")->fetchColumn(), 'Config import CLI should restore audit request metadata setting');
    assert_same('Learner', $pdo->query("SELECT value FROM settings WHERE key = 'terminology_candidate_label'")->fetchColumn(), 'Config import CLI should restore terminology settings');
    assert_same('90', $pdo->query("SELECT value FROM settings WHERE key = 'board_refresh_seconds'")->fetchColumn(), 'Config import CLI should restore board refresh interval');
    assert_same('candidate_id,company,location', $pdo->query("SELECT value FROM settings WHERE key = 'board_card_fields'")->fetchColumn(), 'Config import CLI should restore board card fields');
    assert_same('placement_totals,candidates,companies', $pdo->query("SELECT value FROM settings WHERE key = 'export_profile_custom_datasets'")->fetchColumn(), 'Config import CLI should restore custom export profile settings');
    assert_same($aliasJson, $pdo->query("SELECT value FROM settings WHERE key = 'import_header_aliases_json'")->fetchColumn(), 'Config import CLI should restore custom import alias settings');

    $set->execute(['configuration_freeze', '1']);
    try {
        (new ConfigurationSnapshotService($pdo))->import($target);
        throw new RuntimeException('Expected configuration freeze import failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'Configuration changes are frozen'), 'Configuration import should respect freeze setting');
    }
    $set->execute(['configuration_freeze', '0']);

    $badTarget = sys_get_temp_dir() . '/cpe-config-bad-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($badTarget, json_encode([
        'schema' => 'cpe.config.v1',
        'settings' => ['notification_sms_to' => '+910000000000'],
        'workflow_status_overrides' => [],
        'workflow_transition_overrides' => [],
    ], JSON_PRETTY_PRINT));
    try {
        (new ConfigurationSnapshotService($pdo))->validate($badTarget);
        throw new RuntimeException('Expected non-portable config validation failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'non-portable setting'), 'Config validate should reject local-only settings');
    }
    try {
        (new ConfigurationSnapshotService($pdo))->import($badTarget);
        throw new RuntimeException('Expected non-portable config import failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'non-portable setting'), 'Config import should reject local-only settings');
    }

    $badDateTarget = sys_get_temp_dir() . '/cpe-config-bad-date-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($badDateTarget, json_encode([
        'schema' => 'cpe.config.v1',
        'settings' => [
            'workflow' => 'default',
            'cycle_start_date' => '2026-13-01',
        ],
        'workflow_status_overrides' => [],
        'workflow_transition_overrides' => [],
    ], JSON_PRETTY_PRINT));
    try {
        (new ConfigurationSnapshotService($pdo))->validate($badDateTarget);
        throw new RuntimeException('Expected invalid cycle date validation failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'cycle_start_date must use YYYY-MM-DD'), 'Config validate should reject invalid cycle dates');
    }

    $badCycleTypeTarget = sys_get_temp_dir() . '/cpe-config-bad-cycle-type-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($badCycleTypeTarget, json_encode([
        'schema' => 'cpe.config.v1',
        'settings' => [
            'workflow' => 'default',
            'cycle_type' => 'unknown_cycle',
        ],
        'workflow_status_overrides' => [],
        'workflow_transition_overrides' => [],
    ], JSON_PRETTY_PRINT));
    try {
        (new ConfigurationSnapshotService($pdo))->validate($badCycleTypeTarget);
        throw new RuntimeException('Expected invalid cycle type validation failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'cycle_type must be final'), 'Config validate should reject invalid cycle type');
    }

    $badExportTarget = sys_get_temp_dir() . '/cpe-config-bad-export-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($badExportTarget, json_encode([
        'schema' => 'cpe.config.v1',
        'settings' => [
            'workflow' => 'default',
            'export_profile_custom_datasets' => 'placement_totals,not_a_dataset',
        ],
        'workflow_status_overrides' => [],
        'workflow_transition_overrides' => [],
    ], JSON_PRETTY_PRINT));
    try {
        (new ConfigurationSnapshotService($pdo))->validate($badExportTarget);
        throw new RuntimeException('Expected invalid custom export dataset validation failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'Unknown export dataset'), 'Config validate should reject unknown custom export datasets');
    }

    $badAliasTarget = sys_get_temp_dir() . '/cpe-config-bad-alias-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($badAliasTarget, json_encode([
        'schema' => 'cpe.config.v1',
        'settings' => [
            'workflow' => 'default',
            'import_header_aliases_json' => '{"not_a_field":["Campus UID"]}',
        ],
        'workflow_status_overrides' => [],
        'workflow_transition_overrides' => [],
    ], JSON_PRETTY_PRINT));
    try {
        (new ConfigurationSnapshotService($pdo))->validate($badAliasTarget);
        throw new RuntimeException('Expected invalid custom import alias validation failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'unknown field'), 'Config validate should reject unknown custom import alias fields');
    }

    $badTerminologyTarget = sys_get_temp_dir() . '/cpe-config-bad-terminology-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($badTerminologyTarget, json_encode([
        'schema' => 'cpe.config.v1',
        'settings' => [
            'workflow' => 'default',
            'terminology_candidate_label' => str_repeat('A', 41),
        ],
        'workflow_status_overrides' => [],
        'workflow_transition_overrides' => [],
    ], JSON_PRETTY_PRINT));
    try {
        (new ConfigurationSnapshotService($pdo))->validate($badTerminologyTarget);
        throw new RuntimeException('Expected invalid terminology label validation failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'Terminology labels'), 'Config validate should reject long terminology labels');
    }

    $badIdentityTarget = sys_get_temp_dir() . '/cpe-config-bad-identity-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($badIdentityTarget, json_encode([
        'schema' => 'cpe.config.v1',
        'settings' => [
            'workflow' => 'default',
            'site_name' => str_repeat('A', 81),
        ],
        'workflow_status_overrides' => [],
        'workflow_transition_overrides' => [],
    ], JSON_PRETTY_PRINT));
    try {
        (new ConfigurationSnapshotService($pdo))->validate($badIdentityTarget);
        throw new RuntimeException('Expected invalid text identity validation failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'site_name must be 80'), 'Config validate should reject long site identity labels');
    }

    $badCalendarWeekdayTarget = sys_get_temp_dir() . '/cpe-config-bad-calendar-weekday-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($badCalendarWeekdayTarget, json_encode([
        'schema' => 'cpe.config.v1',
        'settings' => [
            'workflow' => 'default',
            'calendar_non_operating_weekdays' => 'funday',
        ],
        'workflow_status_overrides' => [],
        'workflow_transition_overrides' => [],
    ], JSON_PRETTY_PRINT));
    try {
        (new ConfigurationSnapshotService($pdo))->validate($badCalendarWeekdayTarget);
        throw new RuntimeException('Expected invalid calendar weekday validation failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'unknown weekday'), 'Config validate should reject unknown calendar weekdays');
    }

    $badCalendarDateTarget = sys_get_temp_dir() . '/cpe-config-bad-calendar-date-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($badCalendarDateTarget, json_encode([
        'schema' => 'cpe.config.v1',
        'settings' => [
            'workflow' => 'default',
            'calendar_non_operating_dates' => '2026-99-01',
        ],
        'workflow_status_overrides' => [],
        'workflow_transition_overrides' => [],
    ], JSON_PRETTY_PRINT));
    try {
        (new ConfigurationSnapshotService($pdo))->validate($badCalendarDateTarget);
        throw new RuntimeException('Expected invalid calendar date validation failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'calendar_non_operating_dates must use YYYY-MM-DD'), 'Config validate should reject invalid calendar dates');
    }

    $badAuditMetadataTarget = sys_get_temp_dir() . '/cpe-config-bad-audit-metadata-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($badAuditMetadataTarget, json_encode([
        'schema' => 'cpe.config.v1',
        'settings' => [
            'workflow' => 'default',
            'audit_request_metadata' => 'full_fingerprint',
        ],
        'workflow_status_overrides' => [],
        'workflow_transition_overrides' => [],
    ], JSON_PRETTY_PRINT));
    try {
        (new ConfigurationSnapshotService($pdo))->validate($badAuditMetadataTarget);
        throw new RuntimeException('Expected invalid audit request metadata validation failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'audit_request_metadata'), 'Config validate should reject invalid audit request metadata mode');
    }

    $cleanup = Database::connection();
    $cleanupSet = $cleanup->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    foreach ([
        'college_name' => 'Test College',
        'site_name' => 'Campus Placement Engine',
        'site_tagline' => '',
        'public_placements_title' => 'Public Placements',
        'candidate_status_title' => '',
        'timezone' => 'Asia/Kolkata',
        'cycle_name' => 'Test College Placement Cycle',
        'cycle_type' => 'final',
        'cycle_start_date' => '',
        'cycle_end_date' => '',
        'calendar_non_operating_weekdays' => '',
        'calendar_non_operating_dates' => '',
        'audit_request_metadata' => 'none',
        'workflow' => 'default',
        'configuration_freeze' => '0',
        'terminology_candidate_label' => 'Candidate',
        'terminology_candidates_label' => 'Candidates',
        'terminology_company_label' => 'Company',
        'terminology_companies_label' => 'Companies',
        'scheduling_buffer_minutes' => '0',
        'slot_planner_strategy' => 'sequence',
        'slot_optimizer_exact_limit' => '10',
        'board_card_fields' => 'candidate_id,program,tags,company,process,tracker,active_cap,rounds,schedule,slot,panel,route,location,accommodation,waitlist',
        'export_profile_custom_datasets' => 'placement_totals,application_status_counts,placements_by_company',
        'import_header_aliases_json' => '',
        'allow_offer_upgrade' => '0',
    ] as $key => $value) {
        $cleanupSet->execute([$key, $value]);
    }
    $cleanup->exec('DELETE FROM workflow_status_overrides');
    $cleanup->exec('DELETE FROM workflow_transition_overrides');

    @unlink($target);
    @unlink($cliTarget);
    @unlink($badTarget);
    @unlink($badDateTarget);
    @unlink($badCycleTypeTarget);
    @unlink($badExportTarget);
    @unlink($badAliasTarget);
    @unlink($badTerminologyTarget);
    @unlink($badIdentityTarget);
    @unlink($badCalendarWeekdayTarget);
    @unlink($badCalendarDateTarget);
    @unlink($badAuditMetadataTarget);
});

test_case('privacy anonymization redacts candidate identity while preserving history', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $privacy = new PrivacyService($pdo);

    $candidateId = $service->saveCandidate([
        'external_id' => 'PRV001',
        'name' => 'Privacy Candidate',
        'program' => 'MBA',
        'tags' => 'private-cohort',
        'current_location' => 'CP',
        'accommodation_notes' => 'Private room note',
        'custom_fields_json' => '{"private_note":"Sensitive local value"}',
    ], 1);
    $primaryCompany = $service->saveCompany(['code' => 'PRV', 'name' => 'Privacy Company'], 1);
    $optionCompany = $service->saveCompany(['code' => 'PRW', 'name' => 'Privacy Option'], 1);
    $service->saveApplication($candidateId, $primaryCompany, 'scheduled', null, 1);
    $appId = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$primaryCompany}")->fetchColumn();
    $service->transition($appId, 'intransit', 1, 'admin', 'Privacy Candidate moved from CP');
    $service->createWantedAlert($candidateId, 'Privacy Candidate missing from private room', 1);
    $service->createPreferenceRequest($candidateId, [$primaryCompany, $optionCompany], 1, 'Privacy Candidate prefers option');

    $before = $privacy->report();
    $result = $privacy->anonymizeCandidate('PRV001', 1);
    assert_true(preg_match('/^backup_[a-f0-9]{24}$/', (string) $result['safety_reference']) === 1, 'Anonymization should return an opaque safety reference');
    assert_same('ANON-' . $candidateId, $result['external_id'], 'Anonymization should return anonymous external id');

    $candidate = $pdo->query("SELECT external_id, name, program, tags, current_location, accommodation_notes, custom_fields_json, opted_out, anonymized_at FROM candidates WHERE id = {$candidateId}")->fetch();
    assert_same('ANON-' . $candidateId, $candidate['external_id'], 'Candidate external id should be anonymized');
    assert_same('Anonymized Candidate', $candidate['name'], 'Candidate name should be anonymized');
    assert_same('', $candidate['program'], 'Candidate program should be cleared');
    assert_same('', $candidate['tags'], 'Candidate tags should be cleared');
    assert_same('Anonymized', $candidate['current_location'], 'Candidate location should be anonymized');
    assert_same('', $candidate['accommodation_notes'], 'Candidate accommodation notes should be cleared');
    assert_same('{}', $candidate['custom_fields_json'], 'Candidate custom fields should be cleared');
    assert_same(1, (int) $candidate['opted_out'], 'Anonymized candidate should be opted out');
    assert_true((string) $candidate['anonymized_at'] !== '', 'Candidate anonymized timestamp should be set');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM candidates WHERE external_id = 'PRV001'")->fetchColumn(), 'Old external id should no longer resolve');
    assert_same(null, $service->studentStatus('PRV001', ['id' => 1, 'role' => 'admin', 'active' => 1]), 'Old student lookup id should not resolve after anonymization');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE candidate_id = {$candidateId}")->fetchColumn(), 'Anonymization should preserve application history');
    assert_same('[redacted by candidate anonymization]', $pdo->query("SELECT note FROM events WHERE candidate_id = {$candidateId} ORDER BY id DESC LIMIT 1")->fetchColumn(), 'Event notes should be redacted');
    assert_same('resolved', $pdo->query("SELECT status FROM wanted_alerts WHERE candidate_id = {$candidateId}")->fetchColumn(), 'Wanted alert should close');
    assert_same('[redacted by candidate anonymization]', $pdo->query("SELECT reason FROM wanted_alerts WHERE candidate_id = {$candidateId}")->fetchColumn(), 'Wanted reason should be redacted');
    assert_same('resolved', $pdo->query("SELECT status FROM preference_requests WHERE candidate_id = {$candidateId}")->fetchColumn(), 'Preference request should close');
    assert_same('[redacted by candidate anonymization]', $pdo->query("SELECT note FROM preference_requests WHERE candidate_id = {$candidateId}")->fetchColumn(), 'Preference note should be redacted');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE (subject LIKE '%PRV001%' OR subject LIKE '%Privacy Candidate%' OR body LIKE '%Privacy Candidate%')")->fetchColumn(), 'Notifications should not retain old candidate identity');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE detail LIKE '%PRV001%' OR detail LIKE '%Privacy Candidate%'")->fetchColumn(), 'Audit details should not retain old candidate identity');

    $after = $privacy->report();
    assert_same($before['anonymized_candidates'] + 1, $after['anonymized_candidates'], 'Privacy report should count anonymized candidate');
    assert_same($before['identifiable_candidates'] - 1, $after['identifiable_candidates'], 'Privacy report should reduce identifiable candidate count');

    [$reportCode, $reportOut, $reportErr] = run_cli(['privacy-report']);
    assert_same(0, $reportCode, 'Privacy report CLI should exit cleanly: ' . $reportErr);
    assert_true(str_contains($reportOut, 'anonymized_candidates'), 'Privacy report CLI should include anonymized count');

    $cliCandidateId = $service->saveCandidate(['external_id' => 'PRVCLI', 'name' => 'Privacy CLI Candidate'], 1);
    [$badCode, , $badErr] = run_cli(['anonymize-candidate', 'PRVCLI', '--confirm=WRONG']);
    assert_same(1, $badCode, 'Anonymize CLI should require exact confirmation');
    assert_true(str_contains($badErr, 'Refusing to anonymize'), 'Anonymize CLI should explain confirmation failure');
    [$cliCode, $cliOut, $cliErr] = run_cli(['anonymize-candidate', 'PRVCLI', '--confirm=PRVCLI']);
    assert_same(0, $cliCode, 'Anonymize CLI should exit cleanly: ' . $cliErr);
    assert_true(str_contains($cliOut, 'Candidate anonymized: ANON-' . $cliCandidateId), 'Anonymize CLI should report anonymous id');
    Database::reset();
    $pdo = Database::connection();
    assert_same('ANON-' . $cliCandidateId, $pdo->query("SELECT external_id FROM candidates WHERE id = {$cliCandidateId}")->fetchColumn(), 'Anonymize CLI should update candidate row');
});

test_case('legacy wide importer creates current status', function (): void {
    $pdo = Database::connection();
    $importer = new CsvImporter($pdo);
    assert_same(1, $importer->legacyWide("external_id,name,program,company_code,company_name,slot,stat1,stat2,stat3\nL001,Legacy Candidate,MBA,LEG,Legacy Co,Day 2,1,2,3\n"));
    $status = $pdo->query("SELECT a.current_status FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.external_id = 'L001'")->fetchColumn();
    assert_same('arrived', $status, 'Legacy stat columns should map to highest status');
});

test_case('legacy wide importer maps GD round and panel columns to modern slot assignments', function (): void {
    $pdo = Database::connection();
    $importer = new CsvImporter($pdo);
    $statuses = array_keys(cpe_config('workflows.default.statuses', []));
    $csv = "external_id,name,program,company_code,company_name,slot,type,gd_round,gd_panel,stat1,stat2\n" .
        "LGD001,Legacy GD Candidate,MBA,LGD,Legacy GD Co,Slot 1,GD,2,3,1,2\n";
    $preview = $importer->preview('legacy', $csv, $statuses);
    assert_true($preview['valid'], 'Legacy GD preview should be valid');
    assert_true(in_array('Map LGD001, LGD to GD Round 2 / Panel 3', $preview['samples'], true), 'Legacy GD preview should explain round/panel mapping');

    assert_same(1, $importer->legacyWide($csv, $statuses));
    $row = $pdo->query(
        "SELECT a.current_status, cr.sequence AS round_sequence, cr.label, cr.round_type, cr.room AS round_room,
                rs.sequence AS schedule_sequence, rs.room AS schedule_room, rs.schedule_day,
                asa.sequence AS assignment_sequence, asa.assignment_status, asa.notes
         FROM application_slot_assignments asa
         JOIN applications a ON a.id = asa.application_id
         JOIN candidates c ON c.id = a.candidate_id
         JOIN companies co ON co.id = a.company_id
         JOIN round_schedules rs ON rs.id = asa.round_schedule_id
         JOIN company_rounds cr ON cr.id = rs.round_id
         WHERE c.external_id = 'LGD001' AND co.code = 'LGD'"
    )->fetch();
    assert_true(is_array($row), 'Legacy GD import should create a slot assignment');
    assert_same('intransit', $row['current_status'], 'Legacy stat columns should still map current status');
    assert_same(2, (int) $row['round_sequence'], 'Legacy gd_round should become modern round sequence');
    assert_same('GD Round 2', $row['label'], 'Legacy gd_round should create a synthetic GD round label');
    assert_same('gd', $row['round_type'], 'Legacy GD round should be marked with gd round type');
    assert_same('GD Panel 3', $row['round_room'], 'Legacy gd_panel should become round room');
    assert_same(3, (int) $row['schedule_sequence'], 'Legacy gd_panel should become schedule sequence');
    assert_same('GD Panel 3', $row['schedule_room'], 'Legacy gd_panel should become schedule room');
    assert_same('Slot 1', $row['schedule_day'], 'Legacy slot should become schedule day label');
    assert_same(2, (int) $row['assignment_sequence'], 'Legacy gd_round should become assignment sequence');
    assert_same('assigned', $row['assignment_status'], 'Legacy GD panel assignment should be active');
    assert_true(str_contains($row['notes'], 'legacy GD'), 'Assignment notes should record the legacy source');

    $partial = $importer->preview(
        'legacy',
        "external_id,name,company_code,company_name,gd_round,gd_panel,stat1\nLGD002,Partial GD Candidate,LGD,Legacy GD Co,1,0,1\n",
        $statuses
    );
    assert_true($partial['valid'], 'Partial legacy GD panel data should not block basic application import');
    assert_true(str_contains(implode("\n", $partial['warnings']), 'needs both gd_round and gd_panel'), 'Preview should warn when legacy GD panel data is partial');
});

test_case('records service creates and updates records, rounds, schedules, panelists, and applications', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = $service->saveCandidate([
        'external_id' => 'R001',
        'name' => 'Records Candidate',
        'program' => 'MBA',
        'tags' => 'initial-tag',
        'current_location' => 'CP',
        'accommodation_notes' => 'Use accessible room',
        'custom_fields_json' => '{"branch":"Marketing"}',
    ], 1);
    $service->saveCandidate([
        'id' => $candidateId,
        'external_id' => 'R001',
        'name' => 'Records Candidate Updated',
        'program' => 'PGP',
        'tags' => 'updated-tag,priority',
        'current_location' => 'Control',
        'accommodation_notes' => 'Ground-floor interview room',
        'custom_fields_json' => '{"branch":"Operations","cgpa":8.7}',
        'opted_out' => '1',
    ], 1);
    $candidate = $pdo->query("SELECT name, program, tags, current_location, accommodation_notes, custom_fields_json, opted_out FROM candidates WHERE external_id = 'R001'")->fetch();
    assert_same('Records Candidate Updated', $candidate['name'], 'Candidate name should update');
    assert_same('PGP', $candidate['program'], 'Candidate program should update');
    assert_same('updated-tag,priority', $candidate['tags'], 'Candidate tags should update');
    assert_same('Control', $candidate['current_location'], 'Candidate location should update');
    assert_same('Ground-floor interview room', $candidate['accommodation_notes'], 'Candidate accommodation notes should update');
    assert_same('{"branch":"Operations","cgpa":8.7}', $candidate['custom_fields_json'], 'Candidate custom fields should update');
    assert_same(1, (int) $candidate['opted_out'], 'Candidate opt-out should update');

    $companyId = $service->saveCompany([
        'code' => 'REC',
        'name' => 'Records Company',
        'slot' => 'Day 1',
        'offer_tier' => 'dream',
        'process_type' => 'GD + PI',
        'room' => 'Room R1',
        'tracker_name' => 'Records Tracker',
        'max_active' => '3',
        'deadline_day' => '1',
        'deadline_at' => '16:00',
        'process_notes' => 'Panel starts at 9',
        'tags' => 'sector-a',
        'custom_fields_json' => '{"work_mode":"Office"}',
    ], 1);
    $service->saveCompany([
        'id' => $companyId,
        'code' => 'REC',
        'name' => 'Records Company Updated',
        'slot' => 'Day 2',
        'offer_tier' => 'core',
        'process_type' => 'Final Interview',
        'room' => 'Room R2',
        'tracker_name' => 'Updated Tracker',
        'max_active' => '1',
        'deadline_day' => '2',
        'deadline_at' => '15:30',
        'process_notes' => 'Carry score sheet',
        'tags' => 'sector-b,priority',
        'custom_fields_json' => '{"ctc_lpa":24,"work_mode":"Hybrid"}',
    ], 1);
    $company = $pdo->query("SELECT name, slot, offer_tier, process_type, room, tracker_name, max_active, deadline_day, deadline_at, process_notes, tags, custom_fields_json FROM companies WHERE code = 'REC'")->fetch();
    assert_same('Records Company Updated', $company['name'], 'Company name should update');
    assert_same('Day 2', $company['slot'], 'Company slot should update');
    assert_same('core', $company['offer_tier'], 'Company offer tier should update');
    assert_same('Final Interview', $company['process_type'], 'Company process type should update');
    assert_same('Room R2', $company['room'], 'Company room should update');
    assert_same('Updated Tracker', $company['tracker_name'], 'Company tracker should update');
    assert_same(1, (int) $company['max_active'], 'Company active cap should update');
    assert_same('2', $company['deadline_day'], 'Company deadline day should update');
    assert_same('15:30', $company['deadline_at'], 'Company deadline time should update');
    assert_same('Carry score sheet', $company['process_notes'], 'Company process notes should update');
    assert_same('sector-b,priority', $company['tags'], 'Company tags should update');
    assert_same('{"ctc_lpa":24,"work_mode":"Hybrid"}', $company['custom_fields_json'], 'Company custom fields should update');

    $service->saveApplication($candidateId, $companyId, 'scheduled', 3, 1);
    assert_true($service->applicationExists($candidateId, $companyId), 'Application should exist');
    $service->saveApplication($candidateId, $companyId, 'arrived', 1, 1);
    $application = $pdo->query("SELECT current_status, waitlist_rank FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$companyId}")->fetch();
    assert_same('arrived', $application['current_status'], 'Application status should update');
    assert_same(1, (int) $application['waitlist_rank'], 'Application waitlist rank should update');

    $roundId = $service->saveCompanyRound([
        'company_id' => $companyId,
        'sequence' => '1',
        'label' => 'Screening',
        'round_type' => 'test',
        'room' => 'Lab R',
        'duration_minutes' => '25',
        'instructions' => 'Bring laptop',
    ], 1);
    $service->saveCompanyRound([
        'id' => $roundId,
        'company_id' => $companyId,
        'sequence' => '2',
        'label' => 'Final Panel',
        'round_type' => 'interview',
        'room' => 'Room R3',
        'duration_minutes' => '35',
        'instructions' => 'Carry score sheet',
    ], 1);
    $round = $pdo->query("SELECT sequence, label, round_type, room, duration_minutes, instructions FROM company_rounds WHERE id = {$roundId}")->fetch();
    assert_same(2, (int) $round['sequence'], 'Company round sequence should update');
    assert_same('Final Panel', $round['label'], 'Company round label should update');
    assert_same('interview', $round['round_type'], 'Company round type should update');
    assert_same('Room R3', $round['room'], 'Company round room should update');
    assert_same(35, (int) $round['duration_minutes'], 'Company round duration should update');
    assert_same('Carry score sheet', $round['instructions'], 'Company round instructions should update');

    $scheduleId = $service->saveRoundSchedule([
        'round_id' => $roundId,
        'sequence' => '1',
        'room' => 'Room R3',
        'schedule_day' => '1',
        'starts_at' => '09:00',
        'ends_at' => '09:35',
        'capacity' => '2',
        'schedule_status' => 'paused',
        'notes' => 'Initial schedule',
    ], 1);
    $service->saveRoundSchedule([
        'id' => $scheduleId,
        'round_id' => $roundId,
        'sequence' => '2',
        'room' => 'Room R4',
        'schedule_day' => '2',
        'starts_at' => '10:00',
        'ends_at' => '10:40',
        'capacity' => '1',
        'schedule_status' => 'active',
        'notes' => 'Updated schedule',
    ], 1);
    $schedule = $pdo->query("SELECT sequence, room, schedule_day, starts_at, ends_at, capacity, schedule_status, notes FROM round_schedules WHERE id = {$scheduleId}")->fetch();
    assert_same(2, (int) $schedule['sequence'], 'Schedule sequence should update');
    assert_same('Room R4', $schedule['room'], 'Schedule room should update');
    assert_same('2', $schedule['schedule_day'], 'Schedule day should update');
    assert_same('10:00', $schedule['starts_at'], 'Schedule start should update');
    assert_same('10:40', $schedule['ends_at'], 'Schedule end should update');
    assert_same(1, (int) $schedule['capacity'], 'Schedule capacity should update');
    assert_same('active', $schedule['schedule_status'], 'Schedule status should update');
    assert_same('Updated schedule', $schedule['notes'], 'Schedule notes should update');

    $assignmentId = $service->saveApplicationSlotAssignment([
        'application_id' => $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$companyId}")->fetchColumn(),
        'round_schedule_id' => $scheduleId,
        'sequence' => '1',
        'assignment_status' => 'assigned',
        'notes' => 'Initial slot',
    ], 1);
    $service->saveApplicationSlotAssignment([
        'id' => $assignmentId,
        'application_id' => $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$companyId}")->fetchColumn(),
        'round_schedule_id' => $scheduleId,
        'sequence' => '2',
        'assignment_status' => 'checked-in',
        'notes' => 'Updated slot',
    ], 1);
    $assignment = $pdo->query("SELECT sequence, assignment_status, notes FROM application_slot_assignments WHERE id = {$assignmentId}")->fetch();
    assert_same(2, (int) $assignment['sequence'], 'Slot assignment sequence should update');
    assert_same('checked-in', $assignment['assignment_status'], 'Slot assignment status should update');
    assert_same('Updated slot', $assignment['notes'], 'Slot assignment notes should update');

    $otherScheduleId = (int) $pdo->query("SELECT rs.id FROM round_schedules rs JOIN company_rounds cr ON cr.id = rs.round_id JOIN companies co ON co.id = cr.company_id WHERE co.code != 'REC' LIMIT 1")->fetchColumn();
    try {
        $service->saveApplicationSlotAssignment([
            'application_id' => $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$companyId}")->fetchColumn(),
            'round_schedule_id' => $otherScheduleId,
            'sequence' => '1',
        ], 1);
        throw new RuntimeException('Expected cross-company schedule failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'Schedule does not belong'), 'Expected schedule-company mismatch message');
    }

    $panelistId = $service->saveRoundPanelist([
        'round_id' => $roundId,
        'sequence' => '1',
        'name' => 'Records Panelist',
        'role' => 'Lead',
        'affiliation' => 'Records Company',
        'contact' => 'panel@example.test',
        'availability_status' => 'unavailable',
        'notes' => 'Initial note',
    ], 1);
    $service->saveRoundPanelist([
        'id' => $panelistId,
        'round_id' => $roundId,
        'sequence' => '2',
        'name' => 'Records Panelist Updated',
        'role' => 'Observer',
        'affiliation' => 'IIMB',
        'contact' => 'updated@example.test',
        'availability_status' => 'break',
        'notes' => 'Updated note',
    ], 1);
    $panelist = $pdo->query("SELECT sequence, name, role, affiliation, contact, availability_status, notes FROM round_panelists WHERE id = {$panelistId}")->fetch();
    assert_same(2, (int) $panelist['sequence'], 'Panelist sequence should update');
    assert_same('Records Panelist Updated', $panelist['name'], 'Panelist name should update');
    assert_same('Observer', $panelist['role'], 'Panelist role should update');
    assert_same('IIMB', $panelist['affiliation'], 'Panelist affiliation should update');
    assert_same('updated@example.test', $panelist['contact'], 'Panelist contact should update');
    assert_same('break', $panelist['availability_status'], 'Panelist availability should update');
    assert_same('Updated note', $panelist['notes'], 'Panelist notes should update');
});

test_case('candidate accommodation notes and tags appear on board and candidate trace', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = $service->saveCandidate([
        'external_id' => 'AC001',
        'name' => 'Accommodation Candidate',
        'program' => 'MBA',
        'tags' => 'access-cohort',
        'current_location' => 'CP',
        'accommodation_notes' => 'Ground-floor room near lift',
        'custom_fields_json' => '{"language":"Hindi"}',
    ], 1);
    $companyId = $service->saveCompany([
        'code' => 'ACC',
        'name' => 'Access Company',
        'room' => 'Ground Floor Room',
        'process_type' => 'Interview',
        'tags' => 'access-friendly',
        'custom_fields_json' => '{"industry":"Healthcare"}',
    ], 1);
    $service->saveApplication($candidateId, $companyId, 'scheduled', null, 1);

    $matches = array_values(array_filter(
        collect_apps($service->dashboard()),
        fn (array $app): bool => ($app['external_id'] ?? '') === 'AC001'
    ));
    assert_same(1, count($matches), 'Accommodation candidate should appear on board');
    assert_same('Ground-floor room near lift', $matches[0]['accommodation_notes'] ?? '', 'Board should carry accommodation notes');
    assert_same('access-cohort', $matches[0]['candidate_tags'] ?? '', 'Board should carry candidate tags');
    assert_same('access-friendly', $matches[0]['company_tags'] ?? '', 'Board should carry company tags');
    assert_same('{"language":"Hindi"}', $matches[0]['candidate_custom_fields_json'] ?? '', 'Board should carry candidate custom fields');
    assert_same('{"industry":"Healthcare"}', $matches[0]['company_custom_fields_json'] ?? '', 'Board should carry company custom fields');
    $tagMatches = collect_apps($service->dashboard(null, ['q' => 'access-friendly']));
    assert_true(count(array_filter($tagMatches, fn (array $app): bool => ($app['external_id'] ?? '') === 'AC001')) >= 1, 'Board search should include candidate and company tags');
    $customMatches = collect_apps($service->dashboard(null, ['q' => 'Healthcare']));
    assert_true(count(array_filter($customMatches, fn (array $app): bool => ($app['external_id'] ?? '') === 'AC001')) >= 1, 'Board search should include custom fields');

    $trace = $service->candidate($candidateId);
    assert_same('Ground-floor room near lift', $trace['candidate']['accommodation_notes'] ?? '', 'Candidate trace should carry accommodation notes');
    assert_same('access-cohort', $trace['candidate']['tags'] ?? '', 'Candidate trace should carry candidate tags');
    assert_same('{"language":"Hindi"}', $trace['candidate']['custom_fields_json'] ?? '', 'Candidate trace should carry custom fields');

    $appId = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$companyId}")->fetchColumn();
    $service->moveNext($appId, 1, 'company', 'Private operator note');

    $companyRows = collect_apps($service->dashboard(['role' => 'company', 'scope_value' => 'ACC'], ['q' => 'AC001']));
    assert_same(1, count($companyRows), 'Company role should still see its scoped candidate');
    assert_same('', $companyRows[0]['candidate_tags'] ?? '', 'Company board should mask candidate tags');
    assert_same('', $companyRows[0]['accommodation_notes'] ?? '', 'Company board should mask accommodation notes');
    assert_same('{}', $companyRows[0]['candidate_custom_fields_json'] ?? '', 'Company board should mask candidate custom fields');
    assert_same('{}', $companyRows[0]['company_custom_fields_json'] ?? '', 'Company board should mask custom company fields');
    $companyHiddenSearch = collect_apps($service->dashboard(['role' => 'company', 'scope_value' => 'ACC'], ['q' => 'Hindi']));
    assert_same(0, count($companyHiddenSearch), 'Company board search should not match hidden candidate custom fields');
    $companyTagSearch = collect_apps($service->dashboard(['role' => 'company', 'scope_value' => 'ACC'], ['q' => 'access-friendly']));
    assert_same(1, count($companyTagSearch), 'Company board search may still match visible company tags');

    $mobileRows = collect_apps($service->dashboard(['role' => 'mobile'], ['company' => 'ACC', 'q' => 'Ground-floor']));
    assert_same(1, count($mobileRows), 'Mobile role should be able to search accommodation logistics');
    assert_same('Ground-floor room near lift', $mobileRows[0]['accommodation_notes'] ?? '', 'Mobile board should keep accommodation logistics visible');
    assert_same('', $mobileRows[0]['candidate_tags'] ?? '', 'Mobile board should mask private candidate tags');

    $companyTrace = $service->candidate($candidateId, ['role' => 'company', 'scope_value' => 'ACC']);
    assert_true($companyTrace !== null, 'Company trace should show scoped candidate');
    assert_same('', $companyTrace['candidate']['accommodation_notes'] ?? '', 'Company trace should mask accommodation notes');
    assert_same('', $companyTrace['candidate']['tags'] ?? '', 'Company trace should mask candidate tags');
    assert_same('{}', $companyTrace['candidate']['custom_fields_json'] ?? '', 'Company trace should mask candidate custom fields');
    assert_same(1, count($companyTrace['applications']), 'Company trace should show only scoped applications');
    assert_same('', $companyTrace['events'][0]['note'] ?? 'missing', 'Company trace should mask private event notes');
    assert_same(null, $service->candidate($candidateId, ['role' => 'company', 'scope_value' => 'OTHER']), 'Company trace should hide out-of-scope candidates');

    $adminTrace = $service->candidate($candidateId, ['role' => 'admin']);
    assert_same('Private operator note', $adminTrace['events'][0]['note'] ?? '', 'Admin trace should retain private event notes');
});

test_case('company scoped dashboard only shows assigned company', function (): void {
    $service = new PlacementService(Database::connection());
    $groups = $service->dashboard(['role' => 'company', 'scope_value' => 'ATLAS']);
    $seen = [];
    foreach ($groups as $group) {
        foreach ($group['applications'] as $app) {
            $seen[$app['company_code']] = true;
        }
    }
    assert_same(['ATLAS' => true], $seen, 'Company user should only see scoped company');
});

test_case('custom roles use scope and capabilities independently of role names', function (): void {
    $pdo = Database::connection();
    $now = cpe_now();
    $pdo->prepare(
        'INSERT INTO roles (role_key, label, system_role, created_at, updated_at)
         VALUES (?, ?, 0, ?, ?) ON CONFLICT(role_key) DO NOTHING'
    )->execute(['employer_liaison', 'Employer Liaison', $now, $now]);
    $pdo->prepare(
        'INSERT INTO role_capabilities (role_key, capability) VALUES (?, ?)
         ON CONFLICT(role_key, capability) DO NOTHING'
    )->execute(['employer_liaison', 'placement.board.view']);
    Portal::reset();

    $user = [
        'role' => 'employer_liaison',
        'scope_type' => 'company',
        'scope_value' => 'ACC',
        'active' => 1,
    ];
    $service = new PlacementService($pdo);
    $rows = collect_apps($service->dashboard($user));
    assert_true($rows !== [], 'Custom scoped role should see its assigned company');
    assert_same(['ACC'], array_values(array_unique(array_column($rows, 'company_code'))), 'Scope type should constrain a custom role name');
    $candidate = array_values(array_filter($rows, fn (array $row): bool => ($row['external_id'] ?? '') === 'AC001'))[0] ?? null;
    assert_true($candidate !== null, 'Custom role should see the scoped accommodation fixture');
    assert_same('', $candidate['candidate_tags'] ?? '', 'Sensitive candidate fields should require their capability');
    assert_same('', $candidate['accommodation_notes'] ?? '', 'Accommodation fields should require their separate capability');

    $capability = $pdo->prepare(
        'INSERT INTO role_capabilities (role_key, capability) VALUES (?, ?)
         ON CONFLICT(role_key, capability) DO NOTHING'
    );
    $capability->execute(['employer_liaison', 'placement.sensitive.view']);
    Portal::reset();
    $candidate = array_values(array_filter(
        collect_apps($service->dashboard($user, ['q' => 'AC001'])),
        fn (array $row): bool => ($row['external_id'] ?? '') === 'AC001'
    ))[0] ?? null;
    assert_same('access-cohort', $candidate['candidate_tags'] ?? '', 'A custom role should receive newly granted sensitive visibility');
    assert_same('', $candidate['accommodation_notes'] ?? '', 'Sensitive visibility should not imply accommodation visibility');

    $capability->execute(['employer_liaison', 'placement.accommodation.view']);
    Portal::reset();
    $candidate = array_values(array_filter(
        collect_apps($service->dashboard($user, ['q' => 'AC001'])),
        fn (array $row): bool => ($row['external_id'] ?? '') === 'AC001'
    ))[0] ?? null;
    assert_same('Ground-floor room near lift', $candidate['accommodation_notes'] ?? '', 'Accommodation visibility should follow its capability');
});

test_case('board view presets are role aware and support compact queues', function (): void {
    $service = new PlacementService(Database::connection());
    $mobile = $service->boardViewPresets(['role' => 'mobile']);
    $floor = $service->boardViewPresets(['role' => 'floor']);
    $mobileLabels = array_column($mobile, 'label');
    $floorLabels = array_column($floor, 'label');
    assert_true(in_array('Actionable', $mobileLabels, true), 'Mobile presets should include actionable view');
    assert_true(in_array('Outbound', $mobileLabels, true), 'Mobile presets should include outbound view');
    assert_true(in_array('Arrivals', $floorLabels, true), 'Floor presets should include arrivals view');
    $compactPresets = array_filter([...$mobile, ...$floor], fn (array $preset): bool => !empty($preset['params']['compact']));
    assert_true(count($compactPresets) >= 3, 'Role presets should include compact queues');
});

test_case('board filter form keeps the board route in GET submissions', function (): void {
    $workflow = new Workflow();
    $groups = [];
    foreach (array_keys($workflow->statuses()) as $status) {
        $groups[$status] = ['applications' => []];
    }
    $html = render_view_for_test('board', [
        'user' => ['id' => 1, 'name' => 'Test Admin', 'role' => 'admin'],
        'workflow' => $workflow,
        'groups' => $groups,
        'stats' => [],
        'roleContext' => [],
        'filters' => [],
        'filterOptions' => [
            'statuses' => $workflow->statuses(),
            'companies' => [],
            'flags' => ['' => 'Any flag'],
        ],
        'boardViews' => [],
        'boardCardFields' => [],
        'boardRefreshSeconds' => 0,
        'savedFilters' => [],
        'savedPreference' => [],
        'staleMinutes' => 90,
        'usingSavedFilters' => false,
    ]);

    assert_same(1, substr_count($html, 'name="r" value="board"'), 'Board filter GET form should retain the board route');
});

test_case('board card fields are configurable and normalized', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['board_card_fields', 'candidate_id,company,location,unknown,company']);

    assert_same(['candidate_id', 'company', 'location'], $service->boardCardFields(), 'Board card fields should remove unknown and duplicate keys');
    assert_true(isset($service->boardCardFieldOptions()['accommodation']), 'Board card field options should expose accommodation notes');
    assert_true(isset($service->boardCardFieldOptions()['tags']), 'Board card field options should expose tags');
    assert_true(isset($service->boardCardFieldOptions()['route']), 'Board card field options should expose movement route');
    assert_true(isset($service->boardCardFieldOptions()['custom_fields']), 'Board card field options should expose custom fields');
    assert_same('candidate_id,tags,custom_fields,company', $service->normalizeBoardCardFields(['candidate_id', 'tags', 'custom_fields', 'company', 'bad', 'company']), 'Board card field normalization should produce stable CSV');

    $set->execute(['board_card_fields', 'unknown']);
    assert_true(in_array('program', $service->boardCardFields(), true), 'Invalid board card settings should fall back to defaults');
});

test_case('board refresh setting is normalized and renders only when enabled', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');

    $set->execute(['board_refresh_seconds', '5']);
    assert_same(15, $service->boardRefreshSeconds(), 'Board refresh should clamp low positive values');
    $html = render_layout_for_test(['title' => 'Board', 'content' => '<h1>Board</h1>', 'boardRefreshSeconds' => $service->boardRefreshSeconds()]);
    assert_true(str_contains($html, 'http-equiv="refresh"'), 'Board layout should render refresh meta when enabled');
    assert_true(str_contains($html, 'content="15"'), 'Board layout should render normalized refresh interval');

    $set->execute(['board_refresh_seconds', '0']);
    assert_same(0, $service->boardRefreshSeconds(), 'Board refresh should allow disabled state');
    $disabledHtml = render_layout_for_test(['title' => 'Board', 'content' => '<h1>Board</h1>', 'boardRefreshSeconds' => $service->boardRefreshSeconds()]);
    assert_true(!str_contains($disabledHtml, 'http-equiv="refresh"'), 'Board layout should omit refresh meta when disabled');

    $plainHtml = render_layout_for_test(['title' => 'Records', 'content' => '<h1>Records</h1>']);
    assert_true(!str_contains($plainHtml, 'http-equiv="refresh"'), 'Non-board layout should not refresh without explicit interval');

    $set->execute(['board_refresh_seconds', '45']);
});

test_case('board preferences persist per user and can be cleared', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'admin@test.local'")->fetchColumn();
    $service->saveBoardPreference($adminId, [
        'q' => 'Asha',
        'company' => 'ATLAS',
        'status' => 'scheduled',
        'flag' => 'wanted',
        'actionable' => '1',
        'compact' => '1',
        'stale_minutes' => '45',
    ]);
    $saved = $service->boardPreferenceForUser($adminId);
    assert_same('Asha', $saved['q'], 'Board preference should save search');
    assert_same('ATLAS', $saved['company'], 'Board preference should save company');
    assert_same('scheduled', $saved['status'], 'Board preference should save status');
    assert_same('wanted', $saved['flag'], 'Board preference should save flag');
    assert_same('1', $saved['actionable'], 'Board preference should save actionable');
    assert_same('1', $saved['compact'], 'Board preference should save compact mode');
    assert_same('45', $saved['stale_minutes'], 'Board preference should save stale threshold');
    $service->saveBoardPreference($adminId, ['stale_minutes' => '5000']);
    assert_same('1440', $service->boardPreferenceForUser($adminId)['stale_minutes'], 'Board preference should clamp high stale threshold');
    $service->clearBoardPreference($adminId);
    assert_same([], $service->boardPreferenceForUser($adminId), 'Board preference should clear');
});

test_case('saved board stale threshold controls stale markers and filters', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'admin@test.local'")->fetchColumn();
    $companyId = $service->saveCompany(['code' => 'STH', 'name' => 'Stale Threshold Co'], 1);
    $candidateId = $service->saveCandidate(['external_id' => 'STH001', 'name' => 'Stale Threshold Candidate'], 1);
    $service->saveApplication($candidateId, $companyId, 'scheduled', null, 1);
    $updatedAt = gmdate('Y-m-d H:i:s', time() - 20 * 60);
    $pdo->exec("UPDATE applications SET updated_at = '{$updatedAt}' WHERE candidate_id = {$candidateId} AND company_id = {$companyId}");

    $service->saveBoardPreference($adminId, ['stale_minutes' => '15']);
    $apps = collect_apps($service->dashboard(['id' => $adminId, 'role' => 'admin'], ['company' => 'STH']));
    assert_true(!empty($apps[0]['is_stale']), 'Fifteen-minute preference should mark twenty-minute item stale');
    $staleApps = collect_apps($service->dashboard(['id' => $adminId, 'role' => 'admin'], ['company' => 'STH', 'flag' => 'stale']));
    assert_same(1, count($staleApps), 'Fifteen-minute preference should include row in stale filter');

    $service->saveBoardPreference($adminId, ['stale_minutes' => '60']);
    $apps = collect_apps($service->dashboard(['id' => $adminId, 'role' => 'admin'], ['company' => 'STH']));
    assert_true(empty($apps[0]['is_stale']), 'Sixty-minute preference should not mark twenty-minute item stale');
    $staleApps = collect_apps($service->dashboard(['id' => $adminId, 'role' => 'admin'], ['company' => 'STH', 'flag' => 'stale']));
    assert_same(0, count($staleApps), 'Sixty-minute preference should exclude row from stale filter');
    $service->clearBoardPreference($adminId);
});

test_case('board filters search flags and prioritize urgent queue items', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $companyId = $service->saveCompany(['code' => 'FLT', 'name' => 'Filter Test Co'], 1);
    $firstId = $service->saveCandidate(['external_id' => 'FLT001', 'name' => 'Filter First', 'program' => 'MBA'], 1);
    $urgentId = $service->saveCandidate(['external_id' => 'FLT002', 'name' => 'Filter Urgent', 'program' => 'MBA'], 1);
    $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '1', 'label' => 'Screen'], 1);
    $roundId = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '2', 'label' => 'Panel'], 1);
    $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '1', 'room' => 'Room FLT', 'starts_at' => '12:00', 'ends_at' => '12:30', 'capacity' => '2'], 1);
    $service->saveRoundPanelist(['round_id' => $roundId, 'sequence' => '1', 'name' => 'Filter Panelist', 'role' => 'Lead'], 1);
    $service->saveApplication($firstId, $companyId, 'scheduled', null, 1);
    $service->saveApplication($urgentId, $companyId, 'scheduled', null, 1);
    $urgentAppId = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$urgentId} AND company_id = {$companyId}")->fetchColumn();
    $service->saveApplicationSlotAssignment(['application_id' => $urgentAppId, 'round_schedule_id' => $roundId > 0 ? (int) $pdo->query("SELECT id FROM round_schedules WHERE round_id = {$roundId} LIMIT 1")->fetchColumn() : 0, 'sequence' => '1', 'assignment_status' => 'assigned'], 1);
    $service->createWantedAlert($urgentId, 'priority test', 1);
    $pdo->exec("UPDATE applications SET updated_at = '2000-01-01 00:00:00' WHERE candidate_id = {$firstId}");

    $companyGroups = $service->dashboard(['role' => 'admin'], ['company' => 'FLT']);
    $scheduled = $companyGroups['scheduled']['applications'];
    assert_same('FLT002', $scheduled[0]['external_id'], 'Wanted item should sort first');
    assert_true(!empty($scheduled[1]['is_stale']), 'Older active item should be marked stale');
    assert_true(array_key_exists('process_type', $scheduled[0]), 'Board rows should include company process fields');
    assert_same('1. Screen -> 2. Panel', $scheduled[0]['round_summary'], 'Board rows should include company round summary');
    assert_true(str_contains($scheduled[0]['schedule_summary'], 'Room FLT'), 'Board rows should include schedule summary');
    assert_true(str_contains($scheduled[0]['slot_assignment_summary'], 'Room FLT'), 'Board rows should include assigned slot summary');
    assert_true(str_contains($scheduled[0]['panel_summary'], 'Filter Panelist'), 'Board rows should include panelist summary');
    $candidateTrace = $service->candidate($urgentId);
    assert_true(str_contains($candidateTrace['applications'][0]['slot_assignment_summary'] ?? '', 'Room FLT'), 'Candidate trace should include assigned slot summary');

    $wantedApps = collect_apps($service->dashboard(['role' => 'admin'], ['company' => 'FLT', 'flag' => 'wanted']));
    assert_same(1, count($wantedApps), 'Wanted filter should return one row');
    assert_same('FLT002', $wantedApps[0]['external_id'], 'Wanted filter should return urgent candidate');

    $searchApps = collect_apps($service->dashboard(['role' => 'admin'], ['company' => 'FLT', 'q' => 'First']));
    assert_same(1, count($searchApps), 'Search should return one row');
    assert_same('FLT001', $searchApps[0]['external_id'], 'Search should match candidate name');

    $placementActionable = collect_apps($service->dashboard(['role' => 'placement'], ['company' => 'FLT', 'actionable' => '1']));
    assert_same([], $placementActionable, 'Placement role should not see scheduled rows as actionable');
});

test_case('active company conflicts surface on board and readiness', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $firstCompany = $service->saveCompany(['code' => 'CFA', 'name' => 'Conflict Alpha'], 1);
    $secondCompany = $service->saveCompany(['code' => 'CFB', 'name' => 'Conflict Beta'], 1);
    $terminalCompany = $service->saveCompany(['code' => 'CFC', 'name' => 'Conflict Terminal'], 1);
    $candidateId = $service->saveCandidate(['external_id' => 'CFL001', 'name' => 'Conflict Candidate'], 1);
    $nonConflictCandidateId = $service->saveCandidate(['external_id' => 'CFL002', 'name' => 'Terminal Candidate'], 1);
    $service->saveApplication($candidateId, $firstCompany, 'scheduled', null, 1);
    $service->saveApplication($candidateId, $secondCompany, 'requested', null, 1);
    $service->saveApplication($nonConflictCandidateId, $firstCompany, 'scheduled', null, 1);
    $service->saveApplication($nonConflictCandidateId, $terminalCompany, 'placed', null, 1);

    $conflictApps = collect_apps($service->dashboard(['role' => 'admin'], ['flag' => 'conflict']));
    assert_same(2, count(array_filter($conflictApps, fn (array $app): bool => $app['external_id'] === 'CFL001')), 'Conflict filter should return both active rows for conflicted candidate');
    assert_same(0, count(array_filter($conflictApps, fn (array $app): bool => $app['external_id'] === 'CFL002')), 'Conflict filter should ignore terminal rows');
    foreach ($conflictApps as $app) {
        if ($app['external_id'] !== 'CFL001') {
            continue;
        }
        assert_true(!empty($app['has_active_conflict']), 'Conflict board row should be marked');
        assert_true(str_contains((string) $app['active_company_codes'], 'CFA'), 'Conflict row should include first company code');
        assert_true(str_contains((string) $app['active_company_codes'], 'CFB'), 'Conflict row should include second company code');
    }

    $companyScoped = collect_apps($service->dashboard(['role' => 'company', 'scope_value' => 'CFA'], ['flag' => 'conflict']));
    assert_same(1, count(array_filter($companyScoped, fn (array $app): bool => $app['external_id'] === 'CFL001' && $app['company_code'] === 'CFA')), 'Scoped company user should see only their conflicted row');
    $snapshot = (new ReadinessService($pdo))->snapshot();
    assert_true($snapshot['activeConflicts']['count'] >= 1, 'Readiness should count active company conflicts');
    $conflictRows = array_filter($snapshot['activeConflicts']['rows'], fn (array $row): bool => $row['external_id'] === 'CFL001');
    assert_same(1, count($conflictRows), 'Readiness should include conflicted candidate once');
});

test_case('slot assignment suggestions pick available company schedules without mutating', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $companyId = $service->saveCompany(['code' => 'SUG', 'name' => 'Suggestion Co'], 1);
    $candidateOne = $service->saveCandidate(['external_id' => 'SUG001', 'name' => 'Suggestion One'], 1);
    $candidateTwo = $service->saveCandidate(['external_id' => 'SUG002', 'name' => 'Suggestion Two'], 1);
    $candidateThree = $service->saveCandidate(['external_id' => 'SUG003', 'name' => 'Suggestion Three'], 1);
    $roundId = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '1', 'label' => 'Panel'], 1);
    $scheduleOne = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '1', 'room' => 'Room S1', 'starts_at' => '09:00', 'capacity' => '1'], 1);
    $scheduleTwo = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '2', 'room' => 'Room S2', 'starts_at' => '09:30', 'capacity' => '1'], 1);
    $service->saveApplication($candidateOne, $companyId, 'scheduled', null, 1);
    $service->saveApplication($candidateTwo, $companyId, 'scheduled', null, 1);
    $service->saveApplication($candidateThree, $companyId, 'scheduled', null, 1);
    $assignedApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateOne} AND company_id = {$companyId}")->fetchColumn();
    $service->saveApplicationSlotAssignment(['application_id' => $assignedApp, 'round_schedule_id' => $scheduleOne], 1);

    $suggestions = $service->slotAssignmentSuggestions('SUG');
    assert_same(2, count($suggestions), 'Two unassigned active applications should need suggestions');
    assert_same('SUG002', $suggestions[0]['candidate_external_id'], 'First unassigned candidate should be suggested first');
    assert_same($scheduleTwo, (int) $suggestions[0]['round_schedule_id'], 'First suggestion should use remaining schedule capacity');
    assert_same('Room S2', $suggestions[0]['room'], 'First suggestion should show room');
    assert_same('SUG003', $suggestions[1]['candidate_external_id'], 'Second unassigned candidate should be reported');
    assert_same(null, $suggestions[1]['round_schedule_id'], 'Second suggestion should have no schedule when capacity is full');
    assert_true(str_contains($suggestions[1]['reason'], 'capacity'), 'Second suggestion should explain capacity issue');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM application_slot_assignments asa JOIN applications a ON a.id = asa.application_id JOIN companies co ON co.id = a.company_id WHERE co.code = 'SUG'")->fetchColumn(), 'Suggestions should not write assignments');

    $result = $service->applySlotAssignmentSuggestions('SUG', 1);
    assert_same(1, count($result['assigned']), 'One suggestion should be assignable');
    assert_same(1, count($result['skipped']), 'One suggestion should remain skipped because capacity is full');
    assert_same('SUG002', $result['assigned'][0]['candidate_external_id'], 'Assignable suggestion should be written first');
    assert_same($scheduleTwo, (int) $result['assigned'][0]['round_schedule_id'], 'Applied suggestion should use the available schedule row');
    assert_true((int) ($result['assigned'][0]['assignment_id'] ?? 0) > 0, 'Applied suggestion should report assignment id');
    assert_same('SUG003', $result['skipped'][0]['candidate_external_id'], 'Capacity-constrained suggestion should be skipped');
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM application_slot_assignments asa JOIN applications a ON a.id = asa.application_id JOIN companies co ON co.id = a.company_id WHERE co.code = 'SUG'")->fetchColumn(), 'Apply should create only the available assignment');
    $assignedNote = $pdo->query("SELECT notes FROM application_slot_assignments WHERE id = " . (int) $result['assigned'][0]['assignment_id'])->fetchColumn();
    assert_same('Auto-assigned from slot suggestion.', $assignedNote, 'Applied assignment should record automation note');
    assert_true((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'slot_assignment.apply_suggestions'")->fetchColumn() >= 1, 'Apply should write an audit summary');
});

test_case('slot suggestions respect extended shortlist waitlist priority', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $companyId = $service->saveCompany(['code' => 'EXT', 'name' => 'Extended Shortlist Co'], 1);
    $priorityCandidate = $service->saveCandidate(['external_id' => 'EXT001', 'name' => 'List One Candidate'], 1);
    $laterCandidate = $service->saveCandidate(['external_id' => 'EXT002', 'name' => 'List Two Candidate'], 1);
    $roundId = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '1', 'label' => 'Interview'], 1);
    $slot = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '1', 'room' => 'Room EXT', 'starts_at' => '09:00', 'ends_at' => '09:30', 'capacity' => '1'], 1);

    $service->saveApplication($laterCandidate, $companyId, 'scheduled', 2, 1);
    $service->saveApplication($priorityCandidate, $companyId, 'scheduled', 1, 1);
    $pdo->exec("UPDATE applications SET updated_at = '2000-01-01 00:00:00' WHERE candidate_id = {$laterCandidate}");

    $suggestions = $service->slotAssignmentSuggestions('EXT');
    assert_same(2, count($suggestions), 'Both extended-shortlist candidates should be evaluated');
    assert_same('EXT001', $suggestions[0]['candidate_external_id'], 'List-one candidate should be suggested before older list-two candidate');
    assert_same($slot, (int) $suggestions[0]['round_schedule_id'], 'Scarce slot should go to the higher-priority shortlist rank');
    assert_same('EXT002', $suggestions[1]['candidate_external_id'], 'Lower-priority candidate should still be reported');
    assert_same(null, $suggestions[1]['round_schedule_id'], 'Lower-priority candidate should be blocked when capacity is consumed');
});

test_case('multi-round slot suggestions fill missing ordered rounds after prior assignments', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $companyId = $service->saveCompany(['code' => 'MRO', 'name' => 'Multi Round Co'], 1);
    $candidateOne = $service->saveCandidate(['external_id' => 'MRO001', 'name' => 'Multi Round One'], 1);
    $candidateTwo = $service->saveCandidate(['external_id' => 'MRO002', 'name' => 'Multi Round Two'], 1);
    $roundOne = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '1', 'label' => 'Screen'], 1);
    $roundTwo = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '2', 'label' => 'Final'], 1);
    $screen = $service->saveRoundSchedule(['round_id' => $roundOne, 'sequence' => '1', 'room' => 'MRO Screen', 'starts_at' => '09:00', 'ends_at' => '09:30', 'capacity' => '2'], 1);
    $tooEarlyFinal = $service->saveRoundSchedule(['round_id' => $roundTwo, 'sequence' => '1', 'room' => 'MRO Final Early', 'starts_at' => '09:15', 'ends_at' => '09:45', 'capacity' => '2'], 1);
    $final = $service->saveRoundSchedule(['round_id' => $roundTwo, 'sequence' => '2', 'room' => 'MRO Final', 'starts_at' => '09:45', 'ends_at' => '10:15', 'capacity' => '2'], 1);
    $service->saveApplication($candidateOne, $companyId, 'scheduled', null, 1);
    $service->saveApplication($candidateTwo, $companyId, 'scheduled', null, 1);
    $appOne = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateOne} AND company_id = {$companyId}")->fetchColumn();
    $appTwo = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateTwo} AND company_id = {$companyId}")->fetchColumn();
    $service->saveApplicationSlotAssignment(['application_id' => $appTwo, 'round_schedule_id' => $screen, 'sequence' => '1'], 1);

    $suggestions = $service->slotAssignmentSuggestions('MRO');
    assert_same(3, count($suggestions), 'Planner should suggest all missing rounds across active applications');
    assert_same('MRO001', $suggestions[0]['candidate_external_id'], 'First candidate should get round one first');
    assert_same(1, (int) $suggestions[0]['round_sequence'], 'First suggestion should be round one');
    assert_same($screen, (int) $suggestions[0]['round_schedule_id'], 'Round one should use screen schedule');
    assert_same('MRO001', $suggestions[1]['candidate_external_id'], 'First candidate should then get round two');
    assert_same(2, (int) $suggestions[1]['round_sequence'], 'Second suggestion should be round two');
    assert_same($final, (int) $suggestions[1]['round_schedule_id'], 'Round two should avoid schedule that starts before round one ends');
    assert_true((int) $suggestions[1]['round_schedule_id'] !== $tooEarlyFinal, 'Round two should not use the early overlapping final');
    assert_same('MRO002', $suggestions[2]['candidate_external_id'], 'Second candidate should only need missing round two');
    assert_same(2, (int) $suggestions[2]['round_sequence'], 'Existing round one assignment should be respected');
    assert_same($final, (int) $suggestions[2]['round_schedule_id'], 'Remaining final capacity should be reused safely');

    $result = $service->applySlotAssignmentSuggestions('MRO', 1);
    assert_same(3, count($result['assigned']), 'All three multi-round suggestions should be assignable');
    assert_same(0, count($result['skipped']), 'No multi-round suggestion should be skipped');
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM application_slot_assignments WHERE application_id = {$appOne}")->fetchColumn(), 'First application should now have two round assignments');
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM application_slot_assignments WHERE application_id = {$appTwo}")->fetchColumn(), 'Second application should keep round one and receive round two');
    assert_same(2, (int) $pdo->query("SELECT sequence FROM application_slot_assignments WHERE application_id = {$appOne} AND round_schedule_id = {$final}")->fetchColumn(), 'Round two assignment should carry sequence two');
});

test_case('slot suggestions handle day spillover ordering and conflicts', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = $service->saveCandidate(['external_id' => 'DAY001', 'name' => 'Day Spillover Candidate'], 1);
    $firstCompany = $service->saveCompany(['code' => 'DYA', 'name' => 'Day First Co'], 1);
    $secondCompany = $service->saveCompany(['code' => 'DYB', 'name' => 'Day Second Co'], 1);
    $roundOne = $service->saveCompanyRound(['company_id' => $firstCompany, 'sequence' => '1', 'label' => 'Screen'], 1);
    $roundTwo = $service->saveCompanyRound(['company_id' => $firstCompany, 'sequence' => '2', 'label' => 'Final'], 1);
    $secondRound = $service->saveCompanyRound(['company_id' => $secondCompany, 'sequence' => '1', 'label' => 'Panel'], 1);
    $lateDayOne = $service->saveRoundSchedule(['round_id' => $roundOne, 'sequence' => '1', 'room' => 'Day A Late', 'schedule_day' => '1', 'starts_at' => '17:00', 'ends_at' => '17:30', 'capacity' => '1'], 1);
    $tooEarlyDayOne = $service->saveRoundSchedule(['round_id' => $roundTwo, 'sequence' => '1', 'room' => 'Day A Early', 'schedule_day' => '1', 'starts_at' => '09:00', 'ends_at' => '09:30', 'capacity' => '1'], 1);
    $safeDayTwo = $service->saveRoundSchedule(['round_id' => $roundTwo, 'sequence' => '2', 'room' => 'Day A Next', 'schedule_day' => '2', 'starts_at' => '09:00', 'ends_at' => '09:30', 'capacity' => '1'], 1);
    $conflictingDayOne = $service->saveRoundSchedule(['round_id' => $secondRound, 'sequence' => '1', 'room' => 'Day B Same', 'schedule_day' => '1', 'starts_at' => '17:10', 'ends_at' => '17:40', 'capacity' => '1'], 1);
    $sameClockDayTwo = $service->saveRoundSchedule(['round_id' => $secondRound, 'sequence' => '2', 'room' => 'Day B Next', 'schedule_day' => '2', 'starts_at' => '17:10', 'ends_at' => '17:40', 'capacity' => '1'], 1);
    $service->saveApplication($candidateId, $firstCompany, 'scheduled', null, 1);
    $service->saveApplication($candidateId, $secondCompany, 'scheduled', null, 1);
    $firstApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$firstCompany}")->fetchColumn();
    $service->saveApplicationSlotAssignment(['application_id' => $firstApp, 'round_schedule_id' => $lateDayOne, 'sequence' => '1'], 1);

    $firstCompanySuggestions = array_values(array_filter(
        $service->slotAssignmentSuggestions('DYA'),
        fn (array $row): bool => $row['candidate_external_id'] === 'DAY001'
    ));
    assert_same(1, count($firstCompanySuggestions), 'Day spillover company should produce one missing-round suggestion');
    assert_true((int) $firstCompanySuggestions[0]['round_schedule_id'] !== $tooEarlyDayOne, 'Planner should not route round two to an earlier day-one time');
    assert_same($safeDayTwo, (int) $firstCompanySuggestions[0]['round_schedule_id'], 'Planner should route later round to day two');
    assert_same('2', $firstCompanySuggestions[0]['schedule_day'], 'Suggestion should expose schedule day');

    $secondCompanySuggestions = array_values(array_filter(
        $service->slotAssignmentSuggestions('DYB'),
        fn (array $row): bool => $row['candidate_external_id'] === 'DAY001'
    ));
    assert_same(1, count($secondCompanySuggestions), 'Second day-aware company should produce one suggestion');
    assert_true((int) $secondCompanySuggestions[0]['round_schedule_id'] !== $conflictingDayOne, 'Same-day overlap should remain blocked');
    assert_same($sameClockDayTwo, (int) $secondCompanySuggestions[0]['round_schedule_id'], 'Same clock time on a later day should be safe');
});

test_case('slot suggestions respect operator planner strategy settings', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $companyId = $service->saveCompany(['code' => 'SCO', 'name' => 'Scored Scheduler Co'], 1);
    $roundId = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '1', 'label' => 'Panel'], 1);
    $lateLowLoad = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '1', 'room' => 'Low Load Late', 'starts_at' => '10:00', 'ends_at' => '10:30', 'capacity' => '4'], 1);
    $earlyHighLoad = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '2', 'room' => 'High Load Early', 'starts_at' => '09:00', 'ends_at' => '09:30', 'capacity' => '4'], 1);
    for ($i = 1; $i <= 3; $i++) {
        $fillerId = $service->saveCandidate(['external_id' => sprintf('SCO-F%d', $i), 'name' => "Scored Filler {$i}"], 1);
        $service->saveApplication($fillerId, $companyId, 'scheduled', null, 1);
        $fillerApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$fillerId} AND company_id = {$companyId}")->fetchColumn();
        $service->saveApplicationSlotAssignment(['application_id' => $fillerApp, 'round_schedule_id' => $earlyHighLoad, 'sequence' => '1'], 1);
    }
    $candidateId = $service->saveCandidate(['external_id' => 'SCO001', 'name' => 'Scored Candidate'], 1);
    $service->saveApplication($candidateId, $companyId, 'scheduled', null, 1);

    try {
        $set->execute(['slot_planner_strategy', 'earliest']);
        $earliest = array_values(array_filter(
            $service->slotAssignmentSuggestions('SCO'),
            fn (array $row): bool => $row['candidate_external_id'] === 'SCO001'
        ));
        assert_same(1, count($earliest), 'Earliest strategy should produce one candidate suggestion');
        assert_same($earlyHighLoad, (int) $earliest[0]['round_schedule_id'], 'Earliest strategy should choose the earliest safe row even when it is busier');

        $set->execute(['slot_planner_strategy', 'balanced']);
        $balanced = array_values(array_filter(
            $service->slotAssignmentSuggestions('SCO'),
            fn (array $row): bool => $row['candidate_external_id'] === 'SCO001'
        ));
        assert_same(1, count($balanced), 'Balanced strategy should produce one candidate suggestion');
        assert_same($lateLowLoad, (int) $balanced[0]['round_schedule_id'], 'Balanced strategy should choose the least-loaded safe row');
    } finally {
        $set->execute(['slot_planner_strategy', 'sequence']);
    }
});

test_case('slot suggestions skip paused break and cancelled schedule rows', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $companyId = $service->saveCompany(['code' => 'BRK', 'name' => 'Break Aware Co'], 1);
    $roundId = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '1', 'label' => 'Panel'], 1);
    $pausedSlot = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '1', 'room' => 'Break Room', 'starts_at' => '09:00', 'ends_at' => '09:30', 'capacity' => '1', 'schedule_status' => 'break'], 1);
    $activeSlot = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '2', 'room' => 'Active Room', 'starts_at' => '09:45', 'ends_at' => '10:15', 'capacity' => '1', 'schedule_status' => 'active'], 1);
    $candidateId = $service->saveCandidate(['external_id' => 'BRK001', 'name' => 'Break Candidate'], 1);
    $service->saveApplication($candidateId, $companyId, 'scheduled', null, 1);

    $suggestions = $service->slotAssignmentSuggestions('BRK');
    assert_same(1, count($suggestions), 'Break-aware company should have one suggestion row');
    assert_same($activeSlot, (int) $suggestions[0]['round_schedule_id'], 'Planner should skip break rows and choose active schedule');

    $service->saveRoundSchedule(['id' => $activeSlot, 'round_id' => $roundId, 'sequence' => '2', 'room' => 'Active Room', 'starts_at' => '09:45', 'ends_at' => '10:15', 'capacity' => '1', 'schedule_status' => 'paused'], 1);
    $blocked = $service->slotAssignmentSuggestions('BRK');
    assert_same(null, $blocked[0]['round_schedule_id'], 'Planner should not assign when every row is inactive');
    assert_true(str_contains($blocked[0]['reason'], 'paused, on break, or cancelled'), 'Blocked row should explain inactive schedules');

    $service->saveRoundSchedule(['id' => $pausedSlot, 'round_id' => $roundId, 'sequence' => '1', 'room' => 'Break Room', 'starts_at' => '09:00', 'ends_at' => '09:30', 'capacity' => '1', 'schedule_status' => 'active'], 1);
    $recovered = $service->slotAssignmentSuggestions('BRK');
    assert_same($pausedSlot, (int) $recovered[0]['round_schedule_id'], 'Reactivating a break row should make it schedulable again');
});

test_case('slot suggestions respect company schedule deadlines', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $companyId = $service->saveCompany([
        'code' => 'DDL',
        'name' => 'Deadline Co',
        'deadline_day' => '1',
        'deadline_at' => '10:00',
    ], 1);
    $roundId = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '1', 'label' => 'Panel'], 1);
    $lateSlot = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '1', 'room' => 'Late Deadline Room', 'schedule_day' => '1', 'starts_at' => '09:45', 'ends_at' => '10:15', 'capacity' => '1'], 1);
    $safeSlot = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '2', 'room' => 'Safe Deadline Room', 'schedule_day' => '1', 'starts_at' => '09:20', 'ends_at' => '09:50', 'capacity' => '1'], 1);
    $candidateId = $service->saveCandidate(['external_id' => 'DDL001', 'name' => 'Deadline Candidate'], 1);
    $service->saveApplication($candidateId, $companyId, 'scheduled', null, 1);

    $suggestions = $service->slotAssignmentSuggestions('DDL');
    assert_same(1, count($suggestions), 'Deadline company should have one suggestion row');
    assert_same($safeSlot, (int) $suggestions[0]['round_schedule_id'], 'Planner should skip rows that end after the company deadline');
    assert_true((int) $suggestions[0]['round_schedule_id'] !== $lateSlot, 'Planner should not choose the sequence-first late row');

    $service->saveRoundSchedule(['id' => $safeSlot, 'round_id' => $roundId, 'sequence' => '2', 'room' => 'Safe Deadline Room', 'schedule_day' => '1', 'starts_at' => '09:20', 'ends_at' => '10:05', 'capacity' => '1'], 1);
    $blocked = $service->slotAssignmentSuggestions('DDL');
    assert_same(null, $blocked[0]['round_schedule_id'], 'Planner should block when every row exceeds the deadline');
    assert_true(str_contains($blocked[0]['reason'], 'deadline'), 'Blocked row should explain the company deadline');

    $optimizedBlocked = $service->optimizedSlotAssignmentSuggestions('DDL');
    assert_same(null, $optimizedBlocked[0]['round_schedule_id'], 'Optimized planner should also respect company deadlines');
    assert_true(str_contains($optimizedBlocked[0]['reason'], 'deadline'), 'Optimized blocked row should explain the company deadline');
});

test_case('slot suggestions respect panelist availability breaks', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $companyId = $service->saveCompany(['code' => 'PNL', 'name' => 'Panel Availability Co'], 1);
    $roundId = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '1', 'label' => 'Panel'], 1);
    $slotId = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '1', 'room' => 'Panel Room', 'starts_at' => '09:00', 'ends_at' => '09:30', 'capacity' => '2'], 1);
    $candidateId = $service->saveCandidate(['external_id' => 'PNL001', 'name' => 'Panel Availability Candidate'], 1);
    $service->saveApplication($candidateId, $companyId, 'scheduled', null, 1);
    $service->saveRoundPanelist(['round_id' => $roundId, 'sequence' => '1', 'name' => 'Paused Panelist', 'availability_status' => 'break'], 1);

    $blocked = $service->slotAssignmentSuggestions('PNL');
    assert_same(1, count($blocked), 'Panel availability company should have one suggestion row');
    assert_same(null, $blocked[0]['round_schedule_id'], 'Planner should not assign when every configured panelist is away');
    assert_true(str_contains($blocked[0]['reason'], 'panelists'), 'Blocked row should explain panelist availability');

    $optimizedBlocked = $service->optimizedSlotAssignmentSuggestions('PNL');
    assert_same(null, $optimizedBlocked[0]['round_schedule_id'], 'Optimized planner should also respect panelist availability');
    assert_true(str_contains($optimizedBlocked[0]['reason'], 'panelists'), 'Optimized blocked row should explain panelist availability');

    $service->saveRoundPanelist(['round_id' => $roundId, 'sequence' => '2', 'name' => 'Active Panelist', 'availability_status' => 'active'], 1);
    $available = $service->slotAssignmentSuggestions('PNL');
    assert_same($slotId, (int) $available[0]['round_schedule_id'], 'Any active configured panelist should unblock slot suggestions');

    $optimizedAvailable = $service->optimizedSlotAssignmentSuggestions('PNL');
    assert_same($slotId, (int) $optimizedAvailable[0]['round_schedule_id'], 'Optimized planner should schedule when an active panelist exists');
});

test_case('optimized slot suggestions improve constrained global assignment across companies', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $firstCompany = $service->saveCompany(['code' => 'OPA', 'name' => 'Optimizer Company A'], 1);
    $secondCompany = $service->saveCompany(['code' => 'OPB', 'name' => 'Optimizer Company B'], 1);
    $multiCandidate = $service->saveCandidate(['external_id' => 'OPT001', 'name' => 'Multi Company Optimized'], 1);
    $singleCandidate = $service->saveCandidate(['external_id' => 'OPT002', 'name' => 'Single Company Optimized'], 1);
    $roundA = $service->saveCompanyRound(['company_id' => $firstCompany, 'sequence' => '1', 'label' => 'Panel'], 1);
    $roundB = $service->saveCompanyRound(['company_id' => $secondCompany, 'sequence' => '1', 'label' => 'Panel'], 1);
    $slotA = $service->saveRoundSchedule(['round_id' => $roundA, 'sequence' => '1', 'room' => 'Optimizer A', 'starts_at' => '09:00', 'ends_at' => '09:30', 'capacity' => '1'], 1);
    $slotB = $service->saveRoundSchedule(['round_id' => $roundB, 'sequence' => '1', 'room' => 'Optimizer B', 'starts_at' => '09:00', 'ends_at' => '09:30', 'capacity' => '1'], 1);
    $service->saveApplication($multiCandidate, $firstCompany, 'scheduled', null, 1);
    $service->saveApplication($singleCandidate, $firstCompany, 'scheduled', null, 1);
    $service->saveApplication($multiCandidate, $secondCompany, 'scheduled', null, 1);
    $regular = array_values(array_filter(
        $service->slotAssignmentSuggestions(),
        fn (array $row): bool => in_array($row['company_code'], ['OPA', 'OPB'], true)
    ));
    $optimized = array_values(array_filter(
        $service->optimizedSlotAssignmentSuggestions(),
        fn (array $row): bool => in_array($row['company_code'], ['OPA', 'OPB'], true)
    ));
    assert_same(1, count(array_filter($regular, fn (array $row): bool => (int) ($row['round_schedule_id'] ?? 0) > 0)), 'Regular company-order planner should only find one safe assignment');
    assert_same(2, count(array_filter($optimized, fn (array $row): bool => (int) ($row['round_schedule_id'] ?? 0) > 0)), 'Optimized global planner should find two safe assignments');
    $optimizedByCandidateCompany = [];
    foreach ($optimized as $row) {
        $optimizedByCandidateCompany[$row['candidate_external_id'] . '/' . $row['company_code']] = $row;
    }
    assert_same($slotA, (int) $optimizedByCandidateCompany['OPT002/OPA']['round_schedule_id'], 'Single-company candidate should receive the scarce company A slot');
    assert_same($slotB, (int) $optimizedByCandidateCompany['OPT001/OPB']['round_schedule_id'], 'Multi-company candidate should receive the company B slot');
    assert_same(null, $optimizedByCandidateCompany['OPT001/OPA']['round_schedule_id'], 'Multi-company candidate should skip the conflicting company A slot');

    $result = $service->applyOptimizedSlotAssignmentSuggestions('', 1);
    $optimizedRows = array_values(array_filter(
        [...$result['assigned'], ...$result['skipped']],
        fn (array $row): bool => in_array($row['company_code'], ['OPA', 'OPB'], true)
    ));
    assert_same(2, count(array_filter($optimizedRows, fn (array $row): bool => ($row['result'] ?? '') === 'assigned')), 'Optimized apply should write two safe assignments');
    assert_same(1, count(array_filter($optimizedRows, fn (array $row): bool => ($row['result'] ?? '') === 'skipped')), 'Optimized apply should report the remaining impossible row');
});

test_case('optimized slot suggestions use bounded exact search for small one-round scopes', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['slot_optimizer_exact_limit', '10']);
    $companyId = $service->saveCompany(['code' => 'EXA', 'name' => 'Exact Scope Co'], 1);
    $roundId = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '1', 'label' => 'Panel'], 1);
    $firstSlot = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '1', 'room' => 'Exact A', 'starts_at' => '09:00', 'ends_at' => '09:20', 'capacity' => '1'], 1);
    $secondSlot = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '2', 'room' => 'Exact B', 'starts_at' => '09:30', 'ends_at' => '09:50', 'capacity' => '1'], 1);
    foreach ([1, 2] as $index) {
        $candidateId = $service->saveCandidate(['external_id' => sprintf('EXA%03d', $index), 'name' => "Exact Candidate {$index}"], 1);
        $service->saveApplication($candidateId, $companyId, 'scheduled', null, 1);
    }

    $suggestions = $service->optimizedSlotAssignmentSuggestions('EXA');
    assert_same(2, count($suggestions), 'Small scoped company should have two suggestions');
    assert_same(2, count(array_filter($suggestions, fn (array $row): bool => (int) ($row['round_schedule_id'] ?? 0) > 0)), 'Exact scoped optimizer should fill both available slots');
    assert_same($firstSlot, (int) $suggestions[0]['round_schedule_id'], 'Exact scoped optimizer should keep deterministic schedule order');
    assert_same($secondSlot, (int) $suggestions[1]['round_schedule_id'], 'Exact scoped optimizer should use remaining safe capacity');
    assert_true(str_contains($suggestions[0]['reason'], 'Bounded exact optimized'), 'Small one-round scope should use the bounded exact optimizer');
});

test_case('optimized slot suggestions use bounded exact multi-round search for small scopes', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['slot_optimizer_exact_limit', '10']);
    $companyId = $service->saveCompany(['code' => 'FRN', 'name' => 'Frontier Scope Co'], 1);
    $firstRound = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '1', 'label' => 'Screen'], 1);
    $secondRound = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '2', 'label' => 'Panel'], 1);
    $firstSlot = $service->saveRoundSchedule(['round_id' => $firstRound, 'sequence' => '1', 'room' => 'Frontier A', 'starts_at' => '09:00', 'ends_at' => '09:20', 'capacity' => '1'], 1);
    $secondSlot = $service->saveRoundSchedule(['round_id' => $secondRound, 'sequence' => '1', 'room' => 'Frontier B', 'starts_at' => '09:30', 'ends_at' => '09:50', 'capacity' => '1'], 1);
    $candidateId = $service->saveCandidate(['external_id' => 'FRN001', 'name' => 'Frontier Candidate'], 1);
    $service->saveApplication($candidateId, $companyId, 'scheduled', null, 1);

    $suggestions = $service->optimizedSlotAssignmentSuggestions('FRN');
    assert_same(2, count($suggestions), 'Multi-round frontier scope should suggest each missing round');
    assert_same($firstSlot, (int) $suggestions[0]['round_schedule_id'], 'Frontier optimizer should assign the first ordered round');
    assert_same($secondSlot, (int) $suggestions[1]['round_schedule_id'], 'Frontier optimizer should then assign the second ordered round');
    assert_true(str_contains($suggestions[0]['reason'], 'Bounded exact multi-round'), 'Small multi-round scope should use the bounded exact multi-round optimizer');
    assert_true(str_contains($suggestions[1]['reason'], 'Bounded exact multi-round'), 'Each small multi-round row should be labeled as exact multi-round optimized');
});

test_case('bounded exact multi-round optimizer chooses first-round timing that unlocks later rounds', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['slot_optimizer_exact_limit', '10']);
    $companyId = $service->saveCompany(['code' => 'MRX', 'name' => 'Multi Round Exact Co'], 1);
    $firstRound = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '1', 'label' => 'Screen'], 1);
    $secondRound = $service->saveCompanyRound(['company_id' => $companyId, 'sequence' => '2', 'label' => 'Panel'], 1);
    $lateFirst = $service->saveRoundSchedule(['round_id' => $firstRound, 'sequence' => '1', 'room' => 'Late Screen', 'starts_at' => '10:00', 'ends_at' => '10:20', 'capacity' => '1'], 1);
    $earlyFirst = $service->saveRoundSchedule(['round_id' => $firstRound, 'sequence' => '2', 'room' => 'Early Screen', 'starts_at' => '09:00', 'ends_at' => '09:20', 'capacity' => '1'], 1);
    $secondSlot = $service->saveRoundSchedule(['round_id' => $secondRound, 'sequence' => '1', 'room' => 'Panel Room', 'starts_at' => '09:30', 'ends_at' => '09:50', 'capacity' => '1'], 1);
    $candidateId = $service->saveCandidate(['external_id' => 'MRX001', 'name' => 'Multi Round Exact Candidate'], 1);
    $service->saveApplication($candidateId, $companyId, 'scheduled', null, 1);

    $suggestions = $service->optimizedSlotAssignmentSuggestions('MRX');
    assert_same(2, count($suggestions), 'Exact multi-round optimizer should fill both ordered rounds');
    assert_same($earlyFirst, (int) $suggestions[0]['round_schedule_id'], 'Exact multi-round optimizer should choose the first-round time that enables the later round');
    assert_true((int) $suggestions[0]['round_schedule_id'] !== $lateFirst, 'Exact multi-round optimizer should skip the sequence-first late row when it blocks a later round');
    assert_same($secondSlot, (int) $suggestions[1]['round_schedule_id'], 'Exact multi-round optimizer should then assign the later round');
    assert_true(str_contains($suggestions[0]['reason'], 'Bounded exact multi-round'), 'Suggestion should identify the multi-round exact optimizer');
});

test_case('slot suggestions avoid candidate cross-company conflicts with configured buffer', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['scheduling_buffer_minutes', '15']);
    try {
        $candidateId = $service->saveCandidate(['external_id' => 'BUF001', 'name' => 'Buffered Candidate'], 1);
        $firstCompany = $service->saveCompany(['code' => 'AAA', 'name' => 'Buffer First'], 1);
        $secondCompany = $service->saveCompany(['code' => 'AAB', 'name' => 'Buffer Second'], 1);
        $blockedCompany = $service->saveCompany(['code' => 'AAC', 'name' => 'Buffer Blocked'], 1);
        $firstRound = $service->saveCompanyRound(['company_id' => $firstCompany, 'sequence' => '1', 'label' => 'Panel'], 1);
        $secondRound = $service->saveCompanyRound(['company_id' => $secondCompany, 'sequence' => '1', 'label' => 'Panel'], 1);
        $blockedRound = $service->saveCompanyRound(['company_id' => $blockedCompany, 'sequence' => '1', 'label' => 'Panel'], 1);
        $firstSlot = $service->saveRoundSchedule(['round_id' => $firstRound, 'sequence' => '1', 'room' => 'Buffer A', 'starts_at' => '09:00', 'ends_at' => '09:30', 'capacity' => '1'], 1);
        $tooCloseSlot = $service->saveRoundSchedule(['round_id' => $secondRound, 'sequence' => '1', 'room' => 'Buffer B Too Close', 'starts_at' => '09:35', 'ends_at' => '10:00', 'capacity' => '1'], 1);
        $safeSlot = $service->saveRoundSchedule(['round_id' => $secondRound, 'sequence' => '2', 'room' => 'Buffer B Safe', 'starts_at' => '09:50', 'ends_at' => '10:15', 'capacity' => '1'], 1);
        $blockedSlot = $service->saveRoundSchedule(['round_id' => $blockedRound, 'sequence' => '1', 'room' => 'Buffer C Blocked', 'starts_at' => '10:20', 'ends_at' => '10:40', 'capacity' => '1'], 1);
        $service->saveApplication($candidateId, $firstCompany, 'scheduled', null, 1);
        $service->saveApplication($candidateId, $secondCompany, 'scheduled', null, 1);
        $service->saveApplication($candidateId, $blockedCompany, 'scheduled', null, 1);

        $candidateSuggestions = array_values(array_filter(
            $service->slotAssignmentSuggestions(),
            fn (array $row): bool => $row['candidate_external_id'] === 'BUF001'
        ));
        assert_same(3, count($candidateSuggestions), 'Candidate should receive one suggestion row per active company');
        assert_same('AAA', $candidateSuggestions[0]['company_code'], 'Planner should handle first company first');
        assert_same($firstSlot, (int) $candidateSuggestions[0]['round_schedule_id'], 'First company should get the first slot');
        assert_same('AAB', $candidateSuggestions[1]['company_code'], 'Planner should then evaluate second company');
        assert_true((int) $candidateSuggestions[1]['round_schedule_id'] !== $tooCloseSlot, 'Second company should avoid the slot inside the 15 minute buffer');
        assert_same($safeSlot, (int) $candidateSuggestions[1]['round_schedule_id'], 'Second company should use the later safe slot');
        assert_same('AAC', $candidateSuggestions[2]['company_code'], 'Planner should still report blocked companies');
        assert_same(null, $candidateSuggestions[2]['round_schedule_id'], 'Third company should be skipped when every row conflicts');
        assert_true(str_contains($candidateSuggestions[2]['reason'], '15 minute buffer'), 'Blocked row should explain the configured buffer');
        assert_true((int) $blockedSlot > 0, 'Blocked slot should exist to make the conflict explicit');
    } finally {
        $set->execute(['scheduling_buffer_minutes', '0']);
    }
});

test_case('dry-run exception scenarios cover rejection choice non-shortlist and bottlenecks', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);

    $requestedCompany = $service->saveCompany(['code' => 'NLS', 'name' => 'Non Shortlist Co'], 1);
    $requestedCandidate = $service->saveCandidate(['external_id' => 'NLS001', 'name' => 'Late Requested Candidate'], 1);
    assert_true(!$service->applicationExists($requestedCandidate, $requestedCompany), 'Candidate should start outside company shortlist');
    $service->saveApplication($requestedCandidate, $requestedCompany, 'inside', 1, 1);
    assert_true($service->applicationExists($requestedCandidate, $requestedCompany), 'Company request should create an application for a non-shortlisted candidate');
    $requestedApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$requestedCandidate} AND company_id = {$requestedCompany}")->fetchColumn();
    $roundId = $service->saveCompanyRound(['company_id' => $requestedCompany, 'sequence' => '1', 'label' => 'Late Panel', 'room' => 'Room NLS'], 1);
    $scheduleId = $service->saveRoundSchedule(['round_id' => $roundId, 'sequence' => '1', 'room' => 'Room NLS', 'starts_at' => '13:00', 'ends_at' => '13:30', 'capacity' => '1'], 1);
    $slotId = $service->saveApplicationSlotAssignment(['application_id' => $requestedApp, 'round_schedule_id' => $scheduleId, 'sequence' => '1'], 1);
    $service->returnToIdle($requestedApp, 1, 'company', 'company_rejected', 'Panel declined after late request.', 'inside');
    assert_same('idle', $pdo->query("SELECT current_status FROM applications WHERE id = {$requestedApp}")->fetchColumn(), 'Rejected candidate should return to idle');
    assert_same('CP', $pdo->query("SELECT current_location FROM candidates WHERE id = {$requestedCandidate}")->fetchColumn(), 'Returned candidate should go back to control room');
    assert_same('cancelled', $pdo->query("SELECT assignment_status FROM application_slot_assignments WHERE id = {$slotId}")->fetchColumn(), 'Return should cancel active slot assignments');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM events WHERE application_id = {$requestedApp} AND from_status = 'inside' AND to_status = 'idle' AND note LIKE 'Returned to idle: company_rejected.%'")->fetchColumn(), 'Return should write an event with reason');
    assert_true((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'application.return_to_idle' AND subject_id = {$requestedApp}")->fetchColumn() >= 1, 'Return should write an audit entry');

    $staleCandidate = $service->saveCandidate(['external_id' => 'DRY-STL', 'name' => 'Stale Exception Candidate'], 1);
    $staleCompany = $service->saveCompany(['code' => 'DST', 'name' => 'Dry Stale Co'], 1);
    $service->saveApplication($staleCandidate, $staleCompany, 'scheduled', null, 1);
    $staleApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$staleCandidate} AND company_id = {$staleCompany}")->fetchColumn();
    try {
        $service->returnToIdle($staleApp, 1, 'control', 'candidate_unavailable', 'Wrong stale status.', 'requested');
        throw new RuntimeException('Expected stale exception return failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'board card is stale'), 'Return should use stale-card guard');
    }
    assert_same('scheduled', $pdo->query("SELECT current_status FROM applications WHERE id = {$staleApp}")->fetchColumn(), 'Stale return should not mutate application');

    $choiceOne = $service->saveCompany(['code' => 'CHO', 'name' => 'Choice Co'], 1);
    $choiceTwo = $service->saveCompany(['code' => 'CHX', 'name' => 'Choice Other Co'], 1);
    $choiceCandidate = $service->saveCandidate(['external_id' => 'CHO001', 'name' => 'Choice Candidate'], 1);
    $service->saveApplication($choiceCandidate, $choiceOne, 'sent', null, 1);
    $service->saveApplication($choiceCandidate, $choiceTwo, 'requested', null, 1);
    $service->createPreferenceRequest($choiceCandidate, [$choiceOne, $choiceTwo], 1, 'candidate chooses company');
    $choiceRequest = (int) $pdo->query('SELECT id FROM preference_requests ORDER BY id DESC LIMIT 1')->fetchColumn();
    $service->resolvePreferenceRequest($choiceRequest, $choiceOne, 1);
    $choiceConflictRows = collect_apps($service->dashboard(['role' => 'admin'], ['flag' => 'conflict']));
    assert_same(2, count(array_filter($choiceConflictRows, fn (array $app): bool => $app['external_id'] === 'CHO001')), 'Choice scenario should start as active conflict');
    $choiceSecondApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$choiceCandidate} AND company_id = {$choiceTwo}")->fetchColumn();
    $service->returnToIdle($choiceSecondApp, 1, 'placement', 'candidate_chose_other', 'Preference resolved to CHO.', 'requested');
    $choiceConflictRows = collect_apps($service->dashboard(['role' => 'admin'], ['flag' => 'conflict']));
    assert_same(0, count(array_filter($choiceConflictRows, fn (array $app): bool => $app['external_id'] === 'CHO001')), 'Returning losing choice should clear active conflict');

    $bottleneckCompany = $service->saveCompany(['code' => 'BOT', 'name' => 'Bottleneck Co'], 1);
    $botRound = $service->saveCompanyRound(['company_id' => $bottleneckCompany, 'sequence' => '1', 'label' => 'GD Panel', 'room' => 'Room BOT'], 1);
    $botSchedule = $service->saveRoundSchedule(['round_id' => $botRound, 'sequence' => '1', 'room' => 'Room BOT', 'starts_at' => '14:00', 'ends_at' => '14:30', 'capacity' => '1'], 1);
    $botOne = $service->saveCandidate(['external_id' => 'BOT001', 'name' => 'Bottleneck One'], 1);
    $botTwo = $service->saveCandidate(['external_id' => 'BOT002', 'name' => 'Bottleneck Two'], 1);
    $service->saveApplication($botOne, $bottleneckCompany, 'scheduled', null, 1);
    $service->saveApplication($botTwo, $bottleneckCompany, 'scheduled', null, 1);
    $botOneApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$botOne} AND company_id = {$bottleneckCompany}")->fetchColumn();
    $service->saveApplicationSlotAssignment(['application_id' => $botOneApp, 'round_schedule_id' => $botSchedule, 'sequence' => '1'], 1);
    $botSuggestions = array_values(array_filter(
        $service->slotAssignmentSuggestions('BOT'),
        fn (array $row): bool => $row['candidate_external_id'] === 'BOT002'
    ));
    assert_true(str_contains($botSuggestions[0]['reason'] ?? '', 'capacity'), 'Full panel should surface a bottleneck');
    $service->saveRoundSchedule(['id' => $botSchedule, 'round_id' => $botRound, 'sequence' => '1', 'room' => 'Room BOT', 'starts_at' => '14:00', 'ends_at' => '14:30', 'capacity' => '2'], 1);
    $botSuggestions = array_values(array_filter(
        $service->slotAssignmentSuggestions('BOT'),
        fn (array $row): bool => $row['candidate_external_id'] === 'BOT002'
    ));
    assert_true((int) ($botSuggestions[0]['round_schedule_id'] ?? 0) === $botSchedule, 'Increasing GD capacity should unblock slot suggestion');

    $pauseCompany = $service->saveCompany(['code' => 'PAU', 'name' => 'Paused Panels Co', 'max_active' => '1'], 1);
    $pauseOne = $service->saveCandidate(['external_id' => 'PAU001', 'name' => 'Paused One'], 1);
    $pauseTwo = $service->saveCandidate(['external_id' => 'PAU002', 'name' => 'Paused Two'], 1);
    $service->saveApplication($pauseOne, $pauseCompany, 'inside', null, 1);
    $service->saveApplication($pauseTwo, $pauseCompany, 'inside', null, 1);
    $pauseAlertRows = array_values(array_filter(
        (new ReadinessService($pdo))->snapshot()['capacityAlerts']['rows'],
        fn (array $row): bool => $row['code'] === 'PAU'
    ));
    assert_same(1, count($pauseAlertRows), 'Paused company should surface active-capacity warning');
    $pauseSecondApp = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$pauseTwo} AND company_id = {$pauseCompany}")->fetchColumn();
    $service->returnToIdle($pauseSecondApp, 1, 'control', 'process_pause', 'Company paused panels.', 'inside');
    $pauseAlertRows = array_values(array_filter(
        (new ReadinessService($pdo))->snapshot()['capacityAlerts']['rows'],
        fn (array $row): bool => $row['code'] === 'PAU'
    ));
    assert_same(0, count($pauseAlertRows), 'Returning one paused-panel candidate should clear capacity warning');
});

test_case('preference request can be created and resolved', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = (int) $pdo->query("SELECT id FROM candidates WHERE external_id = 'C001'")->fetchColumn();
    $companyIds = $pdo->query("SELECT id FROM companies ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $service->createPreferenceRequest($candidateId, $companyIds, 1, 'test preference');
    $requestId = (int) $pdo->query('SELECT id FROM preference_requests ORDER BY id DESC LIMIT 1')->fetchColumn();
    assert_true($requestId > 0, 'Preference request should be created');
    $service->resolvePreferenceRequest($requestId, (int) $companyIds[0], 1);
    $status = $pdo->query("SELECT status FROM preference_requests WHERE id = {$requestId}")->fetchColumn();
    assert_same('resolved', $status, 'Preference request should resolve');
});

test_case('wanted alerts can be created and resolved', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = (int) $pdo->query("SELECT id FROM candidates WHERE external_id = 'C002'")->fetchColumn();
    $service->createWantedAlert($candidateId, 'Needed at panel', 1);
    $alertId = (int) $pdo->query('SELECT id FROM wanted_alerts ORDER BY id DESC LIMIT 1')->fetchColumn();
    assert_true(($service->openWantedAlertsByCandidate()[$candidateId] ?? 0) === 1, 'Wanted alert should be open');
    $service->resolveWantedAlert($alertId, 1);
    assert_true(!isset($service->openWantedAlertsByCandidate()[$candidateId]), 'Wanted alert should resolve');
});

test_case('in-app notifications follow wanted and preference workflows', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'admin@test.local'")->fetchColumn();
    $controlId = (int) $pdo->query("SELECT id FROM users WHERE email = 'control@example.test'")->fetchColumn();
    $candidateId = (int) $pdo->query("SELECT id FROM candidates WHERE external_id = 'C003'")->fetchColumn();
    $companyIds = $pdo->query("SELECT id FROM companies ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);

    $service->createWantedAlert($candidateId, 'notification wanted', $adminId);
    $alertId = (int) $pdo->query('SELECT id FROM wanted_alerts ORDER BY id DESC LIMIT 1')->fetchColumn();
    assert_same(3, (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE source_type = 'wanted_alert' AND source_id = {$alertId} AND status = 'open'")->fetchColumn(), 'Wanted alert should notify control, floor, and mobile roles');
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE source_type = 'wanted_alert' AND source_id = {$alertId} AND recipient_role = 'floor' AND status = 'open'")->fetchColumn(), 'Floor role should see wanted notification');
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE source_type = 'wanted_alert' AND source_id = {$alertId} AND recipient_role = 'placement'")->fetchColumn(), 'Placement role should not see wanted notification');

    $controlNotificationId = (int) $pdo->query("SELECT id FROM notifications WHERE source_type = 'wanted_alert' AND source_id = {$alertId} AND recipient_role = 'control'")->fetchColumn();
    $service->acknowledgeNotification($controlNotificationId, ['id' => $controlId, 'role' => 'control']);
    assert_same('acknowledged', $pdo->query("SELECT status FROM notifications WHERE id = {$controlNotificationId}")->fetchColumn(), 'Control notification should acknowledge');
    try {
        $floorNotificationId = (int) $pdo->query("SELECT id FROM notifications WHERE source_type = 'wanted_alert' AND source_id = {$alertId} AND recipient_role = 'floor'")->fetchColumn();
        $service->acknowledgeNotification($floorNotificationId, ['id' => $adminId, 'role' => 'auditor']);
        throw new RuntimeException('Expected auditor notification failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'cannot acknowledge notifications'), 'Expected capability-based acknowledgement block');
    }
    $service->resolveWantedAlert($alertId, $adminId);
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE source_type = 'wanted_alert' AND source_id = {$alertId} AND status = 'open'")->fetchColumn(), 'Resolving wanted alert should close source notifications');

    $service->createPreferenceRequest($candidateId, $companyIds, $adminId, 'notification preference');
    $requestId = (int) $pdo->query('SELECT id FROM preference_requests ORDER BY id DESC LIMIT 1')->fetchColumn();
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE source_type = 'preference_request' AND source_id = {$requestId} AND status = 'open'")->fetchColumn(), 'Preference request should notify control and placement roles');
    assert_true($service->notificationCountForUser(['role' => 'placement']) >= 1, 'Placement role should see preference notification');
    $service->resolvePreferenceRequest($requestId, (int) $companyIds[0], $adminId);
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE source_type = 'preference_request' AND source_id = {$requestId} AND status = 'open'")->fetchColumn(), 'Resolving preference should close source notifications');
});

test_case('optional external notification delivery queues and writes jsonl outbox', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $outbox = sys_get_temp_dir() . '/cpe-notification-outbox-' . bin2hex(random_bytes(4)) . '.jsonl';
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['notification_delivery_channels', 'file']);
    $set->execute(['notification_file_outbox_path', $outbox]);
    putenv('CPE_NOTIFICATION_FILE_OUTBOX_PATH=' . $outbox);
    try {
        $candidateId = $service->saveCandidate(['external_id' => 'NDL001', 'name' => 'Notification Delivery Candidate'], 1);
        $service->createWantedAlert($candidateId, 'external delivery smoke', 1);
        $alertId = (int) $pdo->query('SELECT id FROM wanted_alerts ORDER BY id DESC LIMIT 1')->fetchColumn();
        assert_same(3, (int) $pdo->query("SELECT COUNT(*) FROM notification_deliveries nd JOIN notifications n ON n.id = nd.notification_id WHERE n.source_type = 'wanted_alert' AND n.source_id = {$alertId} AND nd.channel = 'file' AND nd.status = 'queued'")->fetchColumn(), 'Wanted alert should queue file deliveries for role notifications');

        $dryRun = (new NotificationDeliveryService($pdo))->deliverPending('file', 10, true);
        assert_same(3, $dryRun['checked'], 'Dry run should find queued deliveries');
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'delivered'")->fetchColumn(), 'Dry run should not mark deliveries');

        $result = (new NotificationDeliveryService($pdo))->deliverPending('file');
        assert_same(3, $result['delivered'], 'File delivery should deliver queued rows');
        assert_same(0, $result['failed'], 'File delivery should not fail');
        assert_true(is_file($outbox), 'Outbox file should be written');
        $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents($outbox)))));
        assert_same(3, count($lines), 'Outbox should contain one JSON line per role notification');
        assert_true(str_contains($lines[0], 'Wanted: NDL001'), 'Outbox payload should include notification subject');
        $status = (new ReadinessService($pdo))->snapshot()['notificationDeliveries'];
        assert_true($status['delivered'] >= 3, 'Readiness should report delivered external notifications');
        assert_same(0, $status['queued'] + $status['failed'], 'Readiness should have no queued or failed delivery warnings after file delivery');
    } finally {
        putenv('CPE_NOTIFICATION_FILE_OUTBOX_PATH');
        $set->execute(['notification_delivery_channels', '']);
        $set->execute(['notification_file_outbox_path', '']);
        if (is_file($outbox)) {
            unlink($outbox);
        }
    }
});

test_case('tenant-configured notification files cannot escape the data directory', function (): void {
    $pdo = Database::connection();
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['notification_delivery_channels', 'file']);
    $set->execute(['notification_file_outbox_path', sys_get_temp_dir() . '/outside-data.jsonl']);
    putenv('CPE_NOTIFICATION_FILE_OUTBOX_PATH');
    try {
        $notification = $pdo->prepare(
            'INSERT INTO notifications
             (recipient_role, channel, template_key, subject, body, status, source_type, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $notification->execute(['admin', 'in_app', 'path_contract', 'Path contract', 'Path contract', 'open', 'path_contract', cpe_now()]);
        $notificationId = Database::lastInsertId($pdo);
        (new NotificationDeliveryService($pdo))->queueForNotification($notificationId);
        $result = (new NotificationDeliveryService($pdo))->deliverPending('file', 1, false);
        assert_same(1, $result['failed'], 'Unsafe notification outbox paths should fail at the delivery boundary');
        assert_same(1, $result['retrying'], 'Unsafe notification outbox path should retain bounded retry state');
    } finally {
        $set->execute(['notification_delivery_channels', '']);
        $set->execute(['notification_file_outbox_path', '']);
    }
});

test_case('optional email notification delivery writes local mail outbox', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $outbox = sys_get_temp_dir() . '/cpe-email-outbox-' . bin2hex(random_bytes(4)) . '.jsonl';
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['notification_delivery_channels', 'email']);
    $set->execute(['notification_email_to', 'placement-office@example.test']);
    $set->execute(['notification_email_from', 'placements@example.test']);
    putenv('CPE_NOTIFICATION_EMAIL_OUTBOX_PATH=' . $outbox);
    try {
        $candidateId = $service->saveCandidate(['external_id' => 'EML001', 'name' => 'Email Delivery Candidate'], 1);
        $service->createWantedAlert($candidateId, 'email delivery smoke', 1);
        $alertId = (int) $pdo->query('SELECT id FROM wanted_alerts ORDER BY id DESC LIMIT 1')->fetchColumn();
        assert_same(3, (int) $pdo->query("SELECT COUNT(*) FROM notification_deliveries nd JOIN notifications n ON n.id = nd.notification_id WHERE n.source_type = 'wanted_alert' AND n.source_id = {$alertId} AND nd.channel = 'email' AND nd.status = 'queued'")->fetchColumn(), 'Wanted alert should queue email deliveries for role notifications');

        $dryRun = (new NotificationDeliveryService($pdo))->deliverPending('email', 10, true);
        assert_same(3, $dryRun['checked'], 'Email dry run should find queued deliveries');
        assert_same('[config:notification_email]', $dryRun['rows'][0]['target'], 'Email dry run should expose only the fixed configuration reference');

        $result = (new NotificationDeliveryService($pdo))->deliverPending('email');
        assert_same(3, $result['delivered'], 'Email delivery should deliver queued rows to the local outbox');
        assert_same(0, $result['failed'], 'Email delivery should not fail when local outbox is configured');
        assert_true(is_file($outbox), 'Email outbox should be written');
        $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents($outbox)))));
        assert_same(3, count($lines), 'Email outbox should contain one message per role notification');
        $message = json_decode($lines[0], true);
        assert_true(is_array($message), 'Email outbox row should be JSON');
        assert_same('placement-office@example.test', $message['to'] ?? '', 'Email outbox should include configured recipient');
        assert_true(str_contains((string) ($message['subject'] ?? ''), 'Wanted: EML001'), 'Email subject should include notification subject');
        assert_true(str_contains((string) ($message['body'] ?? ''), 'Recipient role:'), 'Email body should include delivery context');
        assert_true(in_array('From: placements@example.test', $message['headers'] ?? [], true), 'Email headers should include configured sender');
    } finally {
        putenv('CPE_NOTIFICATION_EMAIL_OUTBOX_PATH');
        $set->execute(['notification_delivery_channels', '']);
        $set->execute(['notification_email_to', '']);
        $set->execute(['notification_email_from', '']);
        if (is_file($outbox)) {
            unlink($outbox);
        }
    }
});

test_case('optional sms and whatsapp notification gateways write local message outbox', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $outbox = sys_get_temp_dir() . '/cpe-message-outbox-' . bin2hex(random_bytes(4)) . '.jsonl';
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['notification_delivery_channels', 'sms,whatsapp']);
    $set->execute(['notification_sms_to', 'sms-control-room']);
    $set->execute(['notification_whatsapp_to', 'wa-control-room']);
    putenv('CPE_NOTIFICATION_MESSAGE_OUTBOX_PATH=' . $outbox);
    try {
        $candidateId = $service->saveCandidate(['external_id' => 'MSG001', 'name' => 'Message Gateway Candidate'], 1);
        $service->createWantedAlert($candidateId, 'message gateway smoke', 1);
        $alertId = (int) $pdo->query('SELECT id FROM wanted_alerts ORDER BY id DESC LIMIT 1')->fetchColumn();
        assert_same(3, (int) $pdo->query("SELECT COUNT(*) FROM notification_deliveries nd JOIN notifications n ON n.id = nd.notification_id WHERE n.source_type = 'wanted_alert' AND n.source_id = {$alertId} AND nd.channel = 'sms' AND nd.status = 'queued'")->fetchColumn(), 'Wanted alert should queue SMS deliveries for role notifications');
        assert_same(3, (int) $pdo->query("SELECT COUNT(*) FROM notification_deliveries nd JOIN notifications n ON n.id = nd.notification_id WHERE n.source_type = 'wanted_alert' AND n.source_id = {$alertId} AND nd.channel = 'whatsapp' AND nd.status = 'queued'")->fetchColumn(), 'Wanted alert should queue WhatsApp deliveries for role notifications');

        $dryRun = (new NotificationDeliveryService($pdo))->deliverPending('sms', 10, true);
        assert_same(3, $dryRun['checked'], 'SMS dry run should find queued deliveries');
        assert_same('[config:notification_sms]', $dryRun['rows'][0]['target'], 'SMS dry run should expose only the fixed configuration reference');

        $smsResult = (new NotificationDeliveryService($pdo))->deliverPending('sms');
        $whatsAppResult = (new NotificationDeliveryService($pdo))->deliverPending('whatsapp');
        assert_same(3, $smsResult['delivered'], 'SMS gateway should deliver queued rows to the local outbox');
        assert_same(3, $whatsAppResult['delivered'], 'WhatsApp gateway should deliver queued rows to the local outbox');
        assert_same(0, $smsResult['failed'] + $whatsAppResult['failed'], 'Message gateway outbox delivery should not fail');
        assert_true(is_file($outbox), 'Message gateway outbox should be written');
        $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents($outbox)))));
        assert_same(6, count($lines), 'Message gateway outbox should contain one message per channel and role notification');
        $first = json_decode($lines[0], true);
        $last = json_decode($lines[5], true);
        assert_true(is_array($first) && is_array($last), 'Message gateway rows should be JSON');
        assert_same('sms', $first['channel'] ?? '', 'First gateway row should be SMS');
        assert_same('sms-control-room', $first['to'] ?? '', 'SMS gateway row should include configured route');
        assert_true(str_contains((string) ($first['text'] ?? ''), 'Wanted: MSG001'), 'SMS gateway text should include notification subject');
        assert_same('whatsapp', $last['channel'] ?? '', 'Last gateway row should be WhatsApp');
        assert_same('wa-control-room', $last['to'] ?? '', 'WhatsApp gateway row should include configured route');
        assert_same('wanted_alert', $last['notification']['source_type'] ?? '', 'Gateway payload should include original notification metadata');
    } finally {
        putenv('CPE_NOTIFICATION_MESSAGE_OUTBOX_PATH');
        $set->execute(['notification_delivery_channels', '']);
        $set->execute(['notification_sms_to', '']);
        $set->execute(['notification_whatsapp_to', '']);
        if (is_file($outbox)) {
            unlink($outbox);
        }
    }
});

test_case('notification gateway certification preflight validates templates and handoff settings', function (): void {
    $pdo = Database::connection();
    $outbox = sys_get_temp_dir() . '/cpe-certification-outbox-' . bin2hex(random_bytes(4)) . '.jsonl';
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['notification_delivery_channels', 'sms']);
    $set->execute(['notification_sms_to', 'sms-cert-route']);
    $set->execute(['notification_sms_message_template', '{{subject}} / {{recipient_role}}']);
    $set->execute(['notification_sms_payload_template', '{"route": {{to}}, "copy": {{text}}, "kind": {{template_key}}}']);
    putenv('CPE_NOTIFICATION_SMS_OUTBOX_PATH=' . $outbox);
    try {
        $report = (new NotificationDeliveryService($pdo))->certificationReport('sms');
        assert_true($report['ok'], 'SMS certification report should pass with route and local outbox env');
        $joined = implode("\n", array_map(fn (array $check): string => $check['status'] . ':' . $check['key'] . ':' . $check['message'], $report['checks']));
        assert_true(str_contains($joined, 'ok:message_template'), 'Certification should validate message template rendering');
        assert_true(str_contains($joined, 'ok:payload_json'), 'Certification should validate JSON payload rendering');
        assert_true(str_contains($joined, 'manual:provider_approval'), 'Certification should include manual provider approval check');

        [$helpCode, $helpOut, $helpErr] = run_cli(['certify-notifications', '--help']);
        assert_same(0, $helpCode, 'Certification help should exit cleanly: ' . $helpErr);
        assert_true(str_contains($helpOut, '--channel=sms'), 'Certification help should document channel option');

        [$code, $stdout, $stderr] = run_cli(['certify-notifications', '--channel=sms'], [
            'CPE_DB_PATH' => Database::path(),
            'CPE_NOTIFICATION_SMS_OUTBOX_PATH' => $outbox,
        ]);
        assert_same(0, $code, 'Certification CLI should pass with configured SMS route and outbox: ' . $stderr);
        assert_true(str_contains($stdout, 'OK payload_json'), 'Certification CLI should report rendered JSON payload');
        assert_true(str_contains($stdout, 'MANUAL provider_approval'), 'Certification CLI should surface manual provider approval');
        assert_true(str_contains($stdout, 'Notification certification preflight passed for sms.'), 'Certification CLI should report success');

        [$badCode, $badOut, $badErr] = run_cli(['certify-notifications', '--channel=voice'], [
            'CPE_DB_PATH' => Database::path(),
            'CPE_NOTIFICATION_SMS_OUTBOX_PATH' => $outbox,
        ]);
        assert_true($badCode !== 0, 'Unsupported certification channel should fail');
        assert_true(str_contains($badOut, 'ERROR channel'), 'Unsupported channel should print channel error');
        assert_true(str_contains($badErr, 'notification certification preflight failed'), 'Unsupported channel should explain failure');
    } finally {
        putenv('CPE_NOTIFICATION_SMS_OUTBOX_PATH');
        $set->execute(['notification_delivery_channels', '']);
        $set->execute(['notification_sms_to', '']);
        $set->execute(['notification_sms_message_template', '']);
        $set->execute(['notification_sms_payload_template', '']);
        if (is_file($outbox)) {
            unlink($outbox);
        }
    }
});

test_case('external notification templates customize email and message payloads', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $emailOutbox = sys_get_temp_dir() . '/cpe-template-email-' . bin2hex(random_bytes(4)) . '.jsonl';
    $smsOutbox = sys_get_temp_dir() . '/cpe-template-sms-' . bin2hex(random_bytes(4)) . '.jsonl';
    $whatsAppOutbox = sys_get_temp_dir() . '/cpe-template-wa-' . bin2hex(random_bytes(4)) . '.jsonl';
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['college_name', 'Template College']);
    $set->execute(['timezone', 'Asia/Kolkata']);
    $set->execute(['notification_delivery_channels', 'email,sms,whatsapp']);
    $set->execute(['notification_email_to', 'office@example.test']);
    $set->execute(['notification_email_subject_template', '{{college_name}} / {{subject}}']);
    $set->execute(['notification_email_body_template', "{{body}}\nRole={{recipient_role}}\nTZ={{timezone}}"]);
    $set->execute(['notification_sms_to', 'sms-route']);
    $set->execute(['notification_sms_message_template', '{{college_name}} SMS {{subject}} for {{recipient_role}}']);
    $set->execute(['notification_sms_payload_template', '{"route": {{to}}, "copy": {{text}}, "kind": {{template_key}}, "source": {{source_type}}}']);
    $set->execute(['notification_whatsapp_to', 'wa-route']);
    $set->execute(['notification_whatsapp_message_template', 'WA {{subject}} / {{recipient_role}}']);
    $set->execute(['notification_whatsapp_payload_template', '{"phone": {{to}}, "body": {{text}}, "meta": {{notification_json}}}']);
    putenv('CPE_NOTIFICATION_EMAIL_OUTBOX_PATH=' . $emailOutbox);
    putenv('CPE_NOTIFICATION_SMS_OUTBOX_PATH=' . $smsOutbox);
    putenv('CPE_NOTIFICATION_WHATSAPP_OUTBOX_PATH=' . $whatsAppOutbox);
    try {
        $candidateId = $service->saveCandidate(['external_id' => 'TPL001', 'name' => 'Template Candidate'], 1);
        $service->createWantedAlert($candidateId, 'template rendering smoke', 1);
        $alertId = (int) $pdo->query('SELECT id FROM wanted_alerts ORDER BY id DESC LIMIT 1')->fetchColumn();
        assert_same(3, (int) $pdo->query("SELECT COUNT(*) FROM notification_deliveries nd JOIN notifications n ON n.id = nd.notification_id WHERE n.source_type = 'wanted_alert' AND n.source_id = {$alertId} AND nd.channel = 'email' AND nd.status = 'queued'")->fetchColumn(), 'Template alert should queue email deliveries');

        $emailResult = (new NotificationDeliveryService($pdo))->deliverPending('email');
        $smsResult = (new NotificationDeliveryService($pdo))->deliverPending('sms');
        $whatsAppResult = (new NotificationDeliveryService($pdo))->deliverPending('whatsapp');
        assert_same(3, $emailResult['delivered'], 'Email template delivery should write outbox rows');
        assert_same(3, $smsResult['delivered'], 'SMS template delivery should write outbox rows');
        assert_same(3, $whatsAppResult['delivered'], 'WhatsApp template delivery should write outbox rows');

        $email = json_decode((explode("\n", trim((string) file_get_contents($emailOutbox)))[0] ?? ''), true);
        $sms = json_decode((explode("\n", trim((string) file_get_contents($smsOutbox)))[0] ?? ''), true);
        $whatsApp = json_decode((explode("\n", trim((string) file_get_contents($whatsAppOutbox)))[0] ?? ''), true);
        assert_true(is_array($email), 'Email template outbox row should be JSON');
        assert_true(is_array($sms), 'SMS template outbox row should be JSON');
        assert_true(is_array($whatsApp), 'WhatsApp template outbox row should be JSON');
        assert_true(str_contains((string) ($email['subject'] ?? ''), 'Template College / Wanted: TPL001'), 'Email subject template should render college name and subject');
        assert_true(str_contains((string) ($email['body'] ?? ''), 'Role=control'), 'Email body template should render recipient role');
        assert_true(str_contains((string) ($email['body'] ?? ''), 'TZ=Asia/Kolkata'), 'Email body template should render timezone');
        assert_same('sms-route', $sms['route'] ?? '', 'SMS payload template should rename recipient field');
        assert_true(str_contains((string) ($sms['copy'] ?? ''), 'Template College SMS Wanted: TPL001'), 'SMS message template should render into custom payload');
        assert_same('wanted_alert', $sms['source'] ?? '', 'SMS payload template should include source type');
        assert_same('wa-route', $whatsApp['phone'] ?? '', 'WhatsApp payload template should rename recipient field');
        assert_true(str_contains((string) ($whatsApp['body'] ?? ''), 'WA Wanted: TPL001'), 'WhatsApp message template should render into custom payload');
        assert_same('wanted_alert', $whatsApp['meta']['source_type'] ?? '', 'WhatsApp payload template should include notification metadata');
    } finally {
        putenv('CPE_NOTIFICATION_EMAIL_OUTBOX_PATH');
        putenv('CPE_NOTIFICATION_SMS_OUTBOX_PATH');
        putenv('CPE_NOTIFICATION_WHATSAPP_OUTBOX_PATH');
        foreach ([
            'college_name' => 'Test College',
            'timezone' => 'Asia/Kolkata',
            'notification_delivery_channels',
            'notification_email_to',
            'notification_email_subject_template',
            'notification_email_body_template',
            'notification_sms_to',
            'notification_sms_message_template',
            'notification_sms_payload_template',
            'notification_whatsapp_to',
            'notification_whatsapp_message_template',
            'notification_whatsapp_payload_template',
        ] as $key => $value) {
            if (is_int($key)) {
                $key = $value;
                $value = '';
            }
            $set->execute([$key, $value]);
        }
        foreach ([$emailOutbox, $smsOutbox, $whatsAppOutbox] as $outbox) {
            if (is_file($outbox)) {
                unlink($outbox);
            }
        }
    }
});

test_case('opted-out candidates cannot move forward', function (): void {
    $pdo = Database::connection();
    $app = $pdo->query("SELECT id, candidate_id FROM applications WHERE current_status = 'scheduled' LIMIT 1")->fetch();
    assert_true((int) $app['id'] > 0, 'Expected scheduled application');
    $pdo->exec('UPDATE candidates SET opted_out = 1 WHERE id = ' . (int) $app['candidate_id']);
    try {
        (new PlacementService($pdo))->moveNext((int) $app['id'], 1, 'admin');
        throw new RuntimeException('Expected opt-out failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'opted out'), 'Expected opt-out message');
    }
    $pdo->exec('UPDATE candidates SET opted_out = 0 WHERE id = ' . (int) $app['candidate_id']);
});

test_case('placement freeze blocks non-admin placement but allows admin override', function (): void {
    $pdo = Database::connection();
    $appId = (int) $pdo->query("SELECT id FROM applications WHERE current_status != 'placed' LIMIT 1")->fetchColumn();
    $pdo->exec("UPDATE applications SET current_status = 'sent', aggregate_version = aggregate_version + 1 WHERE id = {$appId}");
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('placement_freeze', '1') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    try {
        (new PlacementService($pdo))->moveNext($appId, 1, 'placement');
        throw new RuntimeException('Expected freeze failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'frozen'), 'Expected freeze message');
    }
    (new PlacementService($pdo))->moveNext($appId, 1, 'admin');
    assert_same('placed', $pdo->query("SELECT current_status FROM applications WHERE id = {$appId}")->fetchColumn());
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('placement_freeze', '0') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
});

test_case('offer upgrades are blocked unless enabled', function (): void {
    $pdo = Database::connection();
    $candidateId = (int) $pdo->query("SELECT id FROM candidates WHERE placed_company_id IS NOT NULL LIMIT 1")->fetchColumn();
    $companyId = (int) $pdo->query("SELECT id FROM companies WHERE id != (SELECT placed_company_id FROM candidates WHERE id = {$candidateId}) LIMIT 1")->fetchColumn();
    $service = new PlacementService($pdo);
    $service->saveApplication($candidateId, $companyId, 'sent', null, 1);
    $appId = (int) $pdo->query("SELECT id FROM applications WHERE candidate_id = {$candidateId} AND company_id = {$companyId}")->fetchColumn();
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('allow_offer_upgrade', '0') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    try {
        $service->moveNext($appId, 1, 'admin');
        throw new RuntimeException('Expected upgrade failure');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'upgrades are disabled'), 'Expected upgrade message');
    }
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('allow_offer_upgrade', '1') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $service->moveNext($appId, 1, 'admin');
    assert_same('placed', $pdo->query("SELECT current_status FROM applications WHERE id = {$appId}")->fetchColumn());
});

test_case('workflow overrides change label and transition roles', function (): void {
    $pdo = Database::connection();
    $pdo->exec("INSERT INTO workflow_status_overrides (status_key, label, color) VALUES ('scheduled', 'Ready', '#abcdef')");
    $pdo->exec("INSERT INTO workflow_transition_overrides (from_status, to_status, roles_csv) VALUES ('scheduled', 'intransit', 'mobile')");
    $workflow = new Workflow();
    assert_same('Ready', $workflow->statusLabel('scheduled'), 'Workflow label override');
    assert_true($workflow->canTransition('scheduled', 'intransit', 'mobile'), 'Mobile remains allowed');
    assert_true(!$workflow->canTransition('scheduled', 'intransit', 'company'), 'Company should no longer be allowed by override');
});

test_case('password hashing works through auth attempt', function (): void {
    assert_true(Auth::attempt('admin@test.local', 'password123'), 'Admin login should work');
    assert_true(!Auth::attempt('admin@test.local', 'wrong-password'), 'Wrong password should fail');
});

test_case('admin forms expose accessible names for dynamic controls', function (): void {
    $pdo = Database::connection();
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = 1 LIMIT 1")->fetchColumn();
    $previousSessionUser = $_SESSION['user_id'] ?? null;
    $bufferLevel = ob_get_level();
    $html = '';
    try {
        $_SESSION['user_id'] = $adminId;
        ob_start();
        (new \App\Controllers\AdminController())->show();
        $html = ob_get_clean() ?: '';
    } finally {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        if ($previousSessionUser === null) {
            unset($_SESSION['user_id']);
        } else {
            $_SESSION['user_id'] = $previousSessionUser;
        }
    }

    foreach (['reset_user_id', 'reset_user_password', 'new_user_name', 'new_user_email', 'new_user_password', 'new_user_role', 'new_user_scope_type', 'new_user_scope_value'] as $id) {
        assert_true(str_contains($html, 'for="' . $id . '"'), 'Admin control should have an associated label: ' . $id);
        assert_true(str_contains($html, 'id="' . $id . '"'), 'Admin control should expose its label target: ' . $id);
    }
    assert_true(preg_match('/aria-label="Active user: [^"]+"/', $html) === 1, 'User activation checkboxes should include the user in their accessible name');

    $workflow = new Workflow();
    foreach (array_keys($workflow->statuses()) as $key) {
        assert_true(str_contains($html, 'aria-label="' . $key . ' state label"'), 'Workflow state label input should identify its state: ' . $key);
        assert_true(str_contains($html, 'aria-label="Remove ' . $key . ' state"'), 'Workflow state removal checkbox should identify its state: ' . $key);
    }
    foreach ($workflow->transitionDefinitions(true) as $transition) {
        $key = (string) $transition['key'];
        foreach (['from state', 'to state', 'label', 'roles', 'order'] as $field) {
            assert_true(str_contains($html, 'aria-label="' . $key . ' transition ' . $field . '"'), 'Workflow transition control should identify its transition and field: ' . $key . ' / ' . $field);
        }
        assert_true(str_contains($html, 'aria-label="Remove ' . $key . ' transition"'), 'Workflow transition removal checkbox should identify its transition: ' . $key);
    }
});

test_case('login throttling database sessions and signed SSO assertions are replay safe', function (): void {
    $pdo = Database::connection();
    $pdo->exec('DELETE FROM auth_login_attempts');
    $pdo->exec('DELETE FROM auth_sso_nonces');
    $pdo->exec('DELETE FROM external_identities');
    $originalServer = $_SERVER;
    try {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.40';
        $throttle = new LoginThrottle($pdo);
        for ($i = 0; $i < 5; $i++) {
            $throttle->recordFailure('throttled@example.test');
        }
        try {
            $throttle->assertAllowed('throttled@example.test');
            throw new RuntimeException('Expected login throttle failure');
        } catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), 'Too many'), 'Login throttle should return a generic retry message');
        }
        $throttle->recordSuccess('throttled@example.test');
        $throttle->assertAllowed('throttled@example.test');

        $sessions = new DatabaseSessionHandler($pdo, 3600);
        assert_true($sessions->write('session-contract', 'contract-payload'), 'Database session should write');
        assert_true($sessions->close(), 'Database session should release its request lock');
        $reader = new DatabaseSessionHandler($pdo, 3600);
        assert_same('contract-payload', $reader->read('session-contract'), 'A later request should read the database session');
        assert_true($reader->close(), 'Database session reader should release its request lock');
        assert_true($sessions->destroy('session-contract'), 'Database session should destroy');
        assert_true($sessions->close(), 'Destroyed session should release its request lock');
        assert_same('', $reader->read('session-contract'), 'Destroyed database session should be absent');
        $reader->close();

        $identity = new ExternalIdentityService($pdo);
        $identity->link('oidc_proxy', 'subject-contract', 'admin@test.local');
        $secret = str_repeat('s', 48);
        $timestamp = (string) time();
        $nonce = 'nonce-contract-1234567890';
        $tenant = (string) $pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn();
        $name = 'Test Administrator';
        $email = 'admin@test.local';
        $canonical = implode("\n", ['oidc_proxy', 'subject-contract', $email, $name, $timestamp, $nonce, $tenant]);
        putenv('CPE_SSO_ENABLED=1');
        putenv('CPE_SSO_PROVIDER_KEY=oidc_proxy');
        putenv('CPE_SSO_SHARED_SECRET=' . $secret);
        $_SERVER['HTTP_X_CPE_SSO_SUBJECT'] = 'subject-contract';
        $_SERVER['HTTP_X_CPE_SSO_EMAIL'] = $email;
        $_SERVER['HTTP_X_CPE_SSO_NAME'] = $name;
        $_SERVER['HTTP_X_CPE_SSO_TIMESTAMP'] = $timestamp;
        $_SERVER['HTTP_X_CPE_SSO_NONCE'] = $nonce;
        $_SERVER['HTTP_X_CPE_SSO_SIGNATURE'] = hash_hmac('sha256', $canonical, $secret);
        $_SESSION = [];
        $user = $identity->authenticateRequest();
        assert_same(1, (int) $user['id'], 'Signed SSO assertion should authenticate linked user');
        assert_same('sso:oidc_proxy', $_SESSION['auth_method'], 'Session should retain authentication method');
        try {
            $identity->authenticateRequest();
            throw new RuntimeException('Expected SSO replay failure');
        } catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), 'already been used'), 'SSO nonce should reject replay');
        }
    } finally {
        $_SERVER = $originalServer;
        foreach (['CPE_SSO_ENABLED', 'CPE_SSO_PROVIDER_KEY', 'CPE_SSO_SHARED_SECRET'] as $key) {
            putenv($key);
        }
        $pdo->exec('DELETE FROM auth_login_attempts');
        $pdo->exec('DELETE FROM auth_sso_nonces');
        $pdo->exec('DELETE FROM external_identities');
    }
});

test_case('audit request metadata retention is opt-in', function (): void {
    $pdo = Database::connection();
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $originalRemote = $_SERVER['REMOTE_ADDR'] ?? null;
    $originalAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    try {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.25';
        $_SERVER['HTTP_USER_AGENT'] = "Synthetic\nBrowser 1.0";

        $set->execute(['audit_request_metadata', 'none']);
        Auth::audit(1, 'settings.update', 'system', null, 'Default metadata test');
        $row = $pdo->query("SELECT detail, ip_address, user_agent FROM audit_logs WHERE action = 'settings.update' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same('Settings updated.', (string) $row['detail'], 'Audit detail should use reviewed fixed wording');
        assert_same('', (string) $row['ip_address'], 'Audit metadata should not retain IP by default');
        assert_same('', (string) $row['user_agent'], 'Audit metadata should not retain user agent by default');

        $set->execute(['audit_request_metadata', 'both']);
        Auth::audit(1, 'settings.update', 'system', null, 'Opt-in metadata test sentinel.admin@example.test');
        $row = $pdo->query("SELECT detail, ip_address, user_agent FROM audit_logs WHERE action = 'settings.update' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same('203.0.113.0/24', (string) $row['ip_address'], 'Audit metadata should retain only a coarsened request network when enabled');
        assert_same('client.other', (string) $row['user_agent'], 'Audit metadata should retain only a fixed user-agent family when enabled');
        assert_true(!str_contains(json_encode($row) ?: '', 'sentinel.admin@example.test'), 'Audit persistence should not retain caller detail');

        $set->execute(['audit_request_metadata', 'user_agent']);
        Auth::audit(1, 'settings.update', 'system', null, 'User agent metadata test');
        $row = $pdo->query("SELECT ip_address, user_agent FROM audit_logs WHERE action = 'settings.update' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same('', (string) $row['ip_address'], 'Audit metadata should omit IP unless selected');
        assert_same('client.other', (string) $row['user_agent'], 'Audit metadata should retain only a fixed user-agent family when selected');
    } finally {
        $set->execute(['audit_request_metadata', 'none']);
        if ($originalRemote === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $originalRemote;
        }
        if ($originalAgent === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $originalAgent;
        }
    }
});

test_case('administrators can reset passwords and deactivate users', function (): void {
    $pdo = Database::connection();
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'admin@test.local'")->fetchColumn();
    $userId = Auth::createUser('Temporary Auditor', 'temporary-auditor@test.local', 'password123', 'auditor');
    Auth::setPassword($userId, 'newpass123', $adminId);
    assert_true(Auth::attempt('temporary-auditor@test.local', 'newpass123'), 'Reset password should work');
    Auth::setActiveBulk([$adminId], $adminId);
    assert_true(!Auth::attempt('temporary-auditor@test.local', 'newpass123'), 'Inactive user should not log in');
    assert_true(Auth::attempt('admin@test.local', 'password123'), 'Actor should remain active');
});

test_case('readiness snapshot reports stale applications and open queues', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $candidateId = (int) $pdo->query("SELECT id FROM candidates WHERE external_id = 'C003'")->fetchColumn();
    $companyIds = $pdo->query("SELECT id FROM companies ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $service->createPreferenceRequest($candidateId, $companyIds, 1, 'readiness check');
    $service->createWantedAlert($candidateId, 'readiness check', 1);
    $appId = (int) $pdo->query("SELECT id FROM applications WHERE current_status NOT IN ('idle', 'placed') LIMIT 1")->fetchColumn();
    $pdo->exec("UPDATE applications SET updated_at = '2000-01-01 00:00:00' WHERE id = {$appId}");

    $snapshot = (new ReadinessService($pdo))->snapshot();
    assert_true($snapshot['openPreferences'] >= 1, 'Readiness should count open preference requests');
    assert_true($snapshot['openWanted'] >= 1, 'Readiness should count open wanted alerts');
    assert_true($snapshot['openNotifications'] >= 1, 'Readiness should count open in-app notifications');
    assert_true($snapshot['staleApplications']['count'] >= 1, 'Readiness should count stale active applications');
    assert_same(false, $snapshot['configurationFrozen'], 'Readiness should expose unfrozen configuration state');
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['configuration_freeze', '1']);
    $frozenSnapshot = (new ReadinessService($pdo))->snapshot();
    assert_same(true, $frozenSnapshot['configurationFrozen'], 'Readiness should expose frozen configuration state');
    assert_true(count(array_filter($frozenSnapshot['checks'], fn (array $check): bool => $check['label'] === 'Configuration freeze' && $check['status'] === 'ok')) === 1, 'Readiness should mark configuration freeze as OK when enabled');
    $set->execute(['configuration_freeze', '0']);
    $companyId = $service->saveCompany(['code' => 'CAP', 'name' => 'Capacity Co', 'max_active' => '1'], 1);
    $candidateOne = $service->saveCandidate(['external_id' => 'CAP001', 'name' => 'Capacity One'], 1);
    $candidateTwo = $service->saveCandidate(['external_id' => 'CAP002', 'name' => 'Capacity Two'], 1);
    $service->saveApplication($candidateOne, $companyId, 'scheduled', null, 1);
    $service->saveApplication($candidateTwo, $companyId, 'scheduled', null, 1);
    $snapshot = (new ReadinessService($pdo))->snapshot();
    assert_true($snapshot['capacityAlerts']['count'] >= 1, 'Readiness should warn on company active capacity');
    $outbox = sys_get_temp_dir() . '/cpe-readiness-sms-' . bin2hex(random_bytes(4)) . '.jsonl';
    try {
        $set->execute(['notification_delivery_channels', 'sms']);
        $set->execute(['notification_sms_to', '']);
        $set->execute(['notification_sms_gateway_url', '']);
        $snapshot = (new ReadinessService($pdo))->snapshot();
        assert_same('warn', $snapshot['notificationGatewayCertification']['status'], 'Readiness should warn when enabled SMS handoff cannot pass certification');
        assert_true(str_contains($snapshot['notificationGatewayCertification']['message'], 'SMS recipient'), 'Readiness should explain missing SMS recipient route');

        putenv('CPE_NOTIFICATION_SMS_OUTBOX_PATH=' . $outbox);
        $set->execute(['notification_sms_to', 'sms-readiness-route']);
        $snapshot = (new ReadinessService($pdo))->snapshot();
        assert_same('ok', $snapshot['notificationGatewayCertification']['status'], 'Readiness should pass when enabled SMS handoff has a route and local outbox');
    } finally {
        putenv('CPE_NOTIFICATION_SMS_OUTBOX_PATH');
        $set->execute(['notification_delivery_channels', '']);
        $set->execute(['notification_sms_to', '']);
        $set->execute(['notification_sms_gateway_url', '']);
        if (is_file($outbox)) {
            unlink($outbox);
        }
    }
    assert_true(count($snapshot['checks']) >= 5, 'Readiness should include checks');
});

test_case('readiness uses configured backup storage without disclosing its path', function (): void {
    $pdo = Database::connection();
    $originalDirectory = getenv('CPE_BACKUP_DIR');
    $directory = sys_get_temp_dir() . '/cpe-readiness-backup-private-sentinel-' . bin2hex(random_bytes(4));
    $missingDirectory = $directory . '-missing';
    $symlinkDirectory = $directory . '-link';
    $outsideFile = $directory . '-outside.sqlite';
    $caseDirectories = [];
    try {
        mkdir($directory, 0700, true);
        putenv('CPE_BACKUP_DIR=' . $directory);
        assert_same($directory, DatabaseBackupService::configuredDirectory(), 'Backup creation and readiness should share configured storage');

        $snapshotFor = static function (string $path) use ($pdo): array {
            putenv('CPE_BACKUP_DIR=' . $path);
            return (new ReadinessService($pdo))->snapshot()['backup'];
        };
        $newCaseDirectory = static function (string $label) use ($directory, &$caseDirectories): string {
            $path = $directory . '-' . $label;
            mkdir($path, 0700, true);
            $caseDirectories[] = $path;
            return $path;
        };
        $assertRejected = static function (string $path, string $label) use ($snapshotFor, $directory): void {
            $result = $snapshotFor($path);
            assert_same(false, $result['present'], $label . ' must not count as a backup');
            assert_same('warn', $result['status'], $label . ' should warn');
            assert_true(
                !str_contains(json_encode($result, JSON_THROW_ON_ERROR), $directory),
                $label . ' disclosed configured backup storage',
            );
        };

        $empty = $snapshotFor($directory);
        assert_same(false, $empty['present'], 'Empty configured backup storage should report no backup');
        assert_same('warn', $empty['status'], 'Empty configured backup storage should warn');
        assert_true(str_contains($empty['message'], 'No backup found'), 'Empty configured backup storage should retain useful guidance');
        assert_true(!str_contains(json_encode($empty, JSON_THROW_ON_ERROR), $directory), 'Empty backup readiness disclosed its configured path');

        file_put_contents($outsideFile, 'not a database backup');
        $archiveLinkDirectory = $newCaseDirectory('archive-link');
        if (@symlink($outsideFile, $archiveLinkDirectory . '/linked.sqlite')) {
            $assertRejected($archiveLinkDirectory, 'Symlink archive');
        }

        if (@symlink($directory, $symlinkDirectory)) {
            putenv('CPE_BACKUP_DIR=' . $symlinkDirectory);
            $before = scandir($directory);
            try {
                (new DatabaseBackupService($pdo))->create('unsafe');
                throw new RuntimeException('Symlinked backup storage accepted a write.');
            } catch (UserVisibleException $e) {
                assert_same('DATABASE_BACKUP_STORAGE_UNAVAILABLE', $e->publicCode(), 'Symlink writer rejection used the wrong fixed code');
                assert_true(!str_contains($e->publicMessage(), $symlinkDirectory), 'Symlink writer rejection disclosed configured storage');
            }
            assert_same($before, scandir($directory), 'Symlink writer rejection created an artifact before failing');

            $unsafe = $snapshotFor($symlinkDirectory);
            assert_same(false, $unsafe['present'], 'Symlinked configured backup storage should fail closed');
            assert_same('warn', $unsafe['status'], 'Symlinked configured backup storage should warn safely');
            assert_true(str_contains($unsafe['message'], 'could not be inspected'), 'Unsafe backup storage should use fixed access guidance');
            assert_true(!str_contains(json_encode($unsafe, JSON_THROW_ON_ERROR), $symlinkDirectory), 'Unsafe backup readiness disclosed its configured path');
        }

        $zeroDirectory = $newCaseDirectory('zero');
        file_put_contents($zeroDirectory . '/lookalike.sqlite', '');
        $assertRejected($zeroDirectory, 'Zero-byte archive');

        $missingSidecarsDirectory = $newCaseDirectory('missing-sidecars');
        file_put_contents($missingSidecarsDirectory . '/lookalike.sqlite', 'not a complete backup');
        $assertRejected($missingSidecarsDirectory, 'Archive without sidecars');

        $orphanDirectory = $newCaseDirectory('orphan-sidecars');
        file_put_contents($orphanDirectory . '/orphan.sqlite.sha256', str_repeat('0', 64));
        file_put_contents($orphanDirectory . '/orphan.sqlite' . BackupMetadata::SUFFIX, '{}');
        $assertRejected($orphanDirectory, 'Orphan sidecars');

        $rewriteChecksum = static function (string $archive, string $checksum, string $metadata): void {
            file_put_contents(
                $checksum,
                hash_file('sha256', $archive) . '  ' . basename($archive) . "\n"
                    . hash_file('sha256', $metadata) . '  ' . basename($metadata) . "\n",
            );
        };
        $rewriteCreatedAt = static function (
            string $archive,
            string $checksum,
            string $metadataPath,
            string $createdAt,
        ) use ($rewriteChecksum): void {
            $metadata = json_decode((string) file_get_contents($metadataPath), true, 16, JSON_THROW_ON_ERROR);
            $metadata['created_at'] = $createdAt;
            file_put_contents(
                $metadataPath,
                json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );
            $rewriteChecksum($archive, $checksum, $metadataPath);
        };

        putenv('CPE_BACKUP_DIR=' . $directory);
        $artifact = (new DatabaseBackupService($pdo))->create('readiness');
        touch($artifact->internalPath(), time() - (72 * 3600));
        $fresh = (new ReadinessService($pdo))->snapshot()['backup'];
        assert_same(true, $fresh['present'], 'Fresh backup in configured storage was not found');
        assert_same('ok', $fresh['status'], 'Fresh configured backup should pass readiness');
        assert_same(0, $fresh['ageHours'], 'Archive mtime must not age checksum-bound backup metadata');
        assert_true(!str_contains(json_encode($fresh, JSON_THROW_ON_ERROR), $directory), 'Fresh backup readiness disclosed its configured path');

        $copySet = static function (string $targetDirectory, ?string $basename = null) use ($artifact, $rewriteChecksum): array {
            $archive = $targetDirectory . '/' . ($basename ?? basename($artifact->internalPath()));
            $checksum = $archive . '.sha256';
            $metadata = $archive . BackupMetadata::SUFFIX;
            copy($artifact->internalPath(), $archive);
            copy($artifact->internalMetadataPath(), $metadata);
            $rewriteChecksum($archive, $checksum, $metadata);
            return [$archive, $checksum, $metadata];
        };

        $missingChecksumDirectory = $newCaseDirectory('missing-checksum');
        [$missingChecksumArchive, $missingChecksum, $missingChecksumMetadata] = $copySet($missingChecksumDirectory);
        unlink($missingChecksum);
        $assertRejected($missingChecksumDirectory, 'Backup with missing checksum');

        $checksumLinkDirectory = $newCaseDirectory('checksum-link');
        [$checksumLinkArchive, $checksumLink, $checksumLinkMetadata] = $copySet($checksumLinkDirectory);
        unlink($checksumLink);
        if (@symlink($artifact->internalChecksumPath(), $checksumLink)) {
            $assertRejected($checksumLinkDirectory, 'Symlink checksum');
        }

        $metadataLinkDirectory = $newCaseDirectory('metadata-link');
        [$metadataLinkArchive, $metadataLinkChecksum, $metadataLink] = $copySet($metadataLinkDirectory);
        unlink($metadataLink);
        if (@symlink($artifact->internalMetadataPath(), $metadataLink)) {
            $assertRejected($metadataLinkDirectory, 'Symlink metadata');
        }

        $malformedChecksumDirectory = $newCaseDirectory('malformed-checksum');
        [$malformedChecksumArchive, $malformedChecksum, $malformedChecksumMetadata] = $copySet($malformedChecksumDirectory);
        file_put_contents($malformedChecksum, 'not a checksum');
        $assertRejected($malformedChecksumDirectory, 'Malformed checksum');

        $oversizedChecksumDirectory = $newCaseDirectory('oversized-checksum');
        [$oversizedChecksumArchive, $oversizedChecksum, $oversizedChecksumMetadata] = $copySet($oversizedChecksumDirectory);
        file_put_contents($oversizedChecksum, str_repeat('0', 4097));
        $assertRejected($oversizedChecksumDirectory, 'Oversized checksum');

        $unreadableChecksumDirectory = $newCaseDirectory('unreadable-checksum');
        [$unreadableChecksumArchive, $unreadableChecksum, $unreadableChecksumMetadata] = $copySet($unreadableChecksumDirectory);
        chmod($unreadableChecksum, 0000);
        if (!is_readable($unreadableChecksum)) {
            $assertRejected($unreadableChecksumDirectory, 'Unreadable checksum');
        }
        chmod($unreadableChecksum, 0600);

        $malformedMetadataDirectory = $newCaseDirectory('malformed-metadata');
        [$malformedMetadataArchive, $malformedMetadataChecksum, $malformedMetadata] = $copySet($malformedMetadataDirectory);
        file_put_contents($malformedMetadata, '{"broken":');
        $rewriteChecksum($malformedMetadataArchive, $malformedMetadataChecksum, $malformedMetadata);
        $assertRejected($malformedMetadataDirectory, 'Malformed metadata');

        $invalidDateDirectory = $newCaseDirectory('invalid-date');
        [$invalidDateArchive, $invalidDateChecksum, $invalidDateMetadata] = $copySet($invalidDateDirectory);
        $rewriteCreatedAt(
            $invalidDateArchive,
            $invalidDateChecksum,
            $invalidDateMetadata,
            '2026-02-30T12:00:00Z',
        );
        $assertRejected($invalidDateDirectory, 'Invalid calendar metadata timestamp');

        $futureDateDirectory = $newCaseDirectory('future-date');
        [$futureDateArchive, $futureDateChecksum, $futureDateMetadata] = $copySet($futureDateDirectory);
        $rewriteCreatedAt(
            $futureDateArchive,
            $futureDateChecksum,
            $futureDateMetadata,
            gmdate('Y-m-d\TH:i:s\Z', time() + BackupMetadata::MAX_FUTURE_SKEW_SECONDS + 60),
        );
        $assertRejected($futureDateDirectory, 'Unreasonably future-dated metadata');

        $oversizedMetadataDirectory = $newCaseDirectory('oversized-metadata');
        [$oversizedMetadataArchive, $oversizedMetadataChecksum, $oversizedMetadata] = $copySet($oversizedMetadataDirectory);
        file_put_contents($oversizedMetadata, str_repeat('x', BackupMetadata::MAX_BYTES + 1));
        $rewriteChecksum($oversizedMetadataArchive, $oversizedMetadataChecksum, $oversizedMetadata);
        $assertRejected($oversizedMetadataDirectory, 'Oversized metadata');

        $tamperedArchiveDirectory = $newCaseDirectory('tampered-archive');
        [$tamperedArchive, $tamperedArchiveChecksum, $tamperedArchiveMetadata] = $copySet($tamperedArchiveDirectory);
        file_put_contents($tamperedArchive, 'tamper', FILE_APPEND);
        $assertRejected($tamperedArchiveDirectory, 'Tampered archive');

        $identityMismatchDirectory = $newCaseDirectory('identity-mismatch');
        [$identityMismatchArchive, $identityMismatchChecksum, $identityMismatchMetadata] = $copySet($identityMismatchDirectory);
        $metadata = json_decode((string) file_get_contents($identityMismatchMetadata), true, 16, JSON_THROW_ON_ERROR);
        $metadata['institution_public_id'] = 'tenant_' . str_repeat('f', 32);
        file_put_contents($identityMismatchMetadata, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $rewriteChecksum($identityMismatchArchive, $identityMismatchChecksum, $identityMismatchMetadata);
        $assertRejected($identityMismatchDirectory, 'Identity-mismatched metadata');

        $renamedFormatDirectory = $newCaseDirectory('renamed-format');
        $copySet($renamedFormatDirectory, 'relabeled.pgdump');
        $assertRejected($renamedFormatDirectory, 'SQLite archive renamed as PostgreSQL');

        $liveIdentity = BackupMetadata::databaseIdentity($pdo, 'sqlite');
        $grownBudgetDirectory = $newCaseDirectory('grown-budget');
        [$grownBudgetArchive] = $copySet($grownBudgetDirectory);
        $observedBytes = filesize($grownBudgetArchive);
        assert_true(is_int($observedBytes) && $observedBytes > 0, 'Could not observe budget fixture size');
        file_put_contents($grownBudgetArchive, 'growth-after-directory-scan', FILE_APPEND);
        try {
            (new DatabaseRestoreService(null, $observedBytes))->verifiedCandidate(
                $grownBudgetArchive,
                'sqlite',
                $liveIdentity,
            );
            throw new RuntimeException('Archive growth beyond the fresh verification budget was accepted.');
        } catch (UserVisibleException $e) {
            assert_same('DATABASE_BACKUP_VERIFICATION_LIMIT', $e->publicCode(), 'Archive growth used the wrong fixed failure');
        }

        $firstBudgetDirectory = $newCaseDirectory('budget-first');
        [$firstBudgetArchive] = $copySet($firstBudgetDirectory);
        $secondBudgetDirectory = $newCaseDirectory('budget-second');
        [$secondBudgetArchive] = $copySet($secondBudgetDirectory);
        $singleArchiveBytes = filesize($firstBudgetArchive);
        assert_true(is_int($singleArchiveBytes) && $singleArchiveBytes > 0, 'Could not inspect cumulative budget fixtures');
        $budgetedVerifier = new DatabaseRestoreService(null, ($singleArchiveBytes * 2) - 1);
        $budgetedVerifier->verifiedCandidate($firstBudgetArchive, 'sqlite', $liveIdentity);
        try {
            $budgetedVerifier->verifiedCandidate($secondBudgetArchive, 'sqlite', $liveIdentity);
            throw new RuntimeException('Cumulative backup verification budget was reset between candidates.');
        } catch (UserVisibleException $e) {
            assert_same('DATABASE_BACKUP_VERIFICATION_LIMIT', $e->publicCode(), 'Cumulative budget used the wrong fixed failure');
        }

        $postgresStructureDirectory = $newCaseDirectory('postgres-structure');
        $postgresArchive = $postgresStructureDirectory . '/structural.pgdump';
        $postgresMetadata = $postgresArchive . BackupMetadata::SUFFIX;
        $postgresChecksum = $postgresArchive . '.sha256';
        file_put_contents($postgresArchive, 'synthetic-postgresql-custom-format-fixture');
        $postgresMetadataValues = json_decode(
            (string) file_get_contents($artifact->internalMetadataPath()),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        $postgresMetadataValues['driver'] = 'pgsql';
        $postgresMetadataValues['archive_sha256'] = hash_file('sha256', $postgresArchive);
        $postgresMetadataValues['created_at'] = gmdate('Y-m-d\TH:i:s\Z');
        file_put_contents(
            $postgresMetadata,
            json_encode($postgresMetadataValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        $rewriteChecksum($postgresArchive, $postgresChecksum, $postgresMetadata);
        $fakePgRestore = $postgresStructureDirectory . '/pg_restore';
        file_put_contents(
            $fakePgRestore,
            "#!/bin/sh\n"
                . "test \"\$1\" = \"--list\" || exit 9\n"
                . "printf '%s\\n' cpe_database_ownership institutions settings\n",
        );
        chmod($fakePgRestore, 0700);
        $originalPgRestore = getenv('CPE_PG_RESTORE_BINARY');
        try {
            putenv('CPE_PG_RESTORE_BINARY=' . $fakePgRestore);
            (new DatabaseRestoreService())->verifiedCandidate($postgresArchive, 'pgsql', $liveIdentity);
            file_put_contents($fakePgRestore, "#!/bin/sh\nexit 2\n");
            chmod($fakePgRestore, 0700);
            try {
                (new DatabaseRestoreService())->verifiedCandidate($postgresArchive, 'pgsql', $liveIdentity);
                throw new RuntimeException('Failed PostgreSQL structural inspection was accepted.');
            } catch (UserVisibleException $e) {
                assert_same('DATABASE_BACKUP_STRUCTURE_INVALID', $e->publicCode(), 'PostgreSQL structural failure used the wrong fixed code');
            }
        } finally {
            if ($originalPgRestore === false) {
                putenv('CPE_PG_RESTORE_BINARY');
            } else {
                putenv('CPE_PG_RESTORE_BINARY=' . $originalPgRestore);
            }
        }

        [$code, $stdout, $stderr] = run_cli(['readiness'], ['CPE_BACKUP_DIR' => $directory]);
        assert_same(0, $code, 'CLI readiness should inspect configured backup storage: ' . $stderr);
        assert_true(str_contains($stdout, 'Latest verified backup is about'), 'CLI readiness did not report the configured backup age');
        assert_true(!str_contains($stdout . $stderr, $directory), 'CLI readiness disclosed its configured backup path');

        putenv('CPE_BACKUP_DIR=' . $directory);
        $newerArtifact = (new DatabaseBackupService($pdo))->create('readiness-newer');
        $rewriteCreatedAt(
            $artifact->internalPath(),
            $artifact->internalChecksumPath(),
            $artifact->internalMetadataPath(),
            gmdate('Y-m-d\TH:i:s\Z', time() - (8 * 3600)),
        );
        $rewriteCreatedAt(
            $newerArtifact->internalPath(),
            $newerArtifact->internalChecksumPath(),
            $newerArtifact->internalMetadataPath(),
            gmdate('Y-m-d\TH:i:s\Z', time() - (2 * 3600)),
        );
        touch($artifact->internalPath());
        touch($newerArtifact->internalPath(), time() - (72 * 3600));
        $newerInvalid = $directory . '/newest-lookalike.sqlite';
        file_put_contents($newerInvalid, 'not a complete backup');
        touch($newerInvalid);
        $newestValid = (new ReadinessService($pdo))->snapshot()['backup'];
        assert_same(true, $newestValid['present'], 'An invalid newest candidate hid an older valid backup');
        assert_same('ok', $newestValid['status'], 'Newest valid backup should determine readiness');
        assert_true($newestValid['ageHours'] >= 1 && $newestValid['ageHours'] <= 3, 'Readiness did not choose the newest checksum-bound metadata timestamp');

        $rewriteCreatedAt(
            $artifact->internalPath(),
            $artifact->internalChecksumPath(),
            $artifact->internalMetadataPath(),
            gmdate('Y-m-d\TH:i:s\Z', time() - (72 * 3600)),
        );
        $rewriteCreatedAt(
            $newerArtifact->internalPath(),
            $newerArtifact->internalChecksumPath(),
            $newerArtifact->internalMetadataPath(),
            gmdate('Y-m-d\TH:i:s\Z', time() - (49 * 3600)),
        );
        touch($artifact->internalPath());
        touch($newerArtifact->internalPath());
        $stale = (new ReadinessService($pdo))->snapshot()['backup'];
        assert_same(true, $stale['present'], 'Stale configured backup should remain present');
        assert_same('warn', $stale['status'], 'Stale configured backup should warn');
        assert_true($stale['ageHours'] >= 48, 'Stale configured backup age was not reported accurately');
        assert_true(!str_contains(json_encode($stale, JSON_THROW_ON_ERROR), $directory), 'Stale backup readiness disclosed its configured path');

        $copiedStaleDirectory = $newCaseDirectory('copied-stale');
        $copySet($copiedStaleDirectory);
        $copiedStale = $snapshotFor($copiedStaleDirectory);
        assert_same(true, $copiedStale['present'], 'Copied old backup should remain a valid backup set');
        assert_same('warn', $copiedStale['status'], 'Copying an old backup set must not freshen it');
        assert_true($copiedStale['ageHours'] >= 71, 'Copied backup freshness did not use checksum-bound metadata time');

        $retainedSetsDirectory = $newCaseDirectory('retained-sets');
        for ($index = 1; $index <= 20; $index++) {
            [$retainedArchive, $retainedChecksum, $retainedMetadata] = $copySet(
                $retainedSetsDirectory,
                sprintf('retained-%02d.sqlite', $index),
            );
            $rewriteCreatedAt(
                $retainedArchive,
                $retainedChecksum,
                $retainedMetadata,
                gmdate('Y-m-d\TH:i:s\Z', time() - ($index * 3600)),
            );
            touch($retainedArchive, time() - ((21 - $index) * 3600));
        }
        $retainedSets = $snapshotFor($retainedSetsDirectory);
        assert_same(true, $retainedSets['present'], 'More than sixteen retained complete backup sets made readiness unavailable');
        assert_same('ok', $retainedSets['status'], 'Retained backup readiness did not verify the bounded newest shortlist');
        assert_true(
            $retainedSets['ageHours'] >= 0 && $retainedSets['ageHours'] <= 2,
            'Retained backup readiness did not prioritize checksum-bound metadata time over archive mtime',
        );

        $shortCircuitDirectory = $newCaseDirectory('verified-short-circuit');
        [$newestSmallArchive, $newestSmallChecksum, $newestSmallMetadata] = $copySet(
            $shortCircuitDirectory,
            'newest-small.sqlite',
        );
        $rewriteCreatedAt(
            $newestSmallArchive,
            $newestSmallChecksum,
            $newestSmallMetadata,
            gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
        );

        $olderSparseArchive = $shortCircuitDirectory . '/older-over-budget.sqlite';
        $olderSparseHandle = fopen($olderSparseArchive, 'x+b');
        assert_true(is_resource($olderSparseHandle), 'Could not create sparse over-budget readiness fixture');
        $verificationLimit = (new ReflectionClass(ReadinessService::class))
            ->getConstant('MAX_BACKUP_VERIFICATION_BYTES');
        assert_true(is_int($verificationLimit) && $verificationLimit > 0, 'Readiness verification limit is unavailable');
        assert_true(
            ftruncate($olderSparseHandle, $verificationLimit + 1),
            'Could not size sparse over-budget readiness fixture',
        );
        fclose($olderSparseHandle);
        $olderSparseMetadata = $olderSparseArchive . BackupMetadata::SUFFIX;
        $olderSparseMetadataValues = json_decode(
            (string) file_get_contents($artifact->internalMetadataPath()),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        $olderSparseMetadataValues['archive_sha256'] = str_repeat('0', 64);
        $olderSparseMetadataValues['created_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - (48 * 3600));
        file_put_contents(
            $olderSparseMetadata,
            json_encode($olderSparseMetadataValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        $olderSparseMetadataHash = hash_file('sha256', $olderSparseMetadata);
        assert_true(is_string($olderSparseMetadataHash), 'Could not hash sparse fixture metadata');
        file_put_contents(
            $olderSparseArchive . '.sha256',
            str_repeat('0', 64) . '  ' . basename($olderSparseArchive) . "\n"
                . $olderSparseMetadataHash . '  ' . basename($olderSparseMetadata) . "\n",
        );
        touch($olderSparseArchive);

        $shortCircuit = $snapshotFor($shortCircuitDirectory);
        assert_same(true, $shortCircuit['present'], 'An older over-budget set discarded an already verified newer backup');
        assert_same('ok', $shortCircuit['status'], 'The verified newest backup should determine readiness immediately');
        assert_true(
            $shortCircuit['ageHours'] >= 0 && $shortCircuit['ageHours'] <= 2,
            'Readiness did not stop before full verification of the older sparse archive',
        );

        putenv('CPE_BACKUP_DIR=' . $missingDirectory);
        $missing = (new ReadinessService($pdo))->snapshot()['backup'];
        assert_same(false, $missing['present'], 'Missing configured backup storage should not report a backup');
        assert_same('warn', $missing['status'], 'Missing configured backup storage should warn safely');
        assert_true(!str_contains(json_encode($missing, JSON_THROW_ON_ERROR), $missingDirectory), 'Missing backup readiness disclosed its configured path');

        $boundedDirectory = $newCaseDirectory('bounded-scan');
        for ($i = 0; $i < 513; $i++) {
            file_put_contents($boundedDirectory . '/entry-' . $i . '.txt', 'x');
        }
        $bounded = $snapshotFor($boundedDirectory);
        assert_same(false, $bounded['present'], 'Oversized backup directory scan should fail closed');
        assert_true(str_contains($bounded['message'], 'could not be inspected'), 'Bounded scan should use fixed access guidance');
        assert_true(!str_contains(json_encode($bounded, JSON_THROW_ON_ERROR), $directory), 'Bounded scan disclosed configured storage');

        putenv('CPE_BACKUP_DIR');
        assert_same(cpe_data_path('backups'), DatabaseBackupService::configuredDirectory(), 'Default backup storage contract changed');
    } finally {
        if ($originalDirectory === false) {
            putenv('CPE_BACKUP_DIR');
        } else {
            putenv('CPE_BACKUP_DIR=' . $originalDirectory);
        }
        if (is_link($symlinkDirectory)) {
            unlink($symlinkDirectory);
        }
        if (is_file($outsideFile)) {
            unlink($outsideFile);
        }
        foreach ($caseDirectories as $caseDirectory) {
            if (is_dir($caseDirectory)) {
                remove_tree($caseDirectory);
            }
        }
        if (is_dir($directory)) {
            remove_tree($directory);
        }
    }
});

test_case('readiness snapshot warns on configured non-operating calendar days', function (): void {
    $pdo = Database::connection();
    $set = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $service = new PlacementService($pdo);
    try {
        $set->execute(['calendar_non_operating_weekdays', '']);
        $set->execute(['calendar_non_operating_dates', '2026-07-04']);
        $companyId = $service->saveCompany(['code' => 'CAL', 'name' => 'Calendar Co'], 1);
        $roundId = $service->saveCompanyRound([
            'company_id' => $companyId,
            'sequence' => '1',
            'label' => 'Calendar Round',
            'round_type' => 'interview',
            'room' => 'Room CAL',
            'duration_minutes' => '30',
        ], 1);
        $service->saveRoundSchedule([
            'round_id' => $roundId,
            'sequence' => '1',
            'room' => 'Room CAL',
            'schedule_day' => '2026-07-04',
            'starts_at' => '09:00',
            'ends_at' => '09:30',
            'capacity' => '1',
        ], 1);

        $snapshot = (new ReadinessService($pdo))->snapshot();
        assert_true($snapshot['calendarWarnings']['count'] >= 1, 'Readiness should count round schedules on non-operating calendar days');
        $rows = array_values(array_filter($snapshot['calendarWarnings']['rows'], fn (array $row): bool => $row['company_code'] === 'CAL'));
        assert_same(1, count($rows), 'Readiness should include the configured calendar warning row');
        assert_same('2026-07-04', $rows[0]['resolved_date'], 'Readiness should resolve direct schedule dates');
        assert_same('date', $rows[0]['reason'], 'Readiness should explain date-based calendar guardrail warnings');
        assert_true(count(array_filter($snapshot['checks'], fn (array $check): bool => $check['label'] === 'Calendar guardrails' && $check['status'] === 'warn')) === 1, 'Readiness should surface calendar guardrails as a warning check');
    } finally {
        $set->execute(['calendar_non_operating_weekdays', '']);
        $set->execute(['calendar_non_operating_dates', '']);
    }
});

test_case('large synthetic demo seed creates resettable placement-day QA data', function (): void {
    $pdo = Database::connection();
    $service = new PlacementService($pdo);

    $first = $service->seedLargeDemo(24, 4);
    assert_same(24, $first['candidates'], 'Large seed candidate count');
    assert_same(4, $first['companies'], 'Large seed company count');
    assert_true($first['applications'] >= 28, 'Large seed should create multiple applications per some candidates');
    assert_same(8, $first['rounds'], 'Large seed should create two rounds per company');
    assert_same(16, $first['schedules'], 'Large seed should create four schedule rows per company');
    assert_same(16, $first['panelists'], 'Large seed should create four panelists per company');
    assert_true($first['slot_assignments'] > 0, 'Large seed should include some existing slot assignments');

    $suggestions = $service->slotAssignmentSuggestions('QA01');
    assert_true(count($suggestions) > 0, 'Large seed should leave unassigned QA01 applications for suggestion testing');
    $qaBoardRows = collect_apps($service->dashboard(['role' => 'admin'], ['q' => 'QA Candidate 001']));
    assert_true(count($qaBoardRows) >= 1, 'Large seed should be visible on the board search path');

    $second = $service->seedLargeDemo(24, 4);
    assert_same($first, $second, 'Large seed should reset deterministically when re-run');
    $snapshot = (new ReadinessService($pdo))->snapshot();
    assert_true(count($snapshot['checks']) >= 5, 'Readiness should still run after large seed reset');
});

$failed = 0;
foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}

if (is_file($tmp)) {
    unlink($tmp);
}
remove_tree($importTmp);
remove_tree($configTmp);
remove_tree($privacyTmp);

if ($failed > 0) {
    exit(1);
}
echo "All tests passed.\n";
