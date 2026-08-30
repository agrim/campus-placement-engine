<?php

declare(strict_types=1);

$publicEventRoot = sys_get_temp_dir() . '/cpe-public-event-' . bin2hex(random_bytes(6));
if (!mkdir($publicEventRoot, 0700, true) && !is_dir($publicEventRoot)) {
    throw new RuntimeException('Could not create public event contract directory.');
}
$publicEventSqlite = null;
$usesPostgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$usesPostgres) {
    $publicEventSqlite = $publicEventRoot . '/contract.sqlite';
    putenv('CPE_DB_DRIVER');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $publicEventSqlite);
}

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/authorized_setup_recovery_fixture.php';

use App\Core\Events\DomainEvent;
use App\Core\Events\DomainEventOutboxWorker;
use App\Core\Events\PublicEventDeadLetterReplayService;
use App\Core\Events\PublicEventEnvelope;
use App\Core\Events\PublicEventProjection;
use App\Core\Http\UserVisibleException;
use App\Core\Persistence\WriteTransaction;
use App\Domain\PlacementService;
use App\Import\CsvImporter;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Modules\Placement\Application\ApplicationStatusWriter;
use App\Modules\Placement\Portability\PlacementPortabilityHandler;
use App\Modules\Placement\Workflow\WorkflowMigrationService;
use App\Modules\Placement\Workflow\WorkflowPublisher;
use App\Support\Database;

function public_event_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function public_event_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true),
        );
    }
}

function public_event_rejects(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $e) {
        return $e;
    }
    throw new RuntimeException($message);
}

/** @param array<string, mixed> $value */
function public_event_exact_keys(array $value, array $expected, string $label): void
{
    $actual = array_keys($value);
    sort($actual);
    sort($expected);
    public_event_same($expected, $actual, $label . ' key set differs.');
}

function public_event_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

function public_event_validate_with_draft_2020_12(): void
{
    $python = trim((string) (getenv('CPE_TEST_SCHEMA_PYTHON') ?: 'python3'));
    $command = [$python, cpe_path('tests/validate_public_event_schemas.py')];
    try {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            cpe_path(),
        );
    } catch (Throwable $failure) {
        throw new RuntimeException(
            'Draft 2020-12 validation tooling is unavailable; schema resolution was not proven.',
            0,
            $failure,
        );
    }
    if (!is_resource($process)) {
        throw new RuntimeException(
            'Draft 2020-12 validation tooling is unavailable; schema resolution was not proven.',
        );
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        $detail = trim((string) $stderr);
        throw new RuntimeException(
            'Draft 2020-12 schema validation failed closed.' . ($detail !== '' ? ' ' . $detail : ''),
        );
    }
    public_event_assert(
        trim((string) $stdout) === 'PASS Draft 2020-12 public event schemas',
        'Draft 2020-12 validator did not return its exact success proof.',
    );
}

final class PublicEventContractStream
{
    public mixed $context = null;
    public static bool $fail = false;
    /** @var null|callable */
    public static $beforeWrite = null;
    /** @var list<string> */
    public static array $writes = [];
    private string $buffer = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (is_callable(self::$beforeWrite)) {
            $callback = self::$beforeWrite;
            self::$beforeWrite = null;
            $callback();
        }
        if (self::$fail) {
            throw new RuntimeException('Synthetic public event sink failure.');
        }
        return true;
    }

    public function stream_write(string $data): int
    {
        $this->buffer .= $data;
        return strlen($data);
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        self::$writes[] = $this->buffer;
    }

    public function url_stat(string $path, int $flags): array|false
    {
        if (rtrim($path, '/') === 'publiceventprobe://outbox') {
            return ['mode' => 0040700, 2 => 0040700];
        }
        return false;
    }

    public static function reset(): void
    {
        self::$fail = false;
        self::$beforeWrite = null;
        self::$writes = [];
    }
}

/** @return array<string, mixed> */
function public_event_row(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT id, public_id, public_event_type, public_schema_version, occurred_at,
                public_instance_id, public_aggregate_type, public_aggregate_id,
                public_aggregate_version, public_payload_json, public_correlation_id,
                attempts, lock_token
         FROM domain_event_outbox WHERE id = ?',
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Expected public outbox row was not found.');
    }
    return $row;
}

