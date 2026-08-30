<?php

declare(strict_types=1);

$workerTempRoot = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
$workerRoot = $workerTempRoot . '/cpe-worker-delivery-' . bin2hex(random_bytes(6));
mkdir($workerRoot, 0775, true);
chmod($workerRoot, 0700);
$workerDatabase = null;
$usesPostgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$usesPostgres) {
    $workerDatabase = $workerRoot . '/worker.sqlite';
    putenv('CPE_DB_DRIVER');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $workerDatabase);
}
$workerLog = $workerRoot . '/structured.log';
putenv('CPE_LOG_PATH=' . $workerLog);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/authorized_setup_recovery_fixture.php';

use App\Core\Events\DomainEventOutboxWorker;
use App\Domain\NotificationDeliveryService;
use App\Domain\SnapshotExporter;
use App\Install\Installer;
use App\Support\Database;
function worker_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param mixed $expected @param mixed $actual */
function worker_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')',
        );
    }
}

/** @param array<string, string> $sentinels */
function worker_assert_absent(string $contents, array $sentinels, string $surface): void
{
    foreach ($sentinels as $label => $sentinel) {
        worker_assert(!str_contains($contents, $sentinel), $surface . ' exposed ' . $label . '.');
    }
}

function worker_set(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value',
    );
    $stmt->execute([$key, $value]);
}

function worker_queue(PDO $pdo, string $channels): array
{
    static $sourceId = 900000;
    $sourceId++;
    worker_set($pdo, 'notification_delivery_channels', $channels);
    $stmt = $pdo->prepare(
        'INSERT INTO notifications
         (recipient_role, channel, template_key, subject, body, status, source_type, source_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $stmt->execute([
        'admin',
        'in_app',
        'worker_contract',
        'Worker delivery contract',
        'Synthetic delivery payload.',
        'open',
        'worker_contract',
        $sourceId,
        cpe_now(),
    ]);
    $notificationId = Database::lastInsertId($pdo);
    (new NotificationDeliveryService($pdo))->queueForNotification($notificationId);
    $query = $pdo->prepare(
        'SELECT * FROM notification_deliveries WHERE notification_id = ? ORDER BY channel',
    );
    $query->execute([$notificationId]);
    $rows = [];
    foreach ($query->fetchAll() as $row) {
        $rows[(string) $row['channel']] = $row;
    }
    return $rows;
}

function worker_event(PDO $pdo, string $name): array
{
    static $sequence = 0;
    $sequence++;
    $publicId = 'event_' . bin2hex(random_bytes(16));
    $institution = $pdo->query(
        "SELECT id, public_id FROM institutions WHERE slug = 'default'",
    )->fetch();
    worker_assert(is_array($institution), 'Worker event requires an institution identity.');
    $aggregateId = 'application_' . bin2hex(random_bytes(16));
    $stmt = $pdo->prepare(
        'INSERT INTO domain_event_outbox
         (public_id, event_name, aggregate_type, aggregate_public_id, institution_id, module_key,
          payload_json, occurred_at, available_at,
          public_event_type, public_schema_version, public_instance_id, public_aggregate_type,
          public_aggregate_id, public_aggregate_version, public_payload_json, public_correlation_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $stmt->execute([
        $publicId,
        $name,
        'worker_contract',
        $aggregateId,
        (int) $institution['id'],
        'placement',
        '{"contract":true}',
        cpe_now(),
        cpe_now(),
        'application.status_changed',
        1,
        (string) $institution['public_id'],
        'application',
        $aggregateId,
        2,
        '{"from_status":"idle","to_status":"scheduled"}',
        'req_' . bin2hex(random_bytes(12)),
    ]);
    return ['id' => Database::lastInsertId($pdo), 'public_id' => $publicId];
}

/** @return list<array<string, mixed>> */
function worker_run_pair(string $kind, string $barrier): array
{
    $workers = [];
    for ($index = 0; $index < 2; $index++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/worker_delivery_process.php', $kind, $barrier],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            cpe_path(),
            getenv(),
        );
        worker_assert(is_resource($process), 'Could not start independent delivery worker.');
        fclose($pipes[0]);
        $workers[] = ['process' => $process, 'pipes' => $pipes];
    }
    file_put_contents($barrier, 'go');
    $results = [];
    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]) ?: '';
        $stderr = stream_get_contents($worker['pipes'][2]) ?: '';
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $code = proc_close($worker['process']);
        worker_same(0, $code, 'Independent worker failed: ' . $stderr);
        $decoded = json_decode(trim($stdout), true);
        worker_assert(is_array($decoded), 'Independent worker returned invalid JSON.');
        $results[] = $decoded;
    }
    return $results;
}

