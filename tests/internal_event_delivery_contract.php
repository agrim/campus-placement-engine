<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$safeTempRoot = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
$testRoot = $safeTempRoot . '/cpe-internal-event-delivery-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create internal-event contract test root.');
}

$requestedDriver = strtolower(trim((string) (getenv('CPE_DB_DRIVER') ?: 'sqlite')));
$postgres = in_array($requestedDriver, ['pgsql', 'postgresql'], true)
    || trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== '';
$databasePath = $testRoot . '/engine.sqlite';
$structuredLogPath = $testRoot . '/structured.log';
$outboxPath = $testRoot . '/external-events.jsonl';
if (!$postgres) {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $databasePath);
}
putenv('CPE_LOG_PATH=' . $structuredLogPath);
putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH');
putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS');
putenv('CPE_DOMAIN_EVENT_FANOUT_MAX_ATTEMPTS');
putenv('CPE_DOMAIN_EVENT_LOCK_SECONDS');

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';

use App\Core\Events\DomainEvent;
use App\Core\Events\EventDispatcher;
use App\Core\Events\InternalEventDeliveryReplayService;
use App\Core\Events\InternalEventDeliveryWorker;
use App\Core\Events\InternalEventFanoutReplayService;
use App\Core\Events\InternalEventFanoutWorker;
use App\Core\Events\InternalEventSubscriberRegistry;
use App\Core\Events\InternalEventSubscription;
use App\Core\Http\UserVisibleException;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleLifecycleService;
use App\Core\Modules\ProvidesEventSubscribers;
use App\Core\Persistence\WriteTransaction;
use App\Core\Security\AuthorizationUnavailable;
use App\Core\Security\CapabilityService;
use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Support\Database;

function observer_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function observer_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')',
        );
    }
}

function observer_rejects(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $failure) {
        return $failure;
    }
    throw new RuntimeException($message);
}

function observer_event(string $name, string $aggregatePublicId, array $payload = ['contract' => true]): DomainEvent
{
    return new DomainEvent(
        $name,
        'observer_contract',
        $aggregatePublicId,
        'placement',
        $payload,
        cpe_now(),
    );
}

function observer_delivery(PDO $pdo, string $subscriptionId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM domain_event_deliveries WHERE subscription_id = ? ORDER BY id DESC LIMIT 1',
    );
    $stmt->execute([$subscriptionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Expected an internal observer delivery row for ' . $subscriptionId . '.');
    }
    return $row;
}

/** @return array{0: int, 1: string, 2: string} */
function observer_cli(string $projectRoot, array $arguments): array
{
    $process = proc_open(
        [PHP_BINARY, $projectRoot . '/placement', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectRoot,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start internal-event CLI contract process.');
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function observer_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . '/' . $entry;
        if (is_dir($child)) {
            observer_remove_tree($child);
        } elseif (is_file($child)) {
            unlink($child);
        }
    }
    rmdir($path);
}

final class ObserverTargetHealthyModule implements Module, ProvidesEventSubscribers
{
    public const CPE_MODULE_KEY = 'healthytest';
    public const CPE_MODULE_VERSION = '0.1.0';

    public static int $calls = 0;

    public function key(): string
    {
        return self::CPE_MODULE_KEY;
    }

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromArray($this->key(), observer_target_definitions()[$this->key()]);
    }

    public function routes(): array
    {
        return [];
    }

    public function navigation(): array
    {
        return [];
    }

    public function eventSubscribers(): array
    {
        return [new InternalEventSubscription(
            'internal.healthytest.target.v1',
            'placement.contract.target_only',
            $this->key(),
            static function (): void {
                ObserverTargetHealthyModule::$calls++;
            },
        )];
    }
}

final class ObserverUnrelatedPoisonModule implements Module, ProvidesEventSubscribers
{
    public const CPE_MODULE_KEY = 'poisontest';
    public const CPE_MODULE_VERSION = '0.1.0';

    public static int $constructorCalls = 0;

    public function __construct()
    {
        self::$constructorCalls++;
        throw new RuntimeException('Unrelated poison module must not be constructed for a healthy target delivery.');
    }

    public function key(): string
    {
        return self::CPE_MODULE_KEY;
    }

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromArray($this->key(), observer_target_definitions()[$this->key()]);
    }

    public function routes(): array
    {
        return [];
    }

    public function navigation(): array
    {
        return [];
    }

    public function eventSubscribers(): array
    {
        throw new RuntimeException('Unrelated poison declarations must not be resolved.');
    }
}

/** @return array<string, array<string, mixed>> */
function observer_target_definitions(): array
{
    $base = [
        'name' => 'Observer target contract',
        'version' => '0.1.0',
        'core_requires' => '>=0.1.0-alpha.1',
        'requires_modules' => [],
        'capabilities' => [],
        'enabled_by_default' => false,
        'description' => 'Synthetic target-only observer contract.',
        'internal_event_observer_events' => ['placement.contract.target_only'],
    ];
    return [
        'healthytest' => [
            ...$base,
            'capabilities' => ['healthytest.observe'],
            'class' => ObserverTargetHealthyModule::class,
        ],
        'poisontest' => [...$base, 'class' => ObserverUnrelatedPoisonModule::class],
    ];
}