$publicEventStreamRegistered = false;
try {
    (new SystemRequirements())->assertReady();
    Database::migrate();
    $contractInstanceId = 'tenant_' . str_repeat('d', 32);
    (new Installer())->installHosted([
        'college_name' => 'Public Event Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'Public Event Administrator',
        'admin_email' => 'public-event@example.test',
        'admin_password' => 'public-event-password-123',
        'seed_demo' => '1',
    ], $contractInstanceId, test_authorized_setup_recovery_authority());
    $pdo = Database::connection();
    $driver = Database::driver();
    $placement = new PlacementService($pdo);

    public_event_same(
        0,
        (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox WHERE public_event_type IS NOT NULL')->fetchColumn(),
        'Install/demo data synthesized public events.',
    );
    public_event_same(
        0,
        (int) $pdo->query('SELECT COUNT(*) FROM applications WHERE aggregate_version <> 1')->fetchColumn(),
        'New/demo applications did not start at aggregate version 1.',
    );

    $migration = $driver === 'pgsql'
        ? '013_public_event_projection.sql'
        : '049_public_event_projection.sql';
    $migrationQuery = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
    $migrationQuery->execute([$migration]);
    public_event_same(1, (int) $migrationQuery->fetchColumn(), 'Public event migration was not registered.');
    if ($driver === 'pgsql') {
        $indexes = $pdo->query(
            "SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'domain_event_outbox'",
        )->fetchAll(PDO::FETCH_COLUMN);
        $triggers = $pdo->query(
            "SELECT trigger_name FROM information_schema.triggers
             WHERE event_object_schema = current_schema()
               AND event_object_table IN ('applications', 'domain_event_outbox')",
        )->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $indexes = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'domain_event_outbox'",
        )->fetchAll(PDO::FETCH_COLUMN);
        $triggers = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'trigger'
             AND tbl_name IN ('applications', 'domain_event_outbox')",
        )->fetchAll(PDO::FETCH_COLUMN);
    }
    public_event_assert(
        in_array('idx_domain_event_outbox_public_aggregate_version', $indexes, true)
            && in_array('idx_domain_event_outbox_public_pending', $indexes, true),
        'Public event uniqueness or pending index is missing.',
    );
    public_event_assert(count($triggers) >= 2, 'Public event/status database guards are missing.');

    $institutionId = (int) $pdo->query(
        "SELECT id FROM institutions WHERE slug = 'default'",
    )->fetchColumn();
    $partialProjection = $pdo->prepare(
        'INSERT INTO domain_event_outbox
         (public_id, event_name, aggregate_type, aggregate_public_id, institution_id,
          module_key, payload_json, occurred_at, available_at, public_event_type)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    public_event_rejects(
        static fn (): bool => $partialProjection->execute([
            'event_' . str_repeat('1', 32),
            'placement.private.partial',
            'placement_application',
            'application_' . str_repeat('2', 32),
            $institutionId,
            'placement',
            '{}',
            '2026-01-01 00:00:00',
            '2026-01-01 00:00:00',
            'application.status_changed',
        ]),
        'Database accepted a partially populated public projection.',
    );

    $guardApplication = (int) $pdo->query('SELECT id FROM applications ORDER BY id LIMIT 1')->fetchColumn();
    $guardStatus = (string) $pdo->query(
        'SELECT current_status FROM applications WHERE id = ' . $guardApplication,
    )->fetchColumn();
    public_event_rejects(
        static fn (): bool => $pdo->prepare(
            'UPDATE applications SET current_status = ? WHERE id = ?',
        )->execute([$guardStatus === 'idle' ? 'scheduled' : 'idle', $guardApplication]),
        'Database allowed a status change without an aggregate version increment.',
    );
    public_event_rejects(
        static fn (): bool => $pdo->prepare(
            'UPDATE applications SET aggregate_version = aggregate_version + 1 WHERE id = ?',
        )->execute([$guardApplication]),
        'Database allowed aggregate version drift without a status change.',
    );

    $contract = json_decode(
        (string) file_get_contents(cpe_path('contracts/public-integration.v1.json')),
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
    public_event_same([
        'schema' => 1,
        'event_schemas' => ['application.status_changed' => [1]],
        'api_scopes' => ['opportunities.read', 'applications.read', 'applications.transition'],
        'engine_api' => ['v1'],
    ], $contract, 'Public integration catalog differs.');
    $jsonDocuments = [];
    foreach ([
        'contracts/schemas/public-event-envelope.v1.schema.json',
        'contracts/schemas/application.status_changed.v1.schema.json',
        'contracts/examples/application.status_changed.v1.json',
        'contracts/fixtures/application.status_changed.v1.consumer.json',
        'contracts/fixtures/application.status_changed.v1.future-optional.consumer.json',
    ] as $jsonPath) {
        $jsonDocuments[$jsonPath] = json_decode(
            (string) file_get_contents(cpe_path($jsonPath)),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }
    $requiredEnvelopeKeys = [
        'event_id', 'event_type', 'schema_version', 'occurred_at',
        'instance_id', 'aggregate', 'data', 'trace',
    ];
    foreach ([
        'contracts/schemas/public-event-envelope.v1.schema.json',
        'contracts/schemas/application.status_changed.v1.schema.json',
    ] as $schemaPath) {
        $schema = $jsonDocuments[$schemaPath];
        public_event_same(false, $schema['additionalProperties'] ?? null, 'Producer envelope schema is not strict.');
        public_event_same($requiredEnvelopeKeys, $schema['required'] ?? null, 'Producer envelope required fields differ.');
        public_event_same(false, $schema['properties']['aggregate']['additionalProperties'] ?? null, 'Producer aggregate schema is not strict.');
        public_event_same(false, $schema['properties']['trace']['additionalProperties'] ?? null, 'Producer trace schema is not strict.');
        public_event_same(
            '^(inst|tenant)_[a-f0-9]{32}$',
            $schema['properties']['instance_id']['pattern'] ?? null,
            'Producer instance-id pattern excludes a canonical installation form.',
        );
    }
    $eventSchema = $jsonDocuments['contracts/schemas/application.status_changed.v1.schema.json'];
    $envelopeSchema = $jsonDocuments['contracts/schemas/public-event-envelope.v1.schema.json'];
    public_event_same(
        (string) $eventSchema['$id'] . '#/$defs/data',
        $envelopeSchema['properties']['data']['$ref'] ?? null,
        'Envelope data schema does not use the event schema absolute identifier.',
    );
    public_event_same(false, $eventSchema['$defs']['data']['additionalProperties'] ?? null, 'Producer event data schema is not strict.');
    $example = $jsonDocuments['contracts/examples/application.status_changed.v1.json'];
    public_event_exact_keys($example, $requiredEnvelopeKeys, 'Producer example');
    public_event_exact_keys($example['aggregate'], ['type', 'id', 'version'], 'Producer example aggregate');
    public_event_exact_keys($example['data'], ['from_status', 'to_status'], 'Producer example data');
    public_event_exact_keys($example['trace'], ['correlation_id'], 'Producer example trace');
    public_event_same('application.status_changed', $example['event_type'], 'Producer example event type differs.');
    public_event_same(1, $example['schema_version'], 'Producer example schema differs.');
    public_event_assert(
        preg_match('/^event_[a-f0-9]{32}$/D', (string) $example['event_id']) === 1
            && preg_match('/^(?:inst|tenant)_[a-f0-9]{32}$/D', (string) $example['instance_id']) === 1
            && preg_match('/^application_[a-f0-9]{32}$/D', (string) $example['aggregate']['id']) === 1
            && preg_match('/^req_[a-f0-9]{24}$/D', (string) $example['trace']['correlation_id']) === 1,
        'Producer example contains a non-canonical public identifier.',
    );
    public_event_validate_with_draft_2020_12();
    $hostedProjection = PublicEventProjection::applicationStatusChanged(
        'tenant_' . str_repeat('a', 32),
        'application_' . str_repeat('b', 32),
        2,
        'idle',
        'scheduled',
        'req_' . str_repeat('c', 24),
    );
    public_event_same(
        'tenant_' . str_repeat('a', 32),
        $hostedProjection->instanceId,
        'Hosted canonical institution id was rejected as a public instance id.',
    );

    $applicationId = (int) $pdo->query(
        "SELECT id FROM applications WHERE current_status = 'idle' ORDER BY id LIMIT 1",
    )->fetchColumn();
    public_event_assert($applicationId > 0, 'Public event contract needs an idle application.');
    $applicationPublicId = (string) $pdo->query(
        'SELECT public_id FROM applications WHERE id = ' . $applicationId,
    )->fetchColumn();
    $placement->moveNext($applicationId, 1, 'admin', 'Public contract transition', 'idle');
    $firstRow = $pdo->query(
        'SELECT id FROM domain_event_outbox WHERE public_event_type IS NOT NULL ORDER BY id LIMIT 1',
    )->fetchColumn();
    public_event_assert($firstRow !== false, 'Status change did not create a public outbox projection.');
    $envelope = PublicEventEnvelope::fromOutboxRow(public_event_row($pdo, (int) $firstRow))->toArray();
    public_event_exact_keys($envelope, [
        'event_id', 'event_type', 'schema_version', 'occurred_at', 'instance_id',
        'aggregate', 'data', 'trace',
    ], 'Public envelope');
    public_event_exact_keys($envelope['aggregate'], ['type', 'id', 'version'], 'Public aggregate');
    public_event_exact_keys($envelope['data'], ['from_status', 'to_status'], 'Public data');
    public_event_exact_keys($envelope['trace'], ['correlation_id'], 'Public trace');
    public_event_same('application.status_changed', $envelope['event_type'], 'Public event catalog name changed.');
    public_event_same(1, $envelope['schema_version'], 'Public schema version changed.');
    public_event_same('application', $envelope['aggregate']['type'], 'Public aggregate type changed.');
    public_event_same($contractInstanceId, $envelope['instance_id'], 'Public instance id did not come from the institution row.');
    public_event_same($applicationPublicId, $envelope['aggregate']['id'], 'Public aggregate id is not canonical.');
    public_event_same(2, $envelope['aggregate']['version'], 'First status transition did not publish version 2.');
    public_event_same(['from_status' => 'idle', 'to_status' => 'scheduled'], $envelope['data'], 'Public status data differs.');
    public_event_assert(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) $envelope['occurred_at']) === 1,
        'Public occurrence time is not RFC3339 UTC.',
    );
    $wire = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    foreach ([
        'candidate_public_id', 'company_public_id', 'candidate_id', 'company_id',
        'actor_role', 'transition_key', 'note', 'application_id',
    ] as $forbidden) {
        public_event_assert(!str_contains($wire, $forbidden), 'Public envelope leaked private field: ' . $forbidden);
    }

    $projectionSnapshot = $pdo->query(
        'SELECT public_id, public_event_type, public_schema_version, public_instance_id,
                public_aggregate_type, public_aggregate_id, public_aggregate_version,
                public_payload_json, public_correlation_id, occurred_at
         FROM domain_event_outbox WHERE id = ' . (int) $firstRow,
    )->fetch();
    public_event_rejects(
        static fn (): bool => $pdo->prepare(
            'UPDATE domain_event_outbox SET public_payload_json = ? WHERE id = ?',
        )->execute(['{"from_status":"idle","to_status":"placed"}', (int) $firstRow]),
        'Public projection content was mutable after insertion.',
    );
    public_event_same(
        $projectionSnapshot,
        $pdo->query(
            'SELECT public_id, public_event_type, public_schema_version, public_instance_id,
                    public_aggregate_type, public_aggregate_id, public_aggregate_version,
                    public_payload_json, public_correlation_id, occurred_at
             FROM domain_event_outbox WHERE id = ' . (int) $firstRow,
        )->fetch(),
        'Rejected projection mutation changed immutable content.',
    );
    public_event_rejects(
        static fn (): bool => $pdo->prepare(
            'UPDATE domain_event_outbox SET public_id = ? WHERE id = ?',
        )->execute(['event_' . str_repeat('3', 32), (int) $firstRow]),
        'Public event id was mutable after insertion.',
    );
    public_event_rejects(
        static fn (): bool => $pdo->prepare(
            'UPDATE domain_event_outbox SET occurred_at = ? WHERE id = ?',
        )->execute(['2026-01-02 00:00:00', (int) $firstRow]),
        'Public event occurrence time was mutable after insertion.',
    );
    $copyProjection = $pdo->prepare(
        'INSERT INTO domain_event_outbox
         (public_id, event_name, aggregate_type, aggregate_public_id, institution_id, module_key,
          payload_json, occurred_at, available_at,
          public_event_type, public_schema_version, public_instance_id, public_aggregate_type,
          public_aggregate_id, public_aggregate_version, public_payload_json, public_correlation_id)
         SELECT ?, event_name, aggregate_type, aggregate_public_id, institution_id, module_key,
                payload_json, occurred_at, available_at,
                ?, public_schema_version, public_instance_id, public_aggregate_type,
                public_aggregate_id, public_aggregate_version, public_payload_json, public_correlation_id
         FROM domain_event_outbox WHERE id = ?',
    );
    public_event_rejects(
        static fn (): bool => $copyProjection->execute([
            'event_' . str_repeat('4', 32),
            'application.created',
            (int) $firstRow,
        ]),
        'Database accepted an event outside the public catalog.',
    );
    public_event_rejects(
        static fn (): bool => $copyProjection->execute([
            'event_' . str_repeat('5', 32),
            'application.status_changed',
            (int) $firstRow,
        ]),
        'Database accepted a duplicate public aggregate version.',
    );

    $publicCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM domain_event_outbox WHERE public_event_type IS NOT NULL',
    )->fetchColumn();
    $version = (int) $pdo->query(
        'SELECT aggregate_version FROM applications WHERE id = ' . $applicationId,
    )->fetchColumn();
    $placement->saveApplication(
        (int) $pdo->query('SELECT candidate_id FROM applications WHERE id = ' . $applicationId)->fetchColumn(),
        (int) $pdo->query('SELECT company_id FROM applications WHERE id = ' . $applicationId)->fetchColumn(),
        'scheduled',
        3,
        1,
    );
    public_event_same($version, (int) $pdo->query('SELECT aggregate_version FROM applications WHERE id = ' . $applicationId)->fetchColumn(), 'Same-status save incremented aggregate version.');
    public_event_same($publicCount, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox WHERE public_event_type IS NOT NULL')->fetchColumn(), 'Same-status save emitted a public event.');
    $placement->returnToIdle($applicationId, 1, 'admin', 'public_contract', '', 'scheduled');
    public_event_same($version + 1, (int) $pdo->query('SELECT aggregate_version FROM applications WHERE id = ' . $applicationId)->fetchColumn(), 'Return-to-idle did not increment exactly once.');
    $placement->saveApplication(
        (int) $pdo->query('SELECT candidate_id FROM applications WHERE id = ' . $applicationId)->fetchColumn(),
        (int) $pdo->query('SELECT company_id FROM applications WHERE id = ' . $applicationId)->fetchColumn(),
        'scheduled',
        null,
        1,
    );
    public_event_same($version + 2, (int) $pdo->query('SELECT aggregate_version FROM applications WHERE id = ' . $applicationId)->fetchColumn(), 'saveApplication status change did not increment exactly once.');

    $csvCandidate = (string) $pdo->query(
        'SELECT c.external_id FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE a.id = ' . $applicationId,
    )->fetchColumn();
    $csvCompany = (string) $pdo->query(
        'SELECT co.code FROM applications a JOIN companies co ON co.id = a.company_id WHERE a.id = ' . $applicationId,
    )->fetchColumn();
    (new CsvImporter($pdo))->shortlists(
        "candidate_external_id,company_code,status\n{$csvCandidate},{$csvCompany},intransit\n",
    );
    public_event_same($version + 3, (int) $pdo->query('SELECT aggregate_version FROM applications WHERE id = ' . $applicationId)->fetchColumn(), 'Shortlist import status change did not increment exactly once.');
    $sameImportPublicCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM domain_event_outbox WHERE public_event_type IS NOT NULL',
    )->fetchColumn();
    (new CsvImporter($pdo))->shortlists(
        "candidate_external_id,company_code,status,waitlist_rank\n{$csvCandidate},{$csvCompany},intransit,4\n",
    );
    public_event_same($version + 3, (int) $pdo->query('SELECT aggregate_version FROM applications WHERE id = ' . $applicationId)->fetchColumn(), 'Same-status shortlist import incremented aggregate version.');
    public_event_same($sameImportPublicCount, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox WHERE public_event_type IS NOT NULL')->fetchColumn(), 'Same-status shortlist import emitted a public event.');

    $beforeRollback = $pdo->query(
        'SELECT current_status, aggregate_version FROM applications WHERE id = ' . $applicationId,
    )->fetch();
    $beforeRollbackOutbox = (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn();
    public_event_rejects(
        static function () use ($pdo, $applicationId, $beforeRollback): void {
            WriteTransaction::run($pdo, static function () use ($pdo, $applicationId, $beforeRollback): void {
                (new ApplicationStatusWriter($pdo))->changeStatus(
                    $applicationId,
                    (string) $beforeRollback['current_status'],
                    (int) $beforeRollback['aggregate_version'],
                    'arrived',
                    1,
                    'admin',
                    'Rollback contract.',
                    cpe_now(),
                );
                throw new RuntimeException('force rollback');
            });
        },
        'Forced status transaction did not roll back.',
    );
    public_event_same($beforeRollback, $pdo->query('SELECT current_status, aggregate_version FROM applications WHERE id = ' . $applicationId)->fetch(), 'Rollback changed application status/version.');
    public_event_same($beforeRollbackOutbox, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn(), 'Rollback retained an outbox row.');
    public_event_rejects(
        static fn (): array => (new ApplicationStatusWriter($pdo))->changeStatus(
            $applicationId,
            (string) $beforeRollback['current_status'],
            (int) $beforeRollback['aggregate_version'] - 1,
            'arrived',
            1,
            'admin',
            'Stale CAS.',
            cpe_now(),
        ),
        'Stale aggregate version was accepted.',
    );

    $publisher = new WorkflowPublisher($pdo);
    $definition = $publisher->fromTemplate('default', cpe_config('workflows.default'));
    $definition['states']['scheduled']['label'] = 'Public contract migration';
    $targetVersion = $publisher->publish('default', $definition, 'test', 1, true);
    $migrationRows = $pdo->query(
        'SELECT id, current_status, aggregate_version FROM applications WHERE workflow_version_id IS NULL OR workflow_version_id != ' . $targetVersion,
    )->fetchAll();
    $changedBefore = [];
    foreach ($migrationRows as $row) {
        $changedBefore[(int) $row['id']] = [(string) $row['current_status'], (int) $row['aggregate_version']];
    }
    (new WorkflowMigrationService($pdo))->migrate($targetVersion, ['scheduled' => 'idle'], 1, 'admin', 'Public event contract');
    foreach ($changedBefore as $id => [$oldStatus, $oldVersion]) {
        $after = $pdo->query('SELECT current_status, aggregate_version FROM applications WHERE id = ' . $id)->fetch();
        $expectedStatus = $oldStatus === 'scheduled' ? 'idle' : $oldStatus;
        public_event_same($expectedStatus, (string) $after['current_status'], 'Workflow migration status differs.');
        public_event_same($oldVersion + ($oldStatus === 'scheduled' ? 1 : 0), (int) $after['aggregate_version'], 'Workflow version-only change altered aggregate sequence.');
    }

    $candidateFour = (int) $pdo->query("SELECT id FROM candidates WHERE external_id = 'C004'")->fetchColumn();
    $atlas = (int) $pdo->query("SELECT id FROM companies WHERE code = 'ATLAS'")->fetchColumn();
    $nova = (int) $pdo->query("SELECT id FROM companies WHERE code = 'NOVA'")->fetchColumn();
    $beforeNewApplicationEvents = (int) $pdo->query(
        'SELECT COUNT(*) FROM domain_event_outbox WHERE public_event_type IS NOT NULL',
    )->fetchColumn();
    $placement->saveApplication($candidateFour, $atlas, 'sendaway', null, 1);
    $newApplicationVersion = $pdo->prepare(
        'SELECT aggregate_version FROM applications WHERE candidate_id = ? AND company_id = ?',
    );
    $newApplicationVersion->execute([$candidateFour, $atlas]);
    public_event_same(1, (int) $newApplicationVersion->fetchColumn(), 'New application did not start at aggregate version 1.');
    public_event_same($beforeNewApplicationEvents, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox WHERE public_event_type IS NOT NULL')->fetchColumn(), 'New application synthesized a public event.');
    $placement->saveApplication($candidateFour, $nova, 'scheduled', null, 1);
    $handoffSource = $pdo->prepare('SELECT id FROM applications WHERE candidate_id = ? AND company_id = ?');
    $handoffSource->execute([$candidateFour, $atlas]);
    $handoffSourceId = (int) $handoffSource->fetchColumn();
    $handoffTarget = $pdo->prepare('SELECT id FROM applications WHERE candidate_id = ? AND company_id = ?');
    $handoffTarget->execute([$candidateFour, $nova]);
    $handoffTargetId = (int) $handoffTarget->fetchColumn();
    $placement->moveNext($handoffSourceId, 1, 'admin', 'Handoff contract', 'sendaway');
    $handoffAfter = $pdo->query('SELECT current_status, aggregate_version FROM applications WHERE id = ' . $handoffTargetId)->fetch();
    public_event_same('intransit', (string) $handoffAfter['current_status'], 'Automatic handoff did not change status.');
    public_event_same(2, (int) $handoffAfter['aggregate_version'], 'Automatic handoff did not emit its first versioned change.');

    $candidateFive = (int) $pdo->query("SELECT id FROM candidates WHERE external_id = 'C005'")->fetchColumn();
    $placement->saveApplication($candidateFive, $atlas, 'scheduled', null, 1);
    $placement->saveApplication($candidateFive, $nova, 'intransit', null, 1);
    $cleanupVersionQuery = $pdo->prepare(
        'SELECT aggregate_version FROM applications WHERE candidate_id = ? AND company_id = ?',
    );
    $cleanupVersionQuery->execute([$candidateFive, $nova]);
    $cleanupVersionBefore = (int) $cleanupVersionQuery->fetchColumn();
    $cleanupLead = $pdo->prepare('SELECT id FROM applications WHERE candidate_id = ? AND company_id = ?');
    $cleanupLead->execute([$candidateFive, $atlas]);
    $cleanupLeadId = (int) $cleanupLead->fetchColumn();
    while ((string) $pdo->query('SELECT current_status FROM applications WHERE id = ' . $cleanupLeadId)->fetchColumn() !== 'placed') {
        $placement->moveNext($cleanupLeadId, 1, 'admin');
    }
    $cleanupOther = $pdo->prepare('SELECT current_status, aggregate_version FROM applications WHERE candidate_id = ? AND company_id = ?');
    $cleanupOther->execute([$candidateFive, $nova]);
    $cleanupAfter = $cleanupOther->fetch();
    public_event_same('idle', (string) $cleanupAfter['current_status'], 'Competing application was not auto-cleared.');
    public_event_same($cleanupVersionBefore + 1, (int) $cleanupAfter['aggregate_version'], 'Auto-cleanup did not publish exactly one change.');

    $privateId = 'event_' . bin2hex(random_bytes(16));
    $institutionId = (int) $pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $private = $pdo->prepare(
        'INSERT INTO domain_event_outbox
         (public_id, event_name, aggregate_type, aggregate_public_id, institution_id, module_key,
          payload_json, occurred_at, available_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $sentinel = 'PRIVATE_SENTINEL_' . bin2hex(random_bytes(8));
    $private->execute([
        $privateId,
        'placement.private.sentinel',
        'private',
        'internal_123',
        $institutionId,
        'placement',
        '{"unterminated":' . $sentinel,
        cpe_now(),
        cpe_now(),
    ]);

    $pdo->exec(
        "UPDATE domain_event_outbox
         SET processed_at = COALESCE(processed_at, '2026-01-01 00:00:00'), locked_at = NULL, lock_token = NULL
         WHERE public_event_type IS NOT NULL",
    );
    $orderingCandidate = (int) $pdo->query("SELECT id FROM candidates WHERE external_id = 'C003'")->fetchColumn();
    $river = (int) $pdo->query("SELECT id FROM companies WHERE code = 'RIVER'")->fetchColumn();
    $placement->saveApplication($orderingCandidate, $atlas, 'idle', null, 1);
    $orderingA = $pdo->prepare('SELECT id FROM applications WHERE candidate_id = ? AND company_id = ?');
    $orderingA->execute([$orderingCandidate, $atlas]);
    $orderingAId = (int) $orderingA->fetchColumn();
    $placement->moveNext($orderingAId, 1, 'admin');
    $placement->moveNext($orderingAId, 1, 'admin');
    $placement->saveApplication($orderingCandidate, $river, 'idle', null, 1);
    $orderingB = $pdo->prepare('SELECT id FROM applications WHERE candidate_id = ? AND company_id = ?');
    $orderingB->execute([$orderingCandidate, $river]);
    $orderingBId = (int) $orderingB->fetchColumn();
    $placement->moveNext($orderingBId, 1, 'admin');
    $orderingOutbox = $publicEventRoot . '/ordering.jsonl';
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH=' . $orderingOutbox);
    $firstBatch = (new DomainEventOutboxWorker($pdo))->work(100);
    public_event_same(2, $firstBatch['claimed'], 'Worker did not allow other aggregates while fencing a later version.');
    $firstVersions = [];
    foreach (file($orderingOutbox, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        $firstVersions[(string) $decoded['aggregate']['id']][] = (int) $decoded['aggregate']['version'];
        public_event_assert(!str_contains($line, $sentinel), 'Private sentinel leaked through public delivery.');
    }
    public_event_same([2], $firstVersions[(string) $pdo->query('SELECT public_id FROM applications WHERE id = ' . $orderingAId)->fetchColumn()] ?? [], 'Worker claimed a later version before its predecessor resolved.');
    $secondBatch = (new DomainEventOutboxWorker($pdo))->work(100);
    public_event_same(1, $secondBatch['claimed'], 'Worker did not release the next aggregate version after acknowledgement.');
    public_event_same(null, $pdo->query('SELECT processed_at FROM domain_event_outbox WHERE public_id = ' . $pdo->quote($privateId))->fetchColumn() ?: null, 'Private outbox row was externally claimed.');

    public_event_assert(
        stream_wrapper_register('publiceventprobe', PublicEventContractStream::class),
        'Could not register the public event worker probe.',
    );
    $publicEventStreamRegistered = true;
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH=publiceventprobe://outbox/domain');
    putenv('CPE_LOG_PATH=' . $publicEventRoot . '/worker-incidents.jsonl');
    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS=2');

    $placement->moveNext($orderingBId, 1, 'admin');
    $retryRow = $pdo->query(
        'SELECT id, public_id FROM domain_event_outbox
         WHERE public_aggregate_id = ' . $pdo->quote(
            (string) $pdo->query('SELECT public_id FROM applications WHERE id = ' . $orderingBId)->fetchColumn(),
        ) . ' AND processed_at IS NULL ORDER BY id DESC LIMIT 1',
    )->fetch();
    public_event_assert(is_array($retryRow), 'Retry worker fixture was not published.');
    PublicEventContractStream::reset();
    PublicEventContractStream::$fail = true;
    $retryFailure = (new DomainEventOutboxWorker($pdo))->work(1);
    public_event_same(1, $retryFailure['retrying'], 'Public event failure was not scheduled for retry.');
    public_event_same(
        (string) $retryRow['public_id'],
        (string) $pdo->query('SELECT public_id FROM domain_event_outbox WHERE id = ' . (int) $retryRow['id'])->fetchColumn(),
        'Public event id changed after a failed attempt.',
    );
    $pdo->prepare('UPDATE domain_event_outbox SET available_at = ? WHERE id = ?')->execute([
        cpe_now(),
        (int) $retryRow['id'],
    ]);
    PublicEventContractStream::reset();
    $retrySuccess = (new DomainEventOutboxWorker($pdo))->work(1);
    public_event_same(1, $retrySuccess['delivered'], 'Retryable public event did not deliver on its next attempt.');

    $placement->moveNext($orderingBId, 1, 'admin');
    $claimLossRow = $pdo->query(
        'SELECT id FROM domain_event_outbox WHERE processed_at IS NULL AND public_event_type IS NOT NULL ORDER BY id LIMIT 1',
    )->fetch();
    public_event_assert(is_array($claimLossRow), 'Claim-loss worker fixture was not published.');
    PublicEventContractStream::reset();
    PublicEventContractStream::$beforeWrite = static function () use ($pdo, $claimLossRow): void {
        $pdo->exec(
            "UPDATE domain_event_outbox SET lock_token = 'claim_stolen' WHERE id = " . (int) $claimLossRow['id'],
        );
    };
    $claimLoss = (new DomainEventOutboxWorker($pdo))->work(1);
    public_event_same(1, $claimLoss['claim_lost'], 'Lost public event acknowledgement claim was not reported.');
    public_event_same(1, count(PublicEventContractStream::$writes), 'Claim-loss probe did not observe the first delivery side effect.');
    $firstClaimLossWire = PublicEventContractStream::$writes[0];
    $pdo->prepare(
        'UPDATE domain_event_outbox SET locked_at = ?, available_at = ? WHERE id = ?',
    )->execute(['2000-01-01 00:00:00', cpe_now(), (int) $claimLossRow['id']]);
    PublicEventContractStream::$beforeWrite = null;
    $staleRetry = (new DomainEventOutboxWorker($pdo))->work(1);
    public_event_same(1, $staleRetry['delivered'], 'Stale public event claim was not reclaimed.');
    public_event_same(2, count(PublicEventContractStream::$writes), 'Stale retry did not repeat the delivery side effect.');
    public_event_same($firstClaimLossWire, PublicEventContractStream::$writes[1], 'Public envelope changed across an at-least-once retry.');

    $placement->moveNext($orderingBId, 1, 'admin');
    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS=1');
    PublicEventContractStream::reset();
    PublicEventContractStream::$fail = true;
    $deadLetter = (new DomainEventOutboxWorker($pdo))->work(1);
    public_event_same(1, $deadLetter['dead_lettered'], 'Terminal public event attempt was not dead-lettered.');
    $deadLetterRow = $pdo->query(
        'SELECT id, public_id, public_event_type, public_schema_version, occurred_at,
                public_instance_id, public_aggregate_type, public_aggregate_id,
                public_aggregate_version, public_payload_json, public_correlation_id,
                attempts, available_at, processed_at, failed_at, locked_at, lock_token
         FROM domain_event_outbox
         WHERE public_event_type IS NOT NULL AND failed_at IS NOT NULL
         ORDER BY id DESC LIMIT 1',
    )->fetch(PDO::FETCH_ASSOC);
    public_event_assert(is_array($deadLetterRow), 'Public event dead-letter state was not persisted.');
    $deadLetterEnvelope = PublicEventEnvelope::fromOutboxRow($deadLetterRow)->toJson();
    $deadLetterId = (int) $deadLetterRow['id'];
    $deadLetterPublicId = (string) $deadLetterRow['public_id'];
    $deadLetterVersion = (int) $deadLetterRow['public_aggregate_version'];

    $placement->moveNext($orderingBId, 1, 'admin');
    $laterRow = $pdo->query(
        'SELECT id, public_id, public_aggregate_version
         FROM domain_event_outbox
         WHERE public_aggregate_id = ' . $pdo->quote((string) $deadLetterRow['public_aggregate_id']) . '
           AND processed_at IS NULL AND failed_at IS NULL
         ORDER BY public_aggregate_version DESC LIMIT 1',
    )->fetch(PDO::FETCH_ASSOC);
    public_event_assert(is_array($laterRow), 'A later same-aggregate public version was not published.');
    public_event_same(
        $deadLetterVersion + 1,
        (int) $laterRow['public_aggregate_version'],
        'Later same-aggregate public version was not consecutive.',
    );
    PublicEventContractStream::reset();
    $blockedByDeadLetter = (new DomainEventOutboxWorker($pdo))->work(100);
    public_event_same(0, $blockedByDeadLetter['claimed'], 'Worker bypassed an unresolved earlier public dead letter.');

    $replayService = new PublicEventDeadLetterReplayService($pdo);
    $replayAdminId = (int) $pdo->query(
        "SELECT id FROM users WHERE active = 1 AND role = 'admin' ORDER BY id LIMIT 1",
    )->fetchColumn();
    $restrictedActorId = (int) $pdo->query(
        "SELECT id FROM users WHERE active = 1 AND role <> 'admin' ORDER BY id LIMIT 1",
    )->fetchColumn();
    public_event_assert($replayAdminId > 0, 'Public replay fixture has no active administrator.');
    public_event_assert($restrictedActorId > 0, 'Public replay fixture has no real active restricted user.');
    $inactiveAdminEmail = 'inactive-replay-' . bin2hex(random_bytes(4)) . '@example.test';
    $inactiveAdmin = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, role, active, created_at)
         VALUES (?, ?, ?, 'admin', 0, ?)",
    );
    $inactiveAdmin->execute([
        'Inactive Replay Administrator',
        $inactiveAdminEmail,
        password_hash('inactive-replay-password', PASSWORD_DEFAULT),
        cpe_now(),
    ]);
    $inactiveAdminQuery = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $inactiveAdminQuery->execute([$inactiveAdminEmail]);
    $inactiveAdminId = (int) $inactiveAdminQuery->fetchColumn();
    public_event_assert($inactiveAdminId > 0, 'Public replay fixture inactive administrator is missing.');
    foreach ([
        'active restricted' => $restrictedActorId,
        'inactive administrator' => $inactiveAdminId,
        'missing' => 2147483647,
    ] as $actorLabel => $unauthorizedActorId) {
        $actorFailure = public_event_rejects(
            static fn (): array => $replayService->replay($deadLetterPublicId, $unauthorizedActorId),
            ucfirst($actorLabel) . ' actor was accepted for public replay.',
        );
        public_event_assert(
            $actorFailure instanceof UserVisibleException
                && $actorFailure->publicCode() === 'PUBLIC_EVENT_REPLAY_ACTOR_INVALID'
                && $actorFailure->publicMessage() === 'An active administrator user ID is required.',
            ucfirst($actorLabel) . ' public replay did not use the stable redacted actor failure.',
        );
    }
    public_event_same(
        1,
        (int) $pdo->query(
            'SELECT COUNT(*) FROM domain_event_outbox WHERE id = ' . $deadLetterId . ' AND failed_at IS NOT NULL',
        )->fetchColumn(),
        'Unauthorized public replay changed dead-letter state.',
    );
    public_event_same(
        0,
        (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'public_event.dead_letter_replay'",
        )->fetchColumn(),
        'Unauthorized public replay wrote an audit attribution.',
    );
    $privateReplay = public_event_rejects(
        static fn (): array => $replayService->replay($privateId, $replayAdminId),
        'Private outbox row was accepted for public replay.',
    );
    public_event_assert(
        $privateReplay instanceof UserVisibleException
            && $privateReplay->publicCode() === 'PUBLIC_EVENT_REPLAY_NOT_FOUND',
        'Private outbox replay did not fail closed with the stable not-found code.',
    );
    $pendingReplay = public_event_rejects(
        static fn (): array => $replayService->replay((string) $laterRow['public_id'], $replayAdminId),
        'Pending public event was accepted for dead-letter replay.',
    );
    public_event_assert(
        $pendingReplay instanceof UserVisibleException
            && $pendingReplay->publicCode() === 'PUBLIC_EVENT_REPLAY_NOT_ELIGIBLE',
        'Pending public event replay did not fail closed with the stable eligibility code.',
    );

    $pdo->prepare(
        'UPDATE domain_event_outbox SET locked_at = ?, lock_token = ? WHERE id = ?',
    )->execute([cpe_now(), 'claim_' . str_repeat('a', 32), $deadLetterId]);
    $leasedReplay = public_event_rejects(
        static fn (): array => $replayService->replay($deadLetterPublicId, $replayAdminId),
        'Leased public dead letter was accepted for replay.',
    );
    public_event_assert(
        $leasedReplay instanceof UserVisibleException
            && $leasedReplay->publicCode() === 'PUBLIC_EVENT_REPLAY_LEASE_CONFLICT',
        'Public dead-letter replay did not preserve lease fencing.',
    );
    $pdo->prepare(
        'UPDATE domain_event_outbox SET locked_at = NULL, lock_token = NULL WHERE id = ?',
    )->execute([$deadLetterId]);

    $replayed = $replayService->replay($deadLetterPublicId, $replayAdminId);
    public_event_same('replayed', $replayed['status'], 'Public dead letter was not requeued.');
    public_event_same(
        $deadLetterEnvelope,
        PublicEventEnvelope::fromOutboxRow(public_event_row($pdo, $deadLetterId))->toJson(),
        'Public event ID or envelope content changed during replay.',
    );
    public_event_same(
        'already-replayed',
        $replayService->replay($deadLetterPublicId, $replayAdminId)['status'],
        'Repeated unchanged public replay was not idempotent.',
    );
    $replayAudits = $pdo->query(
        "SELECT actor_user_id, action, subject_type, subject_id, detail
         FROM audit_logs
         WHERE action = 'public_event.dead_letter_replay'",
    )->fetchAll(PDO::FETCH_ASSOC);
    public_event_same(1, count($replayAudits), 'Idempotent public replay wrote duplicate audit rows.');
    public_event_same($replayAdminId, (int) $replayAudits[0]['actor_user_id'], 'Public replay audit omitted the administrator actor.');
    public_event_same('public_event', (string) $replayAudits[0]['subject_type'], 'Public replay audit subject differs.');
    public_event_same($deadLetterId, (int) $replayAudits[0]['subject_id'], 'Public replay audit does not identify the exact event.');
    public_event_same(
        'Dead-lettered public event requeued for delivery.',
        (string) $replayAudits[0]['detail'],
        'Public replay audit detail is not the fixed payload-free message.',
    );

    PublicEventContractStream::reset();
    $replayedDelivery = (new DomainEventOutboxWorker($pdo))->work(100);
    public_event_same(1, $replayedDelivery['delivered'], 'Replayed public dead letter did not deliver first.');
    public_event_same(1, count(PublicEventContractStream::$writes), 'Replayed public event emitted an unexpected delivery count.');
    public_event_same(
        $deadLetterEnvelope . "\n",
        PublicEventContractStream::$writes[0],
        'Replayed public delivery did not preserve the immutable wire content.',
    );
    $resumedDelivery = (new DomainEventOutboxWorker($pdo))->work(100);
    public_event_same(1, $resumedDelivery['delivered'], 'Later same-aggregate version did not resume after replay success.');
    public_event_same(2, count(PublicEventContractStream::$writes), 'Ordered public delivery resume emitted an unexpected count.');
    $resumedEnvelope = json_decode(trim(PublicEventContractStream::$writes[1]), true, 32, JSON_THROW_ON_ERROR);
    public_event_same(
        $deadLetterVersion + 1,
        (int) $resumedEnvelope['aggregate']['version'],
        'Later public aggregate version resumed out of order.',
    );
    PublicEventContractStream::reset();

    public_event_rejects(
        static fn (): PublicEventProjection => PublicEventProjection::fromStored(
            'application.status_changed',
            2,
            'inst_' . str_repeat('a', 32),
            'application',
            'application_' . str_repeat('b', 32),
            2,
            '{"from_status":"idle","to_status":"scheduled"}',
            'req_' . str_repeat('c', 24),
        ),
        'Unknown public schema was accepted.',
    );

    $frozenConsumer = json_decode(
        (string) file_get_contents(cpe_path('contracts/fixtures/application.status_changed.v1.consumer.json')),
        true,
        32,
        JSON_THROW_ON_ERROR,
    );
    $futureConsumer = json_decode(
        (string) file_get_contents(cpe_path('contracts/fixtures/application.status_changed.v1.future-optional.consumer.json')),
        true,
        32,
        JSON_THROW_ON_ERROR,
    );
    $consumeV1 = static fn (array $event): array => [
        'event_id' => (string) $event['event_id'],
        'event_type' => (string) $event['event_type'],
        'schema_version' => (int) $event['schema_version'],
        'aggregate_id' => (string) $event['aggregate']['id'],
        'aggregate_version' => (int) $event['aggregate']['version'],
        'from_status' => (string) $event['data']['from_status'],
        'to_status' => (string) $event['data']['to_status'],
    ];
    public_event_same($consumeV1($frozenConsumer), $consumeV1($futureConsumer), 'Frozen v1 consumer was not tolerant of future optional fields.');
    public_event_assert(count($futureConsumer) > count($frozenConsumer), 'Future optional consumer fixture contains no extension.');
    public_event_assert(
        array_keys($futureConsumer) !== $requiredEnvelopeKeys
            && array_keys($futureConsumer['aggregate']) !== ['type', 'id', 'version']
            && array_keys($futureConsumer['data']) !== ['from_status', 'to_status']
            && array_keys($futureConsumer['trace']) !== ['correlation_id'],
        'Future optional fixture would not be rejected by the strict producer schema.',
    );

    $portableHandler = new PlacementPortabilityHandler($pdo);
    $portable = $portableHandler->export();
    foreach ($portable['applications'] as $row) {
        public_event_assert(is_int($row['aggregate_version']) && $row['aggregate_version'] > 0, 'Portability export omitted a positive aggregate version.');
    }
    $legacyPortable = $portable;
    foreach ($legacyPortable['applications'] as &$row) {
        unset($row['aggregate_version']);
    }
    unset($row);
    $portableHandler->validate($legacyPortable);
    $invalidPortable = $portable;
    $invalidPortable['applications'][0]['aggregate_version'] = 0;
    public_event_rejects(static fn (): array => $portableHandler->validate($invalidPortable), 'Portability accepted a non-positive aggregate version.');

    $pdo->exec('DELETE FROM domain_event_outbox');
    $pdo->exec('DELETE FROM candidates');
    $pdo->exec('DELETE FROM companies');
    $portableHandler->import($legacyPortable);
    public_event_same(0, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn(), 'Portability restore synthesized public or private events.');
    public_event_same(0, (int) $pdo->query('SELECT COUNT(*) FROM applications WHERE aggregate_version <> 1')->fetchColumn(), 'Older portability bundle did not default aggregate versions to 1.');

    echo 'PASS public event contract (' . Database::driver() . ' ' . Database::serverVersion() . ")\n";
} finally {
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH');
    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS');
    putenv('CPE_LOG_PATH');
    if ($publicEventStreamRegistered) {
        stream_wrapper_unregister('publiceventprobe');
    }
    Database::reset();
    public_event_remove_tree($publicEventRoot);
}