/** @return array{0: int, 1: string, 2: string} */
function worker_cli(array $arguments): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, cpe_path('placement'), ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        cpe_path(),
        getenv(),
    );
    worker_assert(is_resource($process), 'Could not start worker CLI contract.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function worker_remove_tree(string $directory): void
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

final class WorkerDeliveryStream
{
    public mixed $context = null;
    public static bool $fail = false;
    public static string $failure = '';
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
            throw new RuntimeException(self::$failure);
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
        if (rtrim($path, '/') === 'workerprobe://outbox') {
            return ['mode' => 0040700, 2 => 0040700];
        }
        return false;
    }
}

function worker_reset_probe(): void
{
    WorkerDeliveryStream::$fail = false;
    WorkerDeliveryStream::$failure = '';
    WorkerDeliveryStream::$beforeWrite = null;
    WorkerDeliveryStream::$writes = [];
}

$streamRegistered = false;
try {
    // Exercise the forward migration against the exact pre-migration shape.
    if ($usesPostgres) {
        $legacyPdo = Database::connection();
        $legacyPdo->beginTransaction();
    } else {
        $legacyPdo = new PDO('sqlite::memory:');
        $legacyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    $legacyPdo->exec(
        'CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);'
        . 'CREATE TABLE notification_deliveries ('
        . 'id INTEGER PRIMARY KEY, notification_id INTEGER NOT NULL, channel TEXT NOT NULL, target TEXT NOT NULL DEFAULT \'\','
        . 'status TEXT NOT NULL DEFAULT \'queued\', attempt_count INTEGER NOT NULL DEFAULT 0,'
        . 'last_error TEXT NOT NULL DEFAULT \'\', payload_json TEXT NOT NULL DEFAULT \'\','
        . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, delivered_at TEXT NULL);',
    );
    $legacyTarget = 'https://legacy-target.example.test/hook?token=legacy-secret';
    $legacyInsert = $legacyPdo->prepare(
        'INSERT INTO notification_deliveries
         (id, notification_id, channel, target, status, attempt_count, last_error, payload_json, created_at, updated_at, delivered_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $legacyInsert->execute([1, 7, 'webhook', $legacyTarget, 'delivered', 4, '', '{}', '2026-01-01 00:00:00', '2026-01-02 00:00:00', '2026-01-02 00:00:00']);
    $migrationFile = cpe_path($usesPostgres
        ? 'database/migrations/pgsql/009_notification_delivery_claims.sql'
        : 'database/migrations/045_notification_delivery_claims.sql');
    $legacyPdo->exec((string) file_get_contents($migrationFile));
    $legacy = $legacyPdo->query('SELECT * FROM notification_deliveries WHERE id = 1')->fetch();
    worker_same('delivered', $legacy['status'], 'Migration changed legacy delivery status.');
    worker_same(4, (int) $legacy['attempt_count'], 'Migration changed legacy attempt evidence.');
    worker_same('2026-01-01 00:00:00', $legacy['created_at'], 'Migration changed legacy creation time.');
    worker_same('[config:notification_webhook]', $legacy['target'], 'Migration did not redact the legacy target.');
    worker_same('webhook', $legacy['delivered_to'], 'Migration did not retain fixed destination evidence.');
    worker_assert(preg_match('/\Andk_[a-f0-9]{32}\z/D', (string) $legacy['idempotency_key']) === 1, 'Migration did not backfill an opaque idempotency key.');
    if ($usesPostgres) {
        $legacyPdo->rollBack();
        Database::reset();
    }

    Database::migrate();
    (new Installer())->install([
        'college_name' => 'Worker Delivery Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'Worker Contract Administrator',
        'admin_email' => 'worker-contract@example.test',
        'admin_password' => 'worker-contract-password-123',
        'seed_demo' => '0',
    ], test_authorized_setup_recovery_authority());
    $pdo = Database::connection();
    $sentinels = [
        'email target' => 'sentinel.delivery@example.test',
        'phone target' => '+971500001337',
        'webhook target' => 'https://sentinel-gateway.example.test/hook?token=top-secret',
        'file target' => '/private/sentinel/notification-outbox.jsonl',
    ];
    $rawFailure = implode(' ', $sentinels) . ' SQLSTATE[23505] DETAIL: password=worker-secret';

    worker_assert(stream_wrapper_register('workerprobe', WorkerDeliveryStream::class), 'Could not register worker probe stream.');
    $streamRegistered = true;

    // Two truly independent notification workers must produce one side effect and one acknowledgement.
    $pdo->exec('DELETE FROM notification_deliveries');
    worker_set($pdo, 'notification_file_outbox_path', $sentinels['file target']);
    $notificationOutbox = $workerRoot . '/notification-concurrency.jsonl';
    putenv('CPE_NOTIFICATION_FILE_OUTBOX_PATH=' . $notificationOutbox);
    $queued = worker_queue($pdo, 'file');
    $notificationRow = $queued['file'];
    worker_same('[config:notification_file]', $notificationRow['target'], 'Queued notification persisted a file target.');
    $beforeDryRun = $pdo->query('SELECT status, attempt_count, locked_at, lock_token FROM notification_deliveries WHERE id = ' . (int) $notificationRow['id'])->fetch();
    $dryRun = (new NotificationDeliveryService($pdo))->deliverPending('file', 1, true);
    $afterDryRun = $pdo->query('SELECT status, attempt_count, locked_at, lock_token FROM notification_deliveries WHERE id = ' . (int) $notificationRow['id'])->fetch();
    worker_same($beforeDryRun, $afterDryRun, 'Dry run claimed or mutated a notification row.');
    worker_same('[config:notification_file]', $dryRun['rows'][0]['target'], 'Dry run exposed a destination.');
    $notificationBarrier = $workerRoot . '/notification.go';
    $notificationWorkers = worker_run_pair('notification', $notificationBarrier);
    worker_same(1, array_sum(array_column($notificationWorkers, 'claimed')), 'Independent notification workers claimed the row more than once.');
    worker_same(1, array_sum(array_column($notificationWorkers, 'delivered')), 'Independent notification workers did not acknowledge exactly once.');
    $notificationLines = file($notificationOutbox, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    worker_same(1, count($notificationLines), 'Independent notification workers emitted duplicate side effects.');
    $notificationEnvelope = json_decode($notificationLines[0], true);
    worker_same((string) $notificationRow['idempotency_key'], $notificationEnvelope['idempotency_key'] ?? '', 'Notification envelope idempotency key changed.');
    $deliveredNotification = $pdo->query('SELECT * FROM notification_deliveries WHERE id = ' . (int) $notificationRow['id'])->fetch();
    worker_same('delivered', $deliveredNotification['status'], 'Notification acknowledgement was not persisted.');
    worker_same(1, (int) $deliveredNotification['attempt_count'], 'Notification claim did not increment attempts exactly once.');
    worker_same('file', $deliveredNotification['delivered_to'], 'Notification persisted a non-fixed destination.');

    // Two independent domain-event workers use the same deterministic SQLite serialization contract.
    $pdo->exec('DELETE FROM domain_event_outbox');
    $domainOutbox = $workerRoot . '/domain-concurrency.jsonl';
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH=' . $domainOutbox);
    $domainRow = worker_event($pdo, 'placement.worker.concurrent');
    $domainBarrier = $workerRoot . '/domain.go';
    $domainWorkers = worker_run_pair('domain-event', $domainBarrier);
    worker_same(1, array_sum(array_column($domainWorkers, 'claimed')), 'Independent domain-event workers claimed the row more than once.');
    worker_same(1, array_sum(array_column($domainWorkers, 'delivered')), 'Independent domain-event workers did not acknowledge exactly once.');
    $domainLines = file($domainOutbox, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    worker_same(1, count($domainLines), 'Independent domain-event workers emitted duplicate side effects.');
    $domainEnvelope = json_decode($domainLines[0], true);
    worker_same($domainRow['public_id'], $domainEnvelope['event_id'] ?? '', 'Domain-event idempotency key changed.');
    worker_same('file', $pdo->query('SELECT delivered_to FROM domain_event_outbox WHERE id = ' . (int) $domainRow['id'])->fetchColumn(), 'Domain event persisted a non-fixed destination.');

    // Notification failure mutation, claim loss, acknowledgement loss, stale reclaim, and dead letters.
    putenv('CPE_NOTIFICATION_FILE_OUTBOX_PATH=workerprobe://outbox/notification');
    putenv('CPE_NOTIFICATION_MAX_ATTEMPTS=2');
    $pdo->exec('DELETE FROM notification_deliveries');
    $owned = worker_queue($pdo, 'file')['file'];
    worker_reset_probe();
    WorkerDeliveryStream::$fail = true;
    WorkerDeliveryStream::$failure = $rawFailure;
    $ownedFailure = (new NotificationDeliveryService($pdo))->deliverPending('file', 1, false);
    worker_same(1, $ownedFailure['failed'], 'Owned notification failure was not counted.');
    worker_same(1, $ownedFailure['retrying'], 'Owned notification failure was not scheduled for retry.');
    worker_same(0, $ownedFailure['claim_lost'], 'Owned notification failure was reported as claim lost.');
    $ownedPersisted = $pdo->query('SELECT * FROM notification_deliveries WHERE id = ' . (int) $owned['id'])->fetch();
    worker_same('failed', $ownedPersisted['status'], 'Owned notification failure did not persist retry state.');
    worker_assert(preg_match('/\ACPE_NOTIFICATION_DELIVERY_FAILED Reference: inc_[a-f0-9]{32}\z/D', (string) $ownedPersisted['last_error']) === 1, 'Owned notification failure did not persist a safe incident reference.');

    $pdo->exec('DELETE FROM notification_deliveries');
    $lostFailure = worker_queue($pdo, 'file')['file'];
    worker_reset_probe();
    WorkerDeliveryStream::$fail = true;
    WorkerDeliveryStream::$failure = $rawFailure;
    WorkerDeliveryStream::$beforeWrite = static function () use ($pdo, $lostFailure): void {
        $pdo->exec("UPDATE notification_deliveries SET lock_token = 'claim_stolen' WHERE id = " . (int) $lostFailure['id']);
    };
    $lostFailureResult = (new NotificationDeliveryService($pdo))->deliverPending('file', 1, false);
    worker_same(0, $lostFailureResult['failed'], 'Lost failure claim was counted as a persisted failure.');
    worker_same(1, $lostFailureResult['claim_lost'], 'Lost failure claim was not reported.');
    worker_same('claim-lost', $lostFailureResult['rows'][0]['status'], 'Lost failure claim row status changed.');

    $pdo->exec('DELETE FROM notification_deliveries');
    $lostAck = worker_queue($pdo, 'file')['file'];
    worker_reset_probe();
    WorkerDeliveryStream::$beforeWrite = static function () use ($pdo, $lostAck): void {
        $pdo->exec("UPDATE notification_deliveries SET lock_token = 'claim_stolen' WHERE id = " . (int) $lostAck['id']);
    };
    $lostAckResult = (new NotificationDeliveryService($pdo))->deliverPending('file', 1, false);
    worker_same(0, $lostAckResult['delivered'], 'Lost notification acknowledgement was counted as delivered.');
    worker_same(0, $lostAckResult['failed'], 'Lost notification acknowledgement was misreported as delivery failure.');
    worker_same(1, $lostAckResult['claim_lost'], 'Lost notification acknowledgement was not reported.');
    $firstRetryEnvelope = json_decode(trim(WorkerDeliveryStream::$writes[0]), true);
    $pdo->prepare('UPDATE notification_deliveries SET locked_at = ?, available_at = ? WHERE id = ?')->execute(['2000-01-01 00:00:00', cpe_now(), (int) $lostAck['id']]);
    WorkerDeliveryStream::$beforeWrite = null;
    $retryResult = (new NotificationDeliveryService($pdo))->deliverPending('file', 1, false);
    worker_same(1, $retryResult['delivered'], 'Stale notification claim was not reclaimed.');
    $secondRetryEnvelope = json_decode(trim(WorkerDeliveryStream::$writes[1]), true);
    worker_same($firstRetryEnvelope['idempotency_key'] ?? '', $secondRetryEnvelope['idempotency_key'] ?? '', 'Notification idempotency key changed across crash retry.');

    $pdo->exec('DELETE FROM notification_deliveries');
    putenv('CPE_NOTIFICATION_MAX_ATTEMPTS=1');
    $dead = worker_queue($pdo, 'file')['file'];
    worker_reset_probe();
    WorkerDeliveryStream::$fail = true;
    WorkerDeliveryStream::$failure = $rawFailure;
    $deadResult = (new NotificationDeliveryService($pdo))->deliverPending('file', 1, false);
    worker_same(1, $deadResult['dead_lettered'], 'Notification terminal attempt did not dead-letter.');
    worker_same('dead-lettered', $pdo->query('SELECT status FROM notification_deliveries WHERE id = ' . (int) $dead['id'])->fetchColumn(), 'Notification dead-letter state was not persisted.');

    // Equivalent domain-event truth table and stable retry identity.
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH=workerprobe://outbox/domain');
    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS=2');
    $pdo->exec('DELETE FROM domain_event_outbox');
    $ownedEvent = worker_event($pdo, 'placement.worker.failure');
    worker_reset_probe();
    WorkerDeliveryStream::$fail = true;
    WorkerDeliveryStream::$failure = $rawFailure;
    $ownedEventResult = (new DomainEventOutboxWorker($pdo))->work(1);
    worker_same(1, $ownedEventResult['failed'], 'Owned domain-event failure was not counted.');
    worker_same(1, $ownedEventResult['retrying'], 'Owned domain-event failure was not scheduled for retry.');

    $pdo->exec('DELETE FROM domain_event_outbox');
    $lostEventFailure = worker_event($pdo, 'placement.worker.failure_lost');
    worker_reset_probe();
    WorkerDeliveryStream::$fail = true;
    WorkerDeliveryStream::$failure = $rawFailure;
    WorkerDeliveryStream::$beforeWrite = static function () use ($pdo, $lostEventFailure): void {
        $pdo->exec("UPDATE domain_event_outbox SET lock_token = 'claim_stolen' WHERE id = " . (int) $lostEventFailure['id']);
    };
    $lostEventFailureResult = (new DomainEventOutboxWorker($pdo))->work(1);
    worker_same(0, $lostEventFailureResult['failed'], 'Lost domain-event failure claim was counted.');
    worker_same(1, $lostEventFailureResult['claim_lost'], 'Lost domain-event failure claim was not reported.');

    $pdo->exec('DELETE FROM domain_event_outbox');
    $lostEventAck = worker_event($pdo, 'placement.worker.ack_lost');
    worker_reset_probe();
    WorkerDeliveryStream::$beforeWrite = static function () use ($pdo, $lostEventAck): void {
        $pdo->exec("UPDATE domain_event_outbox SET lock_token = 'claim_stolen' WHERE id = " . (int) $lostEventAck['id']);
    };
    $lostEventAckResult = (new DomainEventOutboxWorker($pdo))->work(1);
    worker_same(0, $lostEventAckResult['delivered'], 'Lost domain-event acknowledgement was counted as delivered.');
    worker_same(0, $lostEventAckResult['failed'], 'Lost domain-event acknowledgement was misreported as delivery failure.');
    worker_same(1, $lostEventAckResult['claim_lost'], 'Lost domain-event acknowledgement was not reported.');
    $firstEventEnvelope = json_decode(trim(WorkerDeliveryStream::$writes[0]), true);
    $pdo->prepare('UPDATE domain_event_outbox SET locked_at = ?, available_at = ? WHERE id = ?')->execute(['2000-01-01 00:00:00', cpe_now(), (int) $lostEventAck['id']]);
    WorkerDeliveryStream::$beforeWrite = null;
    $retriedEvent = (new DomainEventOutboxWorker($pdo))->work(1);
    worker_same(1, $retriedEvent['delivered'], 'Stale domain-event claim was not reclaimed.');
    $secondEventEnvelope = json_decode(trim(WorkerDeliveryStream::$writes[1]), true);
    worker_same($firstEventEnvelope['event_id'] ?? '', $secondEventEnvelope['event_id'] ?? '', 'Domain-event idempotency key changed across crash retry.');

    $pdo->exec('DELETE FROM domain_event_outbox');
    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS=1');
    $deadEvent = worker_event($pdo, 'placement.worker.dead');
    worker_reset_probe();
    WorkerDeliveryStream::$fail = true;
    WorkerDeliveryStream::$failure = $rawFailure;
    $deadEventResult = (new DomainEventOutboxWorker($pdo))->work(1);
    worker_same(1, $deadEventResult['dead_lettered'], 'Domain-event terminal attempt did not dead-letter.');
    worker_assert((string) $pdo->query('SELECT failed_at FROM domain_event_outbox WHERE id = ' . (int) $deadEvent['id'])->fetchColumn() !== '', 'Domain-event dead-letter state was not persisted.');

    // Fixed delivery-row references conceal targets while the controlled sink resolves the real setting.
    $pdo->exec('DELETE FROM notification_deliveries');
    worker_set($pdo, 'notification_email_to', $sentinels['email target']);
    worker_set($pdo, 'notification_sms_to', $sentinels['phone target']);
    worker_set($pdo, 'notification_whatsapp_to', $sentinels['phone target']);
    worker_set($pdo, 'notification_webhook_url', $sentinels['webhook target']);
    $targetRows = worker_queue($pdo, 'email,sms,whatsapp,webhook');
    foreach ($targetRows as $channel => $row) {
        worker_same('[config:notification_' . $channel . ']', $row['target'], 'Queued delivery exposed the ' . $channel . ' target.');
    }
    $messageOutbox = $workerRoot . '/message-outbox.jsonl';
    putenv('CPE_NOTIFICATION_SMS_OUTBOX_PATH=' . $messageOutbox);
    putenv('CPE_NOTIFICATION_FILE_OUTBOX_PATH');
    $smsResult = (new NotificationDeliveryService($pdo))->deliverPending('sms', 1, false);
    worker_same(1, $smsResult['delivered'], 'Controlled SMS sink did not receive the settings-resolved destination.');
    $message = json_decode(trim((string) file_get_contents($messageOutbox)), true);
    worker_same($sentinels['phone target'], $message['to'] ?? '', 'Controlled SMS sink did not receive the actual destination.');
    worker_assert(preg_match('/\Andk_[a-f0-9]{32}\z/D', (string) ($message['idempotency_key'] ?? '')) === 1, 'Message outbox omitted the stable idempotency key.');

    // Environment values override settings only at the side-effect boundary.
    $pdo->exec('DELETE FROM notification_deliveries');
    $environmentPhone = '+971500009999';
    $environmentOutbox = $workerRoot . '/environment-message-outbox.jsonl';
    putenv('CPE_NOTIFICATION_SMS_TO=' . $environmentPhone);
    putenv('CPE_NOTIFICATION_SMS_OUTBOX_PATH=' . $environmentOutbox);
    $environmentRow = worker_queue($pdo, 'sms')['sms'];
    $environmentResult = (new NotificationDeliveryService($pdo))->deliverPending('sms', 1, false);
    worker_same(1, $environmentResult['delivered'], 'Environment-resolved SMS delivery failed.');
    $environmentMessage = json_decode(trim((string) file_get_contents($environmentOutbox)), true);
    worker_same($environmentPhone, $environmentMessage['to'] ?? '', 'Environment destination did not override the stored setting.');
    worker_same('[config:notification_sms]', $environmentRow['target'], 'Environment destination leaked into the delivery row.');
    putenv('CPE_NOTIFICATION_SMS_TO');

    $queuedForCli = worker_queue($pdo, 'email,webhook,whatsapp');
    [$cliCode, $cliStdout, $cliStderr] = worker_cli(['deliver-notifications', '--dry-run']);
    worker_same(0, $cliCode, 'Notification dry-run CLI failed: ' . $cliStderr);
    worker_assert(str_contains($cliStdout, 'Outcome unknown: 0'), 'CLI omitted outcome-unknown count.');
    worker_assert(str_contains($cliStdout, 'Claim lost: 0'), 'CLI omitted claim-lost count.');
    worker_assert_absent($cliStdout . $cliStderr, $sentinels, 'Notification CLI');

    $corruptId = (int) $queuedForCli['email']['id'];
    $pdo->prepare('UPDATE notification_deliveries SET last_error = ? WHERE id = ?')->execute([$rawFailure, $corruptId]);
    $safeId = (int) $queuedForCli['webhook']['id'];
    $safeReference = 'CPE_NOTIFICATION_DELIVERY_FAILED Reference: inc_' . str_repeat('a', 32);
    $pdo->prepare('UPDATE notification_deliveries SET last_error = ? WHERE id = ?')->execute([$safeReference, $safeId]);
    $exportDirectory = $workerRoot . '/export';
    (new SnapshotExporter($pdo))->export($exportDirectory, 'full');
    $deliveryExport = (string) file_get_contents($exportDirectory . '/notification_deliveries.csv');
    $settingsExport = (string) file_get_contents($exportDirectory . '/settings.csv');
    worker_assert(str_contains($deliveryExport, $safeReference), 'Export did not preserve a valid incident reference.');
    worker_assert(str_contains($deliveryExport, 'CPE_PERSISTED_ERROR_REDACTED Reference: inc_unavailable'), 'Export did not redact a corrupt legacy incident reference.');
    worker_assert_absent($deliveryExport . $settingsExport, $sentinels, 'Notification export');
    $pdo->prepare('UPDATE notification_deliveries SET last_error = ? WHERE id = ?')->execute(['', $corruptId]);

    $deliverySurfaces = json_encode([
        $dryRun,
        $notificationWorkers,
        $ownedFailure,
        $lostFailureResult,
        $lostAckResult,
        $deadResult,
        $ownedEventResult,
        $lostEventFailureResult,
        $lostEventAckResult,
        $deadEventResult,
        $smsResult,
        $environmentResult,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $persistedDeliveryEvidence = json_encode(
        $pdo->query('SELECT target, delivered_to, last_error FROM notification_deliveries ORDER BY id')->fetchAll(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );
    worker_assert_absent($deliverySurfaces . $persistedDeliveryEvidence, $sentinels, 'Worker results and persisted delivery evidence');
    if (is_file($workerLog)) {
        $workerLogContents = (string) file_get_contents($workerLog);
        worker_assert_absent($workerLogContents, $sentinels, 'Protected worker log');
        $records = [];
        foreach (file($workerLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }
        foreach ([
            $ownedFailure,
            $lostFailureResult,
            $lostAckResult,
            $deadResult,
            $ownedEventResult,
            $lostEventFailureResult,
            $lostEventAckResult,
            $deadEventResult,
        ] as $incidentResult) {
            foreach ($incidentResult['rows'] as $incidentRow) {
                $reference = (string) ($incidentRow['error'] ?? '');
                if ($reference === '') {
                    continue;
                }
                worker_assert(
                    preg_match('/Reference: (inc_[a-f0-9]{32})\z/D', $reference, $match) === 1,
                    'Worker result omitted a valid opaque incident reference.',
                );
                $matches = array_values(array_filter(
                    $records,
                    static fn (array $record): bool => ($record['context']['incident_id'] ?? null) === $match[1],
                ));
                worker_same(1, count($matches), 'Worker incident did not correlate to one protected record.');
            }
        }
    }

    echo 'PASS worker delivery claim, idempotency, destination, and outcome contract (' . Database::driver() . ")\n";
} finally {
    foreach ([
        'CPE_NOTIFICATION_FILE_OUTBOX_PATH',
        'CPE_NOTIFICATION_SMS_OUTBOX_PATH',
        'CPE_NOTIFICATION_SMS_TO',
        'CPE_NOTIFICATION_MAX_ATTEMPTS',
        'CPE_DOMAIN_EVENT_OUTBOX_PATH',
        'CPE_DOMAIN_EVENT_MAX_ATTEMPTS',
        'CPE_LOG_PATH',
    ] as $key) {
        putenv($key);
    }
    if ($streamRegistered) {
        stream_wrapper_unregister('workerprobe');
    }
    Database::reset();
    worker_remove_tree($workerRoot);
}