$pdo = null;
$secondary = null;
try {
    (new SystemRequirements())->assertReady();
    $adminId = (new Installer())->install([
        'college_name' => 'Internal Observer Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'Observer Contract Administrator',
        'admin_email' => 'observer-contract@example.test',
        'admin_password' => 'observer-contract-password-123',
        'seed_demo' => '1',
    ]);

    $pdo = Database::connection();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $restrictedActorId = (int) $pdo->query(
        "SELECT id FROM users WHERE active = 1 AND role <> 'admin' ORDER BY id LIMIT 1",
    )->fetchColumn();
    observer_true($restrictedActorId > 0, 'Internal replay fixture has no real active restricted user.');
    $staleContext = cpe_context();
    $outboxBeforeVersionDrift = (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn();
    $fanoutBeforeVersionDrift = (int) $pdo->query('SELECT COUNT(*) FROM domain_event_module_fanout')->fetchColumn();
    $pdo->exec("UPDATE module_installations SET version = '9.9.9' WHERE module_key = 'placement'");
    try {
        (new EventDispatcher(
            $pdo,
            new ModuleRegistry((array) cpe_config('modules', []), $pdo),
        ))->dispatch(observer_event('placement.contract.version_drift', 'version_drift'));
        throw new RuntimeException('Version-drifted module state snapshotted event eligibility.');
    } catch (AuthorizationUnavailable $failure) {
        observer_same(
            AuthorizationUnavailable::MODULE_STATE,
            $failure->reason(),
            'Version drift returned the wrong typed module-state outage.',
        );
    }
    observer_same(
        $outboxBeforeVersionDrift,
        (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn(),
        'Version drift persisted an outbox row before rejecting eligibility.',
    );
    observer_same(
        $fanoutBeforeVersionDrift,
        (int) $pdo->query('SELECT COUNT(*) FROM domain_event_module_fanout')->fetchColumn(),
        'Version drift snapshotted module event eligibility.',
    );
    $pdo->exec("UPDATE module_installations SET version = '0.1.0' WHERE module_key = 'placement'");
    $pdo->exec(
        'CREATE TABLE observer_contract_effects (
            aggregate_public_id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL
        )',
    );
    if ($driver === 'pgsql') {
        $secondary = PostgresConnectionProvider::fromEnvironment()->connection();
    } else {
        $secondary = new PDO('sqlite:' . $databasePath);
        $secondary->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $secondary->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $secondary->exec('PRAGMA foreign_keys = ON');
        $secondary->exec('PRAGMA busy_timeout = 5000');
    }

    $indexes = $driver === 'pgsql'
        ? $pdo->query(
            "SELECT indexname FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename IN ('domain_event_module_fanout', 'domain_event_deliveries')",
        )->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'index'
             AND tbl_name IN ('domain_event_module_fanout', 'domain_event_deliveries')",
        )->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'idx_domain_event_module_fanout_available',
        'idx_domain_event_module_fanout_lease',
        'idx_domain_event_deliveries_available',
        'idx_domain_event_deliveries_lease',
    ] as $expectedIndex) {
        observer_true(in_array($expectedIndex, array_map('strval', $indexes), true), 'Missing worker index ' . $expectedIndex . '.');
    }
    $deliveryColumns = $driver === 'pgsql'
        ? $pdo->query(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = 'domain_event_deliveries'",
        )->fetchAll(PDO::FETCH_COLUMN)
        : array_column($pdo->query('PRAGMA table_info(domain_event_deliveries)')->fetchAll(), 'name');
    foreach (['module_key', 'skipped_at', 'replayed_at', 'replayed_by_user_id'] as $expectedColumn) {
        observer_true(in_array($expectedColumn, $deliveryColumns, true), 'Missing delivery lifecycle column ' . $expectedColumn . '.');
    }

    $targetDefinitions = observer_target_definitions();
    $targetInsert = $pdo->prepare(
        'INSERT INTO module_installations (module_key, version, enabled, installed_at, updated_at)
         VALUES (?, ?, 1, ?, ?)',
    );
    foreach (array_keys($targetDefinitions) as $targetModuleKey) {
        $targetInsert->execute([$targetModuleKey, '0.1.0', cpe_now(), cpe_now()]);
    }
    $implementationDriftDefinitions = $targetDefinitions;
    $implementationDriftDefinitions['healthytest']['version'] = '9.9.9';
    $pdo->exec("UPDATE module_installations SET version = '9.9.9' WHERE module_key = 'healthytest'");
    $implementationDriftRegistry = new ModuleRegistry($implementationDriftDefinitions, $pdo);
    $implementationDriftCapabilities = new CapabilityService(
        ['control' => ['healthytest.observe']],
        $implementationDriftRegistry,
    );
    try {
        $implementationDriftCapabilities->allows(
            ['role' => 'control', 'active' => 1],
            'healthytest.observe',
        );
        throw new RuntimeException('Implementation/config drift authorized a module capability.');
    } catch (AuthorizationUnavailable $failure) {
        observer_same(
            AuthorizationUnavailable::MODULE_STATE,
            $failure->reason(),
            'Implementation/config authorization drift returned the wrong typed outage.',
        );
    }
    $implementationDriftOutboxBefore = (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn();
    $implementationDriftFanoutBefore = (int) $pdo->query('SELECT COUNT(*) FROM domain_event_module_fanout')->fetchColumn();
    try {
        (new EventDispatcher($pdo, $implementationDriftRegistry))->dispatch(
            observer_event('placement.contract.target_only', 'implementation_drift'),
        );
        throw new RuntimeException('Implementation/config drift snapshotted event eligibility.');
    } catch (AuthorizationUnavailable $failure) {
        observer_same(
            AuthorizationUnavailable::MODULE_STATE,
            $failure->reason(),
            'Implementation/config fanout drift returned the wrong typed outage.',
        );
    }
    observer_same(
        $implementationDriftOutboxBefore,
        (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn(),
        'Implementation/config drift persisted an outbox row.',
    );
    observer_same(
        $implementationDriftFanoutBefore,
        (int) $pdo->query('SELECT COUNT(*) FROM domain_event_module_fanout')->fetchColumn(),
        'Implementation/config drift snapshotted event fanout.',
    );
    $implementationDriftManager = new ModuleManager(
        $implementationDriftRegistry,
        $implementationDriftCapabilities,
    );
    try {
        $implementationDriftManager->modulesForKeys(['healthytest']);
        throw new RuntimeException('Configured module version drifted from implementation without failing.');
    } catch (AuthorizationUnavailable $failure) {
        observer_same(
            AuthorizationUnavailable::MODULE_STATE,
            $failure->reason(),
            'Configured/implementation version drift returned the wrong typed outage.',
        );
    }
    $pdo->exec("UPDATE module_installations SET version = '0.1.0' WHERE module_key = 'healthytest'");
    $targetEventPublicId = 'event_' . bin2hex(random_bytes(16));
    $targetOccurredAt = cpe_now();
    $targetOutbox = $pdo->prepare(
        'INSERT INTO domain_event_outbox
         (public_id, event_name, aggregate_type, aggregate_public_id, institution_id, module_key,
          payload_json, occurred_at, available_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $targetOutbox->execute([
        $targetEventPublicId,
        'placement.contract.target_only',
        'observer_contract',
        'target_only',
        (int) $pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn(),
        'placement',
        '{}',
        $targetOccurredAt,
        $targetOccurredAt,
    ]);
    $targetEventId = Database::lastInsertId($pdo);
    $targetDelivery = $pdo->prepare(
        'INSERT INTO domain_event_deliveries
         (event_id, subscription_id, module_key, status, attempt_count, available_at, last_error, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)',
    );
    $targetDelivery->execute([
        $targetEventId,
        'internal.healthytest.target.v1',
        'healthytest',
        'pending',
        $targetOccurredAt,
        '',
        $targetOccurredAt,
        $targetOccurredAt,
    ]);
    $targetRegistry = new ModuleRegistry($targetDefinitions, $pdo);
    $targetCapabilities = CapabilityService::fromDatabase($pdo, $targetRegistry);
    $targetManager = new ModuleManager($targetRegistry, $targetCapabilities);
    $targetResult = (new InternalEventDeliveryWorker(
        $pdo,
        null,
        $targetManager,
        $targetRegistry,
    ))->work(10);
    observer_same(1, $targetResult['delivered'], 'Healthy target delivery did not complete through production module resolution.');
    observer_same(1, ObserverTargetHealthyModule::$calls, 'Healthy target observer did not run exactly once.');
    observer_same(0, ObserverUnrelatedPoisonModule::$constructorCalls, 'Unrelated poison module was constructed for a healthy target delivery.');
    $pdo->exec('DELETE FROM domain_event_deliveries WHERE event_id = ' . $targetEventId);
    $pdo->exec('DELETE FROM domain_event_outbox WHERE id = ' . $targetEventId);
    $pdo->exec("DELETE FROM module_installations WHERE module_key IN ('healthytest', 'poisontest')");

    $dispatcherSource = (string) file_get_contents($projectRoot . '/app/Core/Events/EventDispatcher.php');
    observer_true(
        !str_contains($dispatcherSource, 'function markProcessed')
            && !str_contains($dispatcherSource, 'function markFailed'),
        'EventDispatcher retained an unfenced legacy outbox mutation path.',
    );
    observer_true(
        str_contains((string) file_get_contents($projectRoot . '/app/Core/Events/InternalEventFanoutWorker.php'), 'FOR UPDATE SKIP LOCKED')
            && str_contains((string) file_get_contents($projectRoot . '/app/Core/Events/InternalEventDeliveryWorker.php'), 'FOR UPDATE SKIP LOCKED'),
        'PostgreSQL worker leases are not visibly SKIP LOCKED based.',
    );
    $bundledInstances = $staleContext->moduleManager()->modulesForKeys(array_keys((array) cpe_config('modules', [])));
    $bundledRegistry = InternalEventSubscriberRegistry::fromModuleInstances($bundledInstances);
    foreach ((array) cpe_config('modules', []) as $moduleKey => $definition) {
        $metadataEvents = array_values(array_unique((array) ($definition['internal_event_observer_events'] ?? [])));
        sort($metadataEvents);
        observer_same(
            $metadataEvents,
            $bundledRegistry->eventNamesForModule((string) $moduleKey),
            'Bundled observer code drifted from immutable eligibility metadata for ' . $moduleKey . '.',
        );
    }

    $poisonCalls = 0;
    $healthyCalls = 0;
    $poisonEnabled = true;
    $poisonSentinel = 'observer-secret-poison-sentinel';
    $registry = new InternalEventSubscriberRegistry([
        new InternalEventSubscription(
            'internal.contract.poison.v1',
            'placement.contract.observed',
            'contract',
            static function () use (&$poisonCalls, &$poisonEnabled, $poisonSentinel): void {
                $poisonCalls++;
                if ($poisonEnabled) {
                    throw new RuntimeException($poisonSentinel);
                }
            },
        ),
        new InternalEventSubscription(
            'internal.contract.healthy.v1',
            'placement.contract.observed',
            'contract',
            static function (DomainEvent $event) use (&$healthyCalls, $pdo): void {
                $healthyCalls++;
                $stmt = $pdo->prepare(
                    'INSERT INTO observer_contract_effects (aggregate_public_id, created_at) VALUES (?, ?)',
                );
                $stmt->execute([$event->aggregatePublicId, cpe_now()]);
            },
        ),
    ]);
    $dispatcher = new EventDispatcher($pdo, $staleContext->modules(), $registry);
    $aggregateId = 'observer_' . bin2hex(random_bytes(12));
    $publicId = $dispatcher->dispatch(observer_event('placement.contract.observed', $aggregateId));
    observer_same(0, $poisonCalls + $healthyCalls, 'Publishing invoked observer code in the source transaction.');
    observer_same(1, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox WHERE public_id = ' . $pdo->quote($publicId))->fetchColumn(), 'Publishing lost the immutable outbox row.');
    observer_same(1, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_module_fanout')->fetchColumn(), 'Publishing did not persist exact module eligibility.');
    observer_same(0, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_deliveries')->fetchColumn(), 'Publishing constructed declarations or deliveries in the source transaction.');

    $fanoutResult = (new InternalEventFanoutWorker($pdo, $registry))->work(10);
    observer_same(1, $fanoutResult['expanded'], 'Post-commit fanout did not expand the eligible module.');
    observer_same(2, $fanoutResult['deliveries_created'], 'Post-commit fanout did not create one row per stable subscription.');
    observer_same(0, $poisonCalls + $healthyCalls, 'Fanout invoked an observer callback.');

    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS=1');
    $firstResult = (new InternalEventDeliveryWorker($pdo, $registry))->work(10);
    observer_same(2, $firstResult['claimed'], 'Delivery worker did not isolate both observer rows.');
    observer_same(1, $firstResult['delivered'], 'Healthy observer was not delivered beside a poison observer.');
    observer_same(1, $firstResult['dead_lettered'], 'Poison observer did not reach bounded dead letter.');
    observer_same(1, $healthyCalls, 'Healthy observer did not execute exactly once.');
    observer_same(1, (int) $pdo->query('SELECT COUNT(*) FROM observer_contract_effects')->fetchColumn(), 'Observer callback could not perform post-commit database I/O.');
    $poisonRow = observer_delivery($pdo, 'internal.contract.poison.v1');
    observer_true(
        preg_match('/^CPE_INTERNAL_EVENT_OBSERVER_FAILED Reference: inc_[a-f0-9]{32}$/D', (string) $poisonRow['last_error']) === 1,
        'Poison callback detail was not replaced by an opaque incident reference.',
    );
    observer_true(!str_contains((string) $poisonRow['last_error'], $poisonSentinel), 'Dead letter exposed callback details.');

    $poisonEnabled = false;
    $restrictedDeliveryReplay = observer_rejects(
        static fn (): array => (new InternalEventDeliveryReplayService($pdo))->replay(
            $publicId,
            'internal.contract.poison.v1',
            $restrictedActorId,
        ),
        'Active restricted user was accepted for internal delivery replay.',
    );
    observer_true(
        $restrictedDeliveryReplay instanceof UserVisibleException
            && $restrictedDeliveryReplay->publicCode() === 'INTERNAL_EVENT_REPLAY_ACTOR_INVALID'
            && $restrictedDeliveryReplay->publicMessage() === 'An active administrator user ID is required.',
        'Internal delivery replay did not use the stable redacted administrator failure.',
    );
    [$restrictedDeliveryExit, $restrictedDeliveryOutput, $restrictedDeliveryError] = observer_cli($projectRoot, [
        'replay-internal-delivery',
        '--event=' . $publicId,
        '--subscription=internal.contract.poison.v1',
        '--actor-user-id=' . $restrictedActorId,
    ]);
    observer_same(1, $restrictedDeliveryExit, 'Restricted internal delivery replay CLI should fail.');
    observer_same('', $restrictedDeliveryOutput, 'Restricted internal delivery replay CLI reported a transition.');
    observer_same(
        'Error: An active administrator user ID is required.' . "\n",
        $restrictedDeliveryError,
        'Restricted internal delivery replay CLI did not use the redacted actor failure.',
    );
    [$missingDeliveryExit, $missingDeliveryOutput, $missingDeliveryError] = observer_cli($projectRoot, [
        'replay-internal-delivery',
        '--event=' . $publicId,
        '--subscription=internal.contract.poison.v1',
    ]);
    observer_same(1, $missingDeliveryExit, 'Missing internal delivery replay actor should fail.');
    observer_same('', $missingDeliveryOutput, 'Missing internal delivery replay actor reported a transition.');
    observer_same(
        'Error: An active administrator user ID is required.' . "\n",
        $missingDeliveryError,
        'Missing internal delivery replay actor did not use the redacted failure.',
    );
    observer_same('dead_lettered', (string) observer_delivery($pdo, 'internal.contract.poison.v1')['status'], 'Denied delivery replay changed queue state.');
    observer_same(0, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'internal_event_delivery.replay'")->fetchColumn(), 'Denied delivery replay wrote false audit attribution.');
    [$replayExit, $replayOutput, $replayError] = observer_cli($projectRoot, [
        'replay-internal-delivery',
        '--event=' . $publicId,
        '--subscription=internal.contract.poison.v1',
        '--actor-user-id=' . $adminId,
    ]);
    observer_same(0, $replayExit, 'Audited delivery replay CLI failed: ' . $replayError);
    observer_true(str_contains($replayOutput, 'replay replayed'), 'Delivery replay did not report its state transition.');
    [$repeatExit, $repeatOutput, $repeatError] = observer_cli($projectRoot, [
        'replay-internal-delivery',
        '--event=' . $publicId,
        '--subscription=internal.contract.poison.v1',
        '--actor-user-id=' . $adminId,
    ]);
    observer_same(0, $repeatExit, 'Repeated delivery replay was not idempotent: ' . $repeatError);
    observer_true(str_contains($repeatOutput, 'already-replayed'), 'Repeated delivery replay did not report idempotency.');
    observer_same(
        1,
        (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'internal_event_delivery.replay'")->fetchColumn(),
        'Idempotent replay wrote duplicate audit entries.',
    );
    $deliveryAudit = $pdo->query(
        "SELECT actor_user_id, detail FROM audit_logs
         WHERE action = 'internal_event_delivery.replay' ORDER BY id DESC LIMIT 1",
    )->fetch(PDO::FETCH_ASSOC);
    observer_true(is_array($deliveryAudit), 'Delivery replay audit is missing.');
    observer_same($adminId, (int) $deliveryAudit['actor_user_id'], 'Delivery replay audit did not attribute the administrator.');
    observer_same('Dead-lettered internal observer delivery replayed.', $deliveryAudit['detail'], 'Replay audit detail was not fixed and payload-free.');
    observer_same(1, (new InternalEventDeliveryWorker($pdo, $registry))->work(10)['delivered'], 'Replayed delivery did not return to normal leasing.');

    $beforeOutbox = (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn();
    $beforeFanout = (int) $pdo->query('SELECT COUNT(*) FROM domain_event_module_fanout')->fetchColumn();
    try {
        WriteTransaction::run($pdo, static function () use ($dispatcher): void {
            $dispatcher->dispatch(observer_event('placement.contract.observed', 'rollback_' . bin2hex(random_bytes(12))));
            throw new RuntimeException('force source rollback');
        });
        throw new RuntimeException('Expected source transaction rollback.');
    } catch (RuntimeException $failure) {
        observer_same('force source rollback', $failure->getMessage(), 'Source rollback returned the wrong failure.');
    }
    observer_same($beforeOutbox, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn(), 'Source rollback retained an outbox row.');
    observer_same($beforeFanout, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_module_fanout')->fetchColumn(), 'Source rollback retained module eligibility.');

    $faultRegistry = new InternalEventSubscriberRegistry([
        new InternalEventSubscription(
            'internal.atomicfault.observer.v1',
            'placement.contract.atomic_fault',
            'atomicfault',
            static function (): void {
                throw new RuntimeException('Atomic fanout callback must never run.');
            },
        ),
    ]);
    if ($driver === 'pgsql') {
        $pdo->exec(
            "CREATE FUNCTION observer_contract_reject_fanout() RETURNS trigger LANGUAGE plpgsql AS \$body\$
             BEGIN
                 IF NEW.module_key = 'atomicfault' THEN
                     RAISE EXCEPTION 'forced observer fanout persistence failure';
                 END IF;
                 RETURN NEW;
             END
             \$body\$",
        );
        $pdo->exec(
            'CREATE TRIGGER observer_contract_fanout_fault BEFORE INSERT ON domain_event_module_fanout
             FOR EACH ROW EXECUTE FUNCTION observer_contract_reject_fanout()',
        );
    } else {
        $pdo->exec(
            "CREATE TRIGGER observer_contract_fanout_fault
             BEFORE INSERT ON domain_event_module_fanout
             WHEN NEW.module_key = 'atomicfault'
             BEGIN
                 SELECT RAISE(ABORT, 'forced observer fanout persistence failure');
             END",
        );
    }
    $faultAggregate = 'atomic_fault_' . bin2hex(random_bytes(10));
    try {
        (new EventDispatcher($pdo, $staleContext->modules(), $faultRegistry))->dispatch(
            observer_event('placement.contract.atomic_fault', $faultAggregate),
        );
        throw new RuntimeException('Expected durable fanout persistence failure.');
    } catch (Throwable $failure) {
        observer_true(str_contains($failure->getMessage(), 'forced observer fanout persistence failure'), 'Atomic fanout fault did not reach the source boundary.');
    }
    $pdo->exec($driver === 'pgsql'
        ? 'DROP TRIGGER observer_contract_fanout_fault ON domain_event_module_fanout'
        : 'DROP TRIGGER observer_contract_fanout_fault');
    if ($driver === 'pgsql') {
        $pdo->exec('DROP FUNCTION observer_contract_reject_fanout()');
    }
    observer_same(0, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox WHERE aggregate_public_id = ' . $pdo->quote($faultAggregate))->fetchColumn(), 'Fanout persistence fault retained an orphaned outbox row.');

    $declarationHealthyCalls = 0;
    $declarationPoisonCalls = 0;
    $declarationRegistry = new InternalEventSubscriberRegistry([
        new InternalEventSubscription(
            'internal.healthymod.observer.v1',
            'placement.contract.declaration',
            'healthymod',
            static function () use (&$declarationHealthyCalls): void { $declarationHealthyCalls++; },
        ),
        new InternalEventSubscription(
            'internal.poisonmod.observer.v1',
            'placement.contract.declaration',
            'poisonmod',
            static function () use (&$declarationPoisonCalls): void { $declarationPoisonCalls++; },
        ),
    ]);
    $declarationPublicId = (new EventDispatcher($pdo, $staleContext->modules(), $declarationRegistry))->dispatch(
        observer_event('placement.contract.declaration', 'declaration_' . bin2hex(random_bytes(12))),
    );
    $declarationBroken = true;
    $declarationSentinel = 'module-declaration-secret-sentinel';
    $declarationResolver = static function (string $moduleKey) use (
        &$declarationBroken,
        $declarationRegistry,
        $declarationSentinel,
    ): InternalEventSubscriberRegistry {
        if ($moduleKey === 'poisonmod' && $declarationBroken) {
            throw new RuntimeException($declarationSentinel);
        }
        return $declarationRegistry;
    };
    putenv('CPE_DOMAIN_EVENT_FANOUT_MAX_ATTEMPTS=1');
    $declarationResult = (new InternalEventFanoutWorker($pdo, null, $declarationResolver))->work(10);
    observer_same(1, $declarationResult['expanded'], 'Healthy declaration neighbor did not expand.');
    observer_same(1, $declarationResult['dead_lettered'], 'Poison declaration did not reach an operable dead letter.');
    observer_same(1, $declarationResult['deliveries_created'], 'Poison declaration affected healthy delivery cardinality.');
    observer_same(0, $declarationHealthyCalls + $declarationPoisonCalls, 'Declaration fanout invoked callbacks.');
    observer_true(!str_contains(json_encode($declarationResult, JSON_THROW_ON_ERROR), $declarationSentinel), 'Declaration worker result exposed failure detail.');

    $declarationBroken = false;
    $restrictedFanoutReplay = observer_rejects(
        static fn (): array => (new InternalEventFanoutReplayService($pdo))->replay(
            $declarationPublicId,
            'poisonmod',
            $restrictedActorId,
        ),
        'Active restricted user was accepted for internal fanout replay.',
    );
    observer_true(
        $restrictedFanoutReplay instanceof UserVisibleException
            && $restrictedFanoutReplay->publicCode() === 'INTERNAL_EVENT_FANOUT_REPLAY_ACTOR_INVALID'
            && $restrictedFanoutReplay->publicMessage() === 'An active administrator user ID is required.',
        'Internal fanout replay did not use the stable redacted administrator failure.',
    );
    [$restrictedFanoutExit, $restrictedFanoutOutput, $restrictedFanoutError] = observer_cli($projectRoot, [
        'replay-internal-fanout',
        '--event=' . $declarationPublicId,
        '--module=poisonmod',
        '--actor-user-id=' . $restrictedActorId,
    ]);
    observer_same(1, $restrictedFanoutExit, 'Restricted internal fanout replay CLI should fail.');
    observer_same('', $restrictedFanoutOutput, 'Restricted internal fanout replay CLI reported a transition.');
    observer_same(
        'Error: An active administrator user ID is required.' . "\n",
        $restrictedFanoutError,
        'Restricted internal fanout replay CLI did not use the redacted actor failure.',
    );
    observer_same(
        'dead_lettered',
        (string) $pdo->query(
            "SELECT status FROM domain_event_module_fanout
             WHERE event_id = (SELECT id FROM domain_event_outbox WHERE public_id = " . $pdo->quote($declarationPublicId) . ")
               AND module_key = 'poisonmod'",
        )->fetchColumn(),
        'Denied fanout replay changed queue state.',
    );
    observer_same(0, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'internal_event_fanout.replay'")->fetchColumn(), 'Denied fanout replay wrote false audit attribution.');
    [$fanoutReplayExit, $fanoutReplayOutput, $fanoutReplayError] = observer_cli($projectRoot, [
        'replay-internal-fanout',
        '--event=' . $declarationPublicId,
        '--module=poisonmod',
        '--actor-user-id=' . $adminId,
    ]);
    observer_same(0, $fanoutReplayExit, 'Audited fanout replay CLI failed: ' . $fanoutReplayError);
    observer_true(str_contains($fanoutReplayOutput, 'replay replayed'), 'Fanout replay did not report its state transition.');
    [$fanoutRepeatExit, $fanoutRepeatOutput, $fanoutRepeatError] = observer_cli($projectRoot, [
        'replay-internal-fanout',
        '--event=' . $declarationPublicId,
        '--module=poisonmod',
        '--actor-user-id=' . $adminId,
    ]);
    observer_same(0, $fanoutRepeatExit, 'Repeated fanout replay was not idempotent: ' . $fanoutRepeatError);
    observer_true(str_contains($fanoutRepeatOutput, 'already-replayed'), 'Repeated fanout replay did not report idempotency.');
    observer_same(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'internal_event_fanout.replay'")->fetchColumn(), 'Idempotent fanout replay wrote duplicate audit entries.');
    $fanoutAudit = $pdo->query(
        "SELECT actor_user_id, detail FROM audit_logs
         WHERE action = 'internal_event_fanout.replay' ORDER BY id DESC LIMIT 1",
    )->fetch(PDO::FETCH_ASSOC);
    observer_true(is_array($fanoutAudit), 'Fanout replay audit is missing.');
    observer_same($adminId, (int) $fanoutAudit['actor_user_id'], 'Fanout replay audit did not attribute the administrator.');
    observer_same('Dead-lettered internal observer fanout replayed.', $fanoutAudit['detail'], 'Fanout replay audit detail was not fixed and payload-free.');
    $fanoutReplay = (new InternalEventFanoutWorker($pdo, null, $declarationResolver))->work(10);
    observer_same(1, $fanoutReplay['expanded'], 'Corrected declaration did not recover through normal fanout leasing.');
    observer_same(1, $fanoutReplay['deliveries_created'], 'Corrected declaration replay did not create its stable delivery.');
    $declarationDelivery = (new InternalEventDeliveryWorker($pdo, $declarationRegistry))->work(10);
    observer_same(2, $declarationDelivery['delivered'], 'Recovered declaration deliveries were not isolated and delivered.');
    putenv('CPE_DOMAIN_EVENT_FANOUT_MAX_ATTEMPTS');

    $fanoutLeaseRegistry = new InternalEventSubscriberRegistry([
        new InternalEventSubscription(
            'internal.leasemod.observer.v1',
            'placement.contract.fanout_lease',
            'leasemod',
            static function (): void {},
        ),
    ]);
    $fanoutLeasePublicId = (new EventDispatcher($pdo, $staleContext->modules(), $fanoutLeaseRegistry))->dispatch(
        observer_event('placement.contract.fanout_lease', 'fanout_lease_' . bin2hex(random_bytes(12))),
    );
    $stealingResolver = static function (string $moduleKey) use ($secondary, $fanoutLeaseRegistry): InternalEventSubscriberRegistry {
        $stmt = $secondary->prepare(
            "UPDATE domain_event_module_fanout SET lock_token = 'stolen_fanout'
             WHERE module_key = ? AND status IN ('pending', 'retrying') AND locked_at IS NOT NULL",
        );
        $stmt->execute([$moduleKey]);
        return $fanoutLeaseRegistry;
    };
    $fanoutLeaseResult = (new InternalEventFanoutWorker($pdo, null, $stealingResolver))->work(10);
    observer_same(1, $fanoutLeaseResult['claim_lost'], 'Stale fanout expansion was not fenced.');
    observer_same(
        0,
        (int) $pdo->query(
            'SELECT COUNT(*) FROM domain_event_deliveries d JOIN domain_event_outbox e ON e.id = d.event_id WHERE e.public_id = ' . $pdo->quote($fanoutLeasePublicId),
        )->fetchColumn(),
        'Stale fanout claim committed an unfenced delivery row.',
    );
    $pdo->prepare(
        "UPDATE domain_event_module_fanout
         SET locked_at = ?, lock_token = 'expired_fanout_process'
         WHERE event_id = (SELECT id FROM domain_event_outbox WHERE public_id = ?)",
    )->execute([gmdate('Y-m-d H:i:s', time() - 60), $fanoutLeasePublicId]);
    putenv('CPE_DOMAIN_EVENT_LOCK_SECONDS=30');
    $fanoutRecovery = (new InternalEventFanoutWorker($pdo, $fanoutLeaseRegistry))->work(10);
    observer_same(1, $fanoutRecovery['claimed'], 'Fresh worker did not reclaim expired fanout process lease.');
    observer_same(1, $fanoutRecovery['expanded'], 'Reclaimed fanout process lease did not expand normally.');
    observer_same(1, (new InternalEventDeliveryWorker($pdo, $fanoutLeaseRegistry))->work(10)['delivered'], 'Recovered fanout delivery did not complete.');

    if ($driver === 'pgsql') {
        $contentionRegistry = new InternalEventSubscriberRegistry([
            new InternalEventSubscription(
                'internal.contentionmod.observer.v1',
                'placement.contract.contention',
                'contentionmod',
                static function (): void {},
            ),
        ]);
        $contentionPublicId = (new EventDispatcher($pdo, $staleContext->modules(), $contentionRegistry))->dispatch(
            observer_event('placement.contract.contention', 'contention_' . bin2hex(random_bytes(12))),
        );
        $secondary->beginTransaction();
        $locked = $secondary->prepare(
            'SELECT f.id FROM domain_event_module_fanout f JOIN domain_event_outbox e ON e.id = f.event_id
             WHERE e.public_id = ? FOR UPDATE',
        );
        $locked->execute([$contentionPublicId]);
        observer_same(0, (new InternalEventFanoutWorker($pdo, $contentionRegistry))->work(1)['claimed'], 'SKIP LOCKED worker claimed a row leased by another PostgreSQL connection.');
        $secondary->rollBack();
        observer_same(1, (new InternalEventFanoutWorker($pdo, $contentionRegistry))->work(1)['expanded'], 'Released PostgreSQL fanout row was not claimable.');
        observer_same(1, (new InternalEventDeliveryWorker($pdo, $contentionRegistry))->work(1)['delivered'], 'PostgreSQL contention fixture delivery did not complete.');
    }

    $staleSuccessId = 'internal.contract.stale_success.v1';
    $staleFailureId = 'internal.contract.stale_failure.v1';
    $stealDeliveryLeases = true;
    $staleFailureEnabled = true;
    $staleRegistry = new InternalEventSubscriberRegistry([
        new InternalEventSubscription(
            $staleSuccessId,
            'placement.contract.lease_fence',
            'contract',
            static function () use ($secondary, $staleSuccessId, &$stealDeliveryLeases): void {
                if ($stealDeliveryLeases) {
                    $stmt = $secondary->prepare("UPDATE domain_event_deliveries SET lock_token = 'stolen_success' WHERE subscription_id = ? AND locked_at IS NOT NULL");
                    $stmt->execute([$staleSuccessId]);
                }
            },
        ),
        new InternalEventSubscription(
            $staleFailureId,
            'placement.contract.lease_fence',
            'contract',
            static function () use ($secondary, $staleFailureId, &$stealDeliveryLeases, &$staleFailureEnabled): void {
                if ($stealDeliveryLeases) {
                    $stmt = $secondary->prepare("UPDATE domain_event_deliveries SET lock_token = 'stolen_failure' WHERE subscription_id = ? AND locked_at IS NOT NULL");
                    $stmt->execute([$staleFailureId]);
                }
                if ($staleFailureEnabled) {
                    throw new RuntimeException('stale failure sentinel');
                }
            },
        ),
    ]);
    (new EventDispatcher($pdo, $staleContext->modules(), $staleRegistry))->dispatch(
        observer_event('placement.contract.lease_fence', 'lease_' . bin2hex(random_bytes(12))),
    );
    observer_same(1, (new InternalEventFanoutWorker($pdo, $staleRegistry))->work(10)['expanded'], 'Stale delivery fixture fanout did not expand.');
    $staleResult = (new InternalEventDeliveryWorker($pdo, $staleRegistry))->work(10);
    observer_same(2, $staleResult['claim_lost'], 'Stale success and failure delivery leases were not fenced.');
    observer_same(2, $staleResult['outcome_unknown'], 'Stale delivery leases did not report unknown outcomes.');
    observer_same('pending', (string) observer_delivery($pdo, $staleSuccessId)['status'], 'Stale success lease mutated delivery state.');
    observer_same('pending', (string) observer_delivery($pdo, $staleFailureId)['status'], 'Stale failure lease mutated delivery state.');
    $stealDeliveryLeases = false;
    $staleFailureEnabled = false;
    $pdo->prepare(
        "UPDATE domain_event_deliveries
         SET locked_at = ?, lock_token = 'expired_delivery_process'
         WHERE subscription_id IN (?, ?) AND status = 'pending'",
    )->execute([gmdate('Y-m-d H:i:s', time() - 60), $staleSuccessId, $staleFailureId]);
    $deliveryRecovery = (new InternalEventDeliveryWorker($pdo, $staleRegistry))->work(10);
    observer_same(2, $deliveryRecovery['claimed'], 'Fresh worker did not reclaim expired delivery process leases.');
    observer_same(2, $deliveryRecovery['delivered'], 'Reclaimed delivery process leases did not complete normally.');
    putenv('CPE_DOMAIN_EVENT_LOCK_SECONDS');

    $invalidCalls = 0;
    $invalidRegistry = new InternalEventSubscriberRegistry([
        new InternalEventSubscription(
            'internal.contract.safe_reconstruction.v1',
            'placement.contract.safe_reconstruction',
            'contract',
            static function () use (&$invalidCalls): void { $invalidCalls++; },
        ),
    ]);
    $invalidPublicId = (new EventDispatcher($pdo, $staleContext->modules(), $invalidRegistry))->dispatch(
        observer_event('placement.contract.safe_reconstruction', 'invalid_' . bin2hex(random_bytes(12))),
    );
    observer_same(1, (new InternalEventFanoutWorker($pdo, $invalidRegistry))->work(10)['expanded'], 'Invalid payload fixture fanout did not expand.');
    $pdo->prepare('UPDATE domain_event_outbox SET payload_json = ? WHERE public_id = ?')->execute(['{"unterminated":', $invalidPublicId]);
    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS=1');
    observer_same(1, (new InternalEventDeliveryWorker($pdo, $invalidRegistry))->work(10)['dead_lettered'], 'Invalid persisted event was not safely rejected.');
    observer_same(0, $invalidCalls, 'Invalid persisted event reached its callback.');

    $unknownId = 'internal.contract.unknown_module.v1';
    $unknownRegistry = new InternalEventSubscriberRegistry([
        new InternalEventSubscription(
            $unknownId,
            'placement.contract.unknown_module',
            'contract',
            static function (): void { throw new RuntimeException('Unknown module callback ran.'); },
        ),
    ]);
    (new EventDispatcher($pdo, $staleContext->modules(), $unknownRegistry))->dispatch(
        observer_event('placement.contract.unknown_module', 'unknown_' . bin2hex(random_bytes(12))),
    );
    observer_same(1, (new InternalEventFanoutWorker($pdo, $unknownRegistry))->work(10)['expanded'], 'Unknown module fixture fanout did not expand.');
    $pdo->prepare('UPDATE domain_event_deliveries SET module_key = ? WHERE subscription_id = ?')->execute(['ghostmodule', $unknownId]);
    observer_same(1, (new InternalEventDeliveryWorker($pdo))->work(10)['dead_lettered'], 'Unknown delivery module identity was not treated as failure.');
    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS');

    (new ModuleLifecycleService($pdo))->enable('advising', $adminId);
    $candidatePublicId = (string) $pdo->query(
        'SELECT c.public_id FROM candidates c
         JOIN people p ON p.legacy_candidate_id = c.id
         JOIN student_profiles sp ON sp.person_id = p.id
         ORDER BY sp.id LIMIT 1',
    )->fetchColumn();
    observer_true($candidatePublicId !== '', 'Advising idempotency contract requires a demo student.');
    $advisingAggregate = 'application_' . bin2hex(random_bytes(16));
    $advisingEvent = observer_event(
        'placement.offer.accepted',
        $advisingAggregate,
        ['candidate_public_id' => $candidatePublicId],
    );
    $staleContext->events()->dispatch($advisingEvent);
    $staleContext->events()->dispatch($advisingEvent);
    observer_same(2, (int) $pdo->query("SELECT COUNT(*) FROM domain_event_module_fanout WHERE module_key = 'advising' AND status = 'pending'")->fetchColumn(), 'A stale context omitted newly enabled durable eligibility.');
    $advisingFanout = (new InternalEventFanoutWorker($pdo))->work(10);
    observer_same(2, $advisingFanout['expanded'], 'Production advising declarations did not expand after enablement.');
    observer_same(2, (new InternalEventDeliveryWorker($pdo))->work(10)['delivered'], 'Advising observer deliveries did not complete.');
    observer_same(1, (int) $pdo->query('SELECT COUNT(*) FROM advising_tasks')->fetchColumn(), 'Advising observer was not idempotent across duplicate events.');

    $disablePublicId = $staleContext->events()->dispatch(observer_event(
        'placement.offer.accepted',
        'application_' . bin2hex(random_bytes(16)),
        ['candidate_public_id' => $candidatePublicId],
    ));
    $lifecycle = new ModuleLifecycleService($pdo);
    $lifecycle->disable('advising', $adminId);
    observer_same(1, (new InternalEventFanoutWorker($pdo))->work(10)['expanded'], 'Disable-after-persist erased durable observer eligibility.');
    $disabledResult = (new InternalEventDeliveryWorker($pdo))->work(10);
    observer_same(1, $disabledResult['skipped'], 'Later-disabled module did not reach non-failure skipped state.');
    observer_same(0, $disabledResult['failed'] + $disabledResult['retrying'] + $disabledResult['dead_lettered'], 'Disabled module consumed failure budget.');
    $disabledStatus = $pdo->query(
        'SELECT d.status FROM domain_event_deliveries d JOIN domain_event_outbox e ON e.id = d.event_id
         WHERE e.public_id = ' . $pdo->quote($disablePublicId),
    )->fetchColumn();
    observer_same('skipped', $disabledStatus, 'Disabled delivery terminal state was not durable.');
    $disabledFanoutCount = (int) $pdo->query("SELECT COUNT(*) FROM domain_event_module_fanout WHERE module_key = 'advising'")->fetchColumn();
    $staleContext->events()->dispatch(observer_event(
        'placement.offer.accepted',
        'application_' . bin2hex(random_bytes(16)),
        ['candidate_public_id' => $candidatePublicId],
    ));
    observer_same($disabledFanoutCount, (int) $pdo->query("SELECT COUNT(*) FROM domain_event_module_fanout WHERE module_key = 'advising'")->fetchColumn(), 'Stale context persisted disabled module eligibility.');
    $lifecycle->enable('advising', $adminId);

    $pdo->prepare('UPDATE domain_event_outbox SET processed_at = ?, delivered_to = ? WHERE processed_at IS NULL')->execute([cpe_now(), 'contract-prior']);
    $cliPublicId = $staleContext->events()->dispatch(observer_event(
        'placement.offer.accepted',
        'application_' . bin2hex(random_bytes(16)),
        ['candidate_public_id' => $candidatePublicId],
    ));
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH=' . $outboxPath);
    [$outboxExit, $outboxOutput, $outboxError] = observer_cli($projectRoot, ['work-outbox', '--limit=100']);
    observer_same(0, $outboxExit, 'Paired outbox CLI failed: ' . $outboxError);
    observer_true(str_contains($outboxOutput, 'Domain events claimed: 0'), 'CLI exposed a private internal event to external delivery.');
    observer_true(str_contains($outboxOutput, 'Internal fanout expanded: 1'), 'CLI did not run post-commit internal fanout.');
    observer_true(str_contains($outboxOutput, 'Internal observers delivered: 1'), 'CLI did not deliver expanded internal observer work.');
    $externalLines = is_file($outboxPath)
        ? (file($outboxPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        : [];
    observer_same(0, count($externalLines), 'Private internal event leaked to the external file sink.');
    observer_same(
        null,
        $pdo->query('SELECT processed_at FROM domain_event_outbox WHERE public_id = ' . $pdo->quote($cliPublicId))->fetchColumn() ?: null,
        'External worker acknowledged a private internal event.',
    );
    observer_same(2, (int) $pdo->query('SELECT COUNT(*) FROM advising_tasks')->fetchColumn(), 'CLI did not execute the internal Advising observer.');

    $structuredLog = is_file($structuredLogPath) ? (string) file_get_contents($structuredLogPath) : '';
    observer_true(!str_contains($structuredLog, $poisonSentinel), 'Structured callback incident log exposed callback detail.');
    observer_true(!str_contains($structuredLog, $declarationSentinel), 'Structured declaration incident log exposed declaration detail.');

    echo 'Internal event delivery contract passed (' . $driver . ").\n";
} finally {
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH');
    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS');
    putenv('CPE_DOMAIN_EVENT_FANOUT_MAX_ATTEMPTS');
    putenv('CPE_DOMAIN_EVENT_LOCK_SECONDS');
    if ($secondary instanceof PDO && $secondary->inTransaction()) {
        $secondary->rollBack();
    }
    Database::reset();
    $secondary = null;
    $pdo = null;
    observer_remove_tree($testRoot);
}
