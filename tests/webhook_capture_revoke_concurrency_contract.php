<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/cpe-webhook-capture-revoke-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create webhook capture/revoke fixture directory.');
}
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DB_PATH=' . $testRoot . '/contract.sqlite');
}
$keyMaterial = rtrim(strtr(base64_encode(str_repeat("\x43", 32)), '+/', '-_'), '=');
putenv('CPE_WEBHOOK_ENCRYPTION_KEYS=capture-v1=' . $keyMaterial);
putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION=capture-v1');
putenv('CPE_WEBHOOK_ENDPOINT_CONCURRENCY=1');
putenv('CPE_WEBHOOK_INSTITUTION_CONCURRENCY=1');
putenv('CPE_LOG_PATH=' . $testRoot . '/structured.log');

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';
require $projectRoot . '/tests/authorized_setup_recovery_fixture.php';

use App\Core\Events\DomainEvent;
use App\Core\Events\PublicEventProjection;
use App\Core\Http\UserVisibleException;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Integrations\Webhooks\WebhookDeliveryWorker;
use App\Integrations\Webhooks\WebhookDeliveryReplayService;
use App\Integrations\Webhooks\WebhookHttpResult;
use App\Integrations\Webhooks\WebhookHttpTransport;
use App\Integrations\Webhooks\WebhookSecretCipher;
use App\Integrations\Webhooks\WebhookSubscriptionService;
use App\Support\Database;
use App\Support\StructuredLogger;

function webhook_capture_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function webhook_capture_rejects(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $failure) {
        return $failure;
    }
    throw new RuntimeException($message);
}

function webhook_capture_wait(callable $condition, string $label): void
{
    $deadline = hrtime(true) + 30_000_000_000;
    while (!$condition()) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for webhook ' . $label . '.');
        }
        usleep(5000);
    }
}

function webhook_capture_write(string $path): void
{
    webhook_capture_assert(
        file_put_contents($path, "release\n") !== false,
        'Could not release webhook capture/revoke barrier.',
    );
}

/** @return array{process: resource, stdout: resource, stderr: resource, done: string} */
function webhook_capture_spawn(string $mode, string $ready, string $start, string $done, array $extraEnvironment): array
{
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    foreach ($extraEnvironment as $name => $value) {
        $environment[$name] = $value;
    }
    $environment['CPE_WEBHOOK_CAPTURE_TEST_MODE'] = $mode;
    $environment['CPE_WEBHOOK_CAPTURE_TEST_READY'] = $ready;
    $environment['CPE_WEBHOOK_CAPTURE_TEST_START'] = $start;
    $environment['CPE_WEBHOOK_CAPTURE_TEST_DONE'] = $done;
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/webhook_capture_revoke_concurrency_worker.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start webhook capture/revoke worker.');
    }
    fclose($pipes[0]);
    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'done' => $done];
}

/** @param array{process: resource, stdout: resource, stderr: resource, done: string} $worker */
function webhook_capture_collect(array $worker): array
{
    webhook_capture_wait(static fn (): bool => is_file($worker['done']), 'worker result');
    $status = null;
    webhook_capture_wait(
        static function () use ($worker, &$status): bool {
            $observed = proc_get_status($worker['process']);
            if (!is_array($observed) || ($observed['running'] ?? false)) {
                return false;
            }
            $status = $observed;
            return true;
        },
        'worker completion',
    );
    $stdout = (string) stream_get_contents($worker['stdout']);
    $stderr = (string) stream_get_contents($worker['stderr']);
    fclose($worker['stdout']);
    fclose($worker['stderr']);
    $closeCode = proc_close($worker['process']);
    $capturedExitCode = is_array($status) ? (int) ($status['exitcode'] ?? -1) : -1;
    $exitCode = $capturedExitCode >= 0 ? $capturedExitCode : $closeCode;
    $decoded = json_decode(trim($stdout), true);
    webhook_capture_assert(
        $exitCode === 0 && is_array($decoded) && ($decoded['status'] ?? '') === 'ok',
        'Webhook capture/revoke worker failed: ' . $stdout . ' ' . $stderr,
    );
    return $decoded;
}

/** @param list<array{process: resource, stdout: resource, stderr: resource, done: string}> $workers */
function webhook_capture_stop(array &$workers): void
{
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
        }
        foreach (['stdout', 'stderr'] as $pipe) {
            if (is_resource($worker[$pipe])) {
                fclose($worker[$pipe]);
            }
        }
        if (is_resource($worker['process'])) {
            proc_close($worker['process']);
        }
    }
    $workers = [];
}

function webhook_capture_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

/** @return array{advisory: int, transaction: int} */
function webhook_capture_postgres_waits(PDO $pdo, int $advisoryObject = 5003): array
{
    $row = $pdo->query(
        "SELECT
            SUM(CASE WHEN lock.locktype = 'advisory'
                      AND lock.classid = 1900000003::oid AND lock.objid = {$advisoryObject}::oid
                     THEN 1 ELSE 0 END) AS advisory_waiters,
            SUM(CASE WHEN lock.locktype = 'transactionid' THEN 1 ELSE 0 END) AS transaction_waiters
         FROM pg_catalog.pg_locks lock
         JOIN pg_catalog.pg_stat_activity activity ON activity.pid = lock.pid
         WHERE activity.datname = current_database() AND lock.granted = false",
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'advisory' => (int) ($row['advisory_waiters'] ?? 0),
        'transaction' => (int) ($row['transaction_waiters'] ?? 0),
    ];
}

final class WebhookCaptureValidationTransport implements WebhookHttpTransport
{
    public int $calls = 0;

    public function send(string $endpointUrl, string $body, array $headers, bool $allowPrivateNetwork): WebhookHttpResult
    {
        $this->calls++;
        return new WebhookHttpResult(204);
    }
}

final class WebhookCaptureNoSendTransport implements WebhookHttpTransport
{
    public int $calls = 0;

    public function send(string $endpointUrl, string $body, array $headers, bool $allowPrivateNetwork): WebhookHttpResult
    {
        $this->calls++;
        return new WebhookHttpResult(204);
    }
}

final class WebhookFairnessTransport implements WebhookHttpTransport
{
    /** @var list<array{endpoint: string, version: int}> */
    public array $calls = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function send(string $endpointUrl, string $body, array $headers, bool $allowPrivateNetwork): WebhookHttpResult
    {
        $endpoint = $this->pdo->prepare(
            "SELECT COUNT(*) FROM webhook_deliveries delivery
             JOIN webhook_subscriptions subscription ON subscription.id = delivery.subscription_id
             WHERE subscription.endpoint_url = ? AND delivery.status = 'processing'",
        );
        $endpoint->execute([$endpointUrl]);
        webhook_capture_assert(
            (int) $endpoint->fetchColumn() === 1,
            'Repeated work(1) exceeded or lost the endpoint processing cap.',
        );
        webhook_capture_assert(
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'processing'",
            )->fetchColumn() === 1,
            'Repeated work(1) exceeded or lost the institution processing cap.',
        );
        $envelope = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        $this->calls[] = [
            'endpoint' => $endpointUrl,
            'version' => (int) ($envelope['aggregate']['version'] ?? 0),
        ];
        return new WebhookHttpResult(204);
    }
}

function webhook_capture_activate(
    WebhookSubscriptionService $service,
    string $name,
    string $endpointUrl,
): string {
    $created = $service->create($name, $endpointUrl, true, false, 1);
    webhook_capture_assert(
        is_string($created['signing_secret']),
        'Webhook capture/revoke fixture did not receive a signing secret.',
    );
    $publicId = (string) $created['subscription_id'];
    $service->validate($publicId, 1);
    $service->activate($publicId, 1);
    return $publicId;
}

function webhook_capture_dispatch(PDO $pdo, string $aggregateId, int $version): string
{
    $instanceId = (string) $pdo->query(
        "SELECT public_id FROM institutions WHERE slug = 'default'",
    )->fetchColumn();
    return cpe_context()->events()->dispatch(new DomainEvent(
        'placement.application.transitioned',
        'placement_application',
        $aggregateId,
        'placement',
        ['private_application_id' => $version],
        cpe_now(),
        PublicEventProjection::applicationStatusChanged(
            $instanceId,
            $aggregateId,
            $version,
            'stage_' . $version,
            'stage_' . ($version + 1),
            StructuredLogger::requestId(),
        ),
    ));
}

$workers = [];
$advisoryLocked = false;
$gateInstalled = false;
$expiryAdvisoryLocked = false;
$expiryGateInstalled = false;
$expiryReverseIndexInstalled = false;
$captureStart = $testRoot . '/capture-start';
$revokeStart = $testRoot . '/revoke-start';
$expiryStart = $testRoot . '/expiry-start';
$expiryCaptureStart = $testRoot . '/expiry-capture-start';
try {
    (new SystemRequirements())->assertReady();
    $pdo = Database::connection();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'pgsql') {
        webhook_capture_assert(
            (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()",
            )->fetchColumn() === 0,
            'PostgreSQL webhook capture/revoke contract requires a fresh dedicated database.',
        );
    }
    Database::migrate();
    (new Installer())->installHosted([
        'college_name' => 'Webhook Capture Concurrency College',
        'timezone' => 'UTC',
        'admin_name' => 'Webhook Capture Administrator',
        'admin_email' => 'webhook-capture@example.test',
        'admin_password' => 'webhook-capture-password-123',
        'seed_demo' => '0',
    ], 'tenant_' . str_repeat('e', 32), test_authorized_setup_recovery_authority());
    $pdo = Database::connection();
    $validationTransport = new WebhookCaptureValidationTransport();
    $cipher = WebhookSecretCipher::fromEnvironment();
    $service = new WebhookSubscriptionService($pdo, $validationTransport, $cipher);
    $raceSubscription = webhook_capture_activate(
        $service,
        'Capture/revoke race receiver',
        'https://capture-race.example.test/hook',
    );
    $raceAggregate = 'application_' . str_repeat('e', 32);
    $raceEventId = '';

    if ($driver === 'pgsql') {
        $pdo->exec(
            "CREATE FUNCTION cpe_webhook_capture_order_test_gate() RETURNS trigger LANGUAGE plpgsql AS $$
             BEGIN
                 PERFORM pg_advisory_xact_lock(1900000003, 5003);
                 RETURN NEW;
             END;
             $$",
        );
        $pdo->exec(
            'CREATE TRIGGER webhook_capture_order_test_gate BEFORE INSERT ON webhook_deliveries
             FOR EACH ROW EXECUTE FUNCTION cpe_webhook_capture_order_test_gate()',
        );
        $gateInstalled = true;
        $pdo->query('SELECT pg_advisory_lock(1900000003, 5003)')->fetchColumn();
        $advisoryLocked = true;

        $captureReady = $testRoot . '/capture-ready';
        $captureDone = $testRoot . '/capture-done';
        $captureWorker = webhook_capture_spawn('capture', $captureReady, $captureStart, $captureDone, [
            'CPE_WEBHOOK_CAPTURE_TEST_AGGREGATE' => $raceAggregate,
            'CPE_WEBHOOK_CAPTURE_TEST_SUBSCRIPTION' => '',
        ]);
        $workers[] = $captureWorker;
        webhook_capture_wait(static fn (): bool => is_file($captureReady), 'capture worker readiness');
        webhook_capture_write($captureStart);
        webhook_capture_wait(
            static fn (): bool => webhook_capture_postgres_waits($pdo)['advisory'] >= 1,
            'capture insert gate',
        );

        $revokeReady = $testRoot . '/revoke-ready';
        $revokeDone = $testRoot . '/revoke-done';
        $revokeWorker = webhook_capture_spawn('revoke', $revokeReady, $revokeStart, $revokeDone, [
            'CPE_WEBHOOK_CAPTURE_TEST_AGGREGATE' => '',
            'CPE_WEBHOOK_CAPTURE_TEST_SUBSCRIPTION' => $raceSubscription,
        ]);
        $workers[] = $revokeWorker;
        webhook_capture_wait(static fn (): bool => is_file($revokeReady), 'revoke worker readiness');
        webhook_capture_write($revokeStart);
        webhook_capture_wait(
            static fn (): bool => webhook_capture_postgres_waits($pdo)['transaction'] >= 1 || is_file($revokeDone),
            'subscription lock conflict',
        );
        $waits = webhook_capture_postgres_waits($pdo);
        webhook_capture_assert(
            $waits['transaction'] >= 1 && !is_file($revokeDone),
            'Webhook revoke was not serialized behind a capture-time FOR UPDATE subscription lock.',
        );

        webhook_capture_assert(
            (bool) $pdo->query('SELECT pg_advisory_unlock(1900000003, 5003)')->fetchColumn(),
            'Could not release PostgreSQL webhook capture gate.',
        );
        $advisoryLocked = false;
        $captureResult = webhook_capture_collect($captureWorker);
        array_shift($workers);
        $revokeResult = webhook_capture_collect($revokeWorker);
        array_shift($workers);
        $raceEventId = (string) ($captureResult['event_id'] ?? '');
        webhook_capture_assert(
            ($revokeResult['mode'] ?? '') === 'revoke',
            'PostgreSQL webhook revoke worker did not complete the intended operation.',
        );
        $pdo->exec('DROP TRIGGER webhook_capture_order_test_gate ON webhook_deliveries');
        $pdo->exec('DROP FUNCTION cpe_webhook_capture_order_test_gate()');
        $gateInstalled = false;
    } else {
        $raceEventId = webhook_capture_dispatch($pdo, $raceAggregate, 2);
        $service->revoke($raceSubscription, 1);
    }

    $revokedDelivery = $pdo->prepare(
        "SELECT delivery.public_id, delivery.status, delivery.last_error_code
         FROM webhook_deliveries delivery
         JOIN domain_event_outbox event ON event.id = delivery.event_id
         WHERE event.public_id = ? AND delivery.subscription_id = (
             SELECT id FROM webhook_subscriptions WHERE public_id = ?
         )",
    );
    $revokedDelivery->execute([$raceEventId, $raceSubscription]);
    $revokedState = $revokedDelivery->fetch(PDO::FETCH_ASSOC);
    webhook_capture_assert(
        is_array($revokedState)
            && (string) $revokedState['status'] === 'dead_lettered'
            && (string) $revokedState['last_error_code'] === 'subscription_revoked',
        'Capture/revoke serialization left a revocation-era delivery eligible for future work.',
    );
    $deadLetters = $service->deadLettersForAdministrator();
    $revokedDeadLetter = array_values(array_filter(
        $deadLetters,
        static fn (array $row): bool => (string) $row['public_id'] === (string) $revokedState['public_id'],
    ));
    webhook_capture_assert(
        count($revokedDeadLetter) === 1 && $revokedDeadLetter[0]['replayable'] === false,
        'Administrator state offered replay for a delivery terminated by revocation.',
    );

    $regeneratedSecret = $service->generateSecret($raceSubscription, 1);
    webhook_capture_assert($regeneratedSecret !== '', 'Revoked webhook subscription could not enter clean reactivation.');
    $service->validate($raceSubscription, 1);
    $service->activate($raceSubscription, 1);
    $replayFailure = webhook_capture_rejects(
        static fn (): array => (new WebhookDeliveryReplayService($pdo))->replay(
            (string) $revokedState['public_id'],
            1,
        ),
        'A delivery terminated by revocation became replayable after reactivation.',
    );
    webhook_capture_assert(
        $replayFailure instanceof UserVisibleException
            && $replayFailure->publicCode() === 'WEBHOOK_REPLAY_REVOKED',
        'Revoked delivery replay did not fail with the stable terminal-revocation code.',
    );
    $noSend = new WebhookCaptureNoSendTransport();
    $reactivatedWorker = new WebhookDeliveryWorker($pdo, $noSend, $cipher);
    $reactivatedWork = $reactivatedWorker->work(1);
    webhook_capture_assert(
        (int) $reactivatedWork['claimed'] === 0 && $noSend->calls === 0,
        'A revocation-era delivery appeared or delivered after subscription reactivation.',
    );
    webhook_capture_dispatch($pdo, $raceAggregate, 3);
    $futureWork = $reactivatedWorker->work(1);
    webhook_capture_assert(
        (int) $futureWork['claimed'] === 1
            && (int) $futureWork['succeeded'] === 1
            && $noSend->calls === 1,
        'A terminal revoked predecessor blocked an ordinary future event after clean reactivation.',
    );
    $service->disable($raceSubscription, 1);

    if ($driver === 'pgsql') {
        $expiryLow = webhook_capture_activate(
            $service,
            'Expiry ordering receiver A',
            'https://expiry-order-a.example.test/hook',
        );
        $expiryHigh = webhook_capture_activate(
            $service,
            'Expiry ordering receiver B',
            'https://expiry-order-b.example.test/hook',
        );
        $service->rotateSecret($expiryLow, 1);
        $service->rotateSecret($expiryHigh, 1);
        $expiredAt = '2000-01-01 00:00:00';
        $expireFixture = $pdo->prepare(
            'UPDATE webhook_subscriptions SET previous_secret_expires_at = ?, updated_at = ?
             WHERE public_id IN (?, ?)',
        );
        $expireFixture->execute([$expiredAt, cpe_now(), $expiryLow, $expiryHigh]);
        webhook_capture_assert(
            $expireFixture->rowCount() === 2,
            'PostgreSQL capture/expiry fixture did not expire exactly two subscriptions.',
        );
        $expiryIds = $pdo->prepare(
            'SELECT id, public_id FROM webhook_subscriptions WHERE public_id IN (?, ?) ORDER BY id',
        );
        $expiryIds->execute([$expiryLow, $expiryHigh]);
        $expiryRows = $expiryIds->fetchAll(PDO::FETCH_ASSOC);
        webhook_capture_assert(
            count($expiryRows) === 2 && (int) $expiryRows[0]['id'] < (int) $expiryRows[1]['id'],
            'PostgreSQL capture/expiry fixture did not produce two ordered subscription rows.',
        );
        $expiryHighId = (int) $expiryRows[1]['id'];

        $pdo->exec('CREATE INDEX webhook_expiry_reverse_order_test_idx ON webhook_subscriptions (id DESC)');
        $expiryReverseIndexInstalled = true;
        $pdo->exec('CLUSTER webhook_subscriptions USING webhook_expiry_reverse_order_test_idx');
        $pdo->exec('ANALYZE webhook_subscriptions');
        $pdo->exec(
            "CREATE FUNCTION cpe_webhook_expiry_order_test_gate() RETURNS trigger LANGUAGE plpgsql AS $$
             BEGIN
                 IF OLD.id = {$expiryHighId}
                    AND OLD.previous_secret_expires_at IS NOT NULL
                    AND NEW.previous_secret_expires_at IS NULL THEN
                     PERFORM pg_advisory_xact_lock(1900000003, 5004);
                 END IF;
                 RETURN NEW;
             END;
             $$",
        );
        $pdo->exec(
            'CREATE TRIGGER webhook_expiry_order_test_gate
             BEFORE UPDATE OF previous_secret_ciphertext, previous_secret_nonce,
                 previous_secret_tag, previous_secret_key_version, previous_secret_expires_at
             ON webhook_subscriptions
             FOR EACH ROW EXECUTE FUNCTION cpe_webhook_expiry_order_test_gate()',
        );
        $expiryGateInstalled = true;
        $pdo->query('SELECT pg_advisory_lock(1900000003, 5004)')->fetchColumn();
        $expiryAdvisoryLocked = true;

        $expiryReady = $testRoot . '/expiry-ready';
        $expiryDone = $testRoot . '/expiry-done';
        $expiryWorker = webhook_capture_spawn('expire', $expiryReady, $expiryStart, $expiryDone, [
            'CPE_WEBHOOK_CAPTURE_TEST_AGGREGATE' => '',
            'CPE_WEBHOOK_CAPTURE_TEST_SUBSCRIPTION' => '',
            'CPE_WEBHOOK_CAPTURE_TEST_REVERSE_SCAN' => '1',
        ]);
        $workers[] = $expiryWorker;
        webhook_capture_wait(static fn (): bool => is_file($expiryReady), 'expiry worker readiness');
        webhook_capture_write($expiryStart);
        webhook_capture_wait(
            static fn (): bool => webhook_capture_postgres_waits($pdo, 5004)['advisory'] >= 1,
            'secret-expiry update gate',
        );

        $expiryCaptureReady = $testRoot . '/expiry-capture-ready';
        $expiryCaptureDone = $testRoot . '/expiry-capture-done';
        $expiryAggregate = 'application_' . str_repeat('c', 32);
        $expiryCaptureWorker = webhook_capture_spawn(
            'capture',
            $expiryCaptureReady,
            $expiryCaptureStart,
            $expiryCaptureDone,
            [
                'CPE_WEBHOOK_CAPTURE_TEST_AGGREGATE' => $expiryAggregate,
                'CPE_WEBHOOK_CAPTURE_TEST_SUBSCRIPTION' => '',
            ],
        );
        $workers[] = $expiryCaptureWorker;
        webhook_capture_wait(
            static fn (): bool => is_file($expiryCaptureReady),
            'capture/expiry capture worker readiness',
        );
        webhook_capture_write($expiryCaptureStart);
        webhook_capture_wait(
            static fn (): bool => webhook_capture_postgres_waits($pdo, 5004)['transaction'] >= 1
                || is_file($expiryCaptureDone),
            'capture/expiry subscription lock conflict',
        );
        $expiryWaits = webhook_capture_postgres_waits($pdo, 5004);
        webhook_capture_assert(
            $expiryWaits['transaction'] >= 1 && !is_file($expiryCaptureDone),
            'Webhook capture was not serialized behind ordered secret-expiry subscription locks.',
        );

        webhook_capture_assert(
            (bool) $pdo->query('SELECT pg_advisory_unlock(1900000003, 5004)')->fetchColumn(),
            'Could not release PostgreSQL webhook secret-expiry gate.',
        );
        $expiryAdvisoryLocked = false;
        $expiryResult = webhook_capture_collect($expiryWorker);
        array_shift($workers);
        $expiryCaptureResult = webhook_capture_collect($expiryCaptureWorker);
        array_shift($workers);
        webhook_capture_assert(
            ($expiryResult['mode'] ?? '') === 'expire'
                && (int) ($expiryResult['claimed'] ?? -1) === 0
                && (int) ($expiryResult['transport_calls'] ?? -1) === 0,
            'Secret-expiry concurrency worker performed delivery or network work.',
        );
        $expiryCaptureEventId = (string) ($expiryCaptureResult['event_id'] ?? '');
        $expiredSecrets = $pdo->prepare(
            'SELECT COUNT(*) FROM webhook_subscriptions
             WHERE public_id IN (?, ?) AND previous_secret_expires_at IS NOT NULL',
        );
        $expiredSecrets->execute([$expiryLow, $expiryHigh]);
        webhook_capture_assert(
            (int) $expiredSecrets->fetchColumn() === 0,
            'Ordered secret-expiry cleanup left an expired overlap behind.',
        );
        $capturedExpiryDeliveries = $pdo->prepare(
            'SELECT COUNT(*) FROM webhook_deliveries delivery
             JOIN domain_event_outbox event ON event.id = delivery.event_id
             WHERE event.public_id = ? AND delivery.status = ?',
        );
        $capturedExpiryDeliveries->execute([$expiryCaptureEventId, 'pending']);
        webhook_capture_assert(
            (int) $capturedExpiryDeliveries->fetchColumn() === 2,
            'Capture/expiry serialization did not preserve both eligible deliveries.',
        );

        $pdo->exec('DROP TRIGGER webhook_expiry_order_test_gate ON webhook_subscriptions');
        $pdo->exec('DROP FUNCTION cpe_webhook_expiry_order_test_gate()');
        $expiryGateInstalled = false;
        $pdo->exec('DROP INDEX webhook_expiry_reverse_order_test_idx');
        $expiryReverseIndexInstalled = false;
        $service->revoke($expiryLow, 1);
        $service->revoke($expiryHigh, 1);
    }

    $fairAUrl = 'https://fair-a.example.test/hook';
    $fairBUrl = 'https://fair-b.example.test/hook';
    $fairA = webhook_capture_activate($service, 'Fair receiver A', $fairAUrl);
    $fairAggregate = 'application_' . str_repeat('f', 32);
    foreach (range(2, 5) as $version) {
        webhook_capture_dispatch($pdo, $fairAggregate, $version);
    }
    $fairB = webhook_capture_activate($service, 'Fair receiver B', $fairBUrl);
    foreach (range(6, 9) as $version) {
        webhook_capture_dispatch($pdo, $fairAggregate, $version);
    }
    $pdo->exec('UPDATE webhook_worker_heartbeat SET claim_cursor_subscription_id = 0 WHERE singleton_id = 1');

    $fairTransport = new WebhookFairnessTransport($pdo);
    $fairWorker = new WebhookDeliveryWorker($pdo, $fairTransport, $cipher);
    foreach (range(1, 6) as $_iteration) {
        $work = $fairWorker->work(1);
        webhook_capture_assert(
            (int) $work['claimed'] === 1 && (int) $work['succeeded'] === 1,
            'Repeated work(1) did not claim and complete exactly one delivery.',
        );
    }
    webhook_capture_assert(
        array_column($fairTransport->calls, 'endpoint') === [
            $fairAUrl, $fairBUrl, $fairAUrl, $fairBUrl, $fairAUrl, $fairBUrl,
        ],
        'Persisted round-robin fairness did not advance both endpoints under a deep older backlog.',
    );
    $versionsByEndpoint = [$fairAUrl => [], $fairBUrl => []];
    foreach ($fairTransport->calls as $call) {
        $versionsByEndpoint[$call['endpoint']][] = $call['version'];
    }
    webhook_capture_assert(
        $versionsByEndpoint[$fairAUrl] === [2, 3, 4]
            && $versionsByEndpoint[$fairBUrl] === [6, 7, 8],
        'Round-robin fairness broke per-subscription aggregate ordering.',
    );
    $fairBId = $pdo->prepare('SELECT id FROM webhook_subscriptions WHERE public_id = ?');
    $fairBId->execute([$fairB]);
    webhook_capture_assert(
        (int) $pdo->query(
            'SELECT claim_cursor_subscription_id FROM webhook_worker_heartbeat WHERE singleton_id = 1',
        )->fetchColumn() === (int) $fairBId->fetchColumn(),
        'Heartbeat UPSERT did not preserve the last successfully claimed subscription cursor.',
    );
    webhook_capture_assert($fairA !== $fairB, 'Fairness fixture subscriptions were not distinct.');
    $service->disable($fairA, 1);
    $service->disable($fairB, 1);

    $batchInstitutionId = (int) $pdo->query(
        "SELECT id FROM institutions WHERE slug = 'default'",
    )->fetchColumn();
    $batchActorId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    $batchCreatedAt = cpe_now();
    $batchInsert = $pdo->prepare(
        "INSERT INTO webhook_subscriptions
         (public_id, institution_id, name, endpoint_url, lifecycle_state, allow_private_network,
          previous_secret_ciphertext, previous_secret_nonce, previous_secret_tag,
          previous_secret_key_version, previous_secret_expires_at, created_by_user_id,
          created_at, updated_at)
         VALUES (?, ?, ?, ?, 'disabled', 0, ?, ?, ?, ?, ?, ?, ?, ?)",
    );
    $batchPublicIds = [];
    foreach (range(1, 101) as $batchIndex) {
        $batchPublicId = 'whsub_' . substr(hash('sha256', 'expiry-batch-' . $batchIndex), 0, 32);
        $batchPublicIds[] = $batchPublicId;
        $batchInsert->execute([
            $batchPublicId,
            $batchInstitutionId,
            'Expiry batch receiver ' . $batchIndex,
            'https://expiry-batch-' . $batchIndex . '.example.test/hook',
            str_repeat('a', 64),
            str_repeat('b', 24),
            str_repeat('c', 24),
            'capture-v1',
            '2000-01-01 00:00:00',
            $batchActorId,
            $batchCreatedAt,
            $batchCreatedAt,
        ]);
    }
    $batchPlaceholders = implode(', ', array_fill(0, count($batchPublicIds), '?'));
    $batchState = $pdo->prepare(
        "SELECT id, previous_secret_expires_at FROM webhook_subscriptions
         WHERE public_id IN ({$batchPlaceholders}) ORDER BY id",
    );
    $batchState->execute($batchPublicIds);
    $batchRowsBefore = $batchState->fetchAll(PDO::FETCH_ASSOC);
    webhook_capture_assert(
        count($batchRowsBefore) === 101,
        'Secret-expiry portability fixture did not create 101 isolated rows.',
    );
    $batchIds = array_map(static fn (array $row): int => (int) $row['id'], $batchRowsBefore);
    $batchTransport = new WebhookCaptureNoSendTransport();
    $batchWorker = new WebhookDeliveryWorker($pdo, $batchTransport, $cipher);
    $batchFirstWork = $batchWorker->work(1);
    webhook_capture_assert(
        (int) $batchFirstWork['claimed'] === 0 && $batchTransport->calls === 0,
        'Bounded secret-expiry cleanup performed delivery or network work.',
    );
    $batchState->execute($batchPublicIds);
    $batchRowsAfterFirstWork = $batchState->fetchAll(PDO::FETCH_ASSOC);
    $clearedBatchIds = [];
    $remainingBatchIds = [];
    foreach ($batchRowsAfterFirstWork as $batchRow) {
        if (($batchRow['previous_secret_expires_at'] ?? null) === null) {
            $clearedBatchIds[] = (int) $batchRow['id'];
        } else {
            $remainingBatchIds[] = (int) $batchRow['id'];
        }
    }
    webhook_capture_assert(
        $clearedBatchIds === array_slice($batchIds, 0, 100)
            && $remainingBatchIds === [$batchIds[100]],
        'Secret-expiry cleanup was not bounded to the first 100 prelocked subscription IDs.',
    );
    $batchSecondWork = $batchWorker->work(1);
    webhook_capture_assert(
        (int) $batchSecondWork['claimed'] === 0 && $batchTransport->calls === 0,
        'Secret-expiry continuation performed delivery or network work.',
    );
    $batchState->execute($batchPublicIds);
    webhook_capture_assert(
        count(array_filter(
            $batchState->fetchAll(PDO::FETCH_ASSOC),
            static fn (array $row): bool => ($row['previous_secret_expires_at'] ?? null) !== null,
        )) === 0,
        'Secret-expiry continuation did not clear the final bounded-batch row.',
    );

    echo 'PASS webhook capture/revoke and fairness contract (' . $driver . ")\n";
} finally {
    if (!is_file($captureStart)) {
        webhook_capture_write($captureStart);
    }
    if (!is_file($revokeStart)) {
        webhook_capture_write($revokeStart);
    }
    if (!is_file($expiryStart)) {
        webhook_capture_write($expiryStart);
    }
    if (!is_file($expiryCaptureStart)) {
        webhook_capture_write($expiryCaptureStart);
    }
    if (isset($pdo) && $pdo instanceof PDO && $advisoryLocked) {
        try {
            $pdo->query('SELECT pg_advisory_unlock(1900000003, 5003)')->fetchColumn();
        } catch (Throwable) {
        }
    }
    if (isset($pdo) && $pdo instanceof PDO && $expiryAdvisoryLocked) {
        try {
            $pdo->query('SELECT pg_advisory_unlock(1900000003, 5004)')->fetchColumn();
        } catch (Throwable) {
        }
    }
    webhook_capture_stop($workers);
    if (isset($pdo) && $pdo instanceof PDO && $gateInstalled) {
        try {
            $pdo->exec('DROP TRIGGER IF EXISTS webhook_capture_order_test_gate ON webhook_deliveries');
            $pdo->exec('DROP FUNCTION IF EXISTS cpe_webhook_capture_order_test_gate()');
        } catch (Throwable) {
        }
    }
    if (isset($pdo) && $pdo instanceof PDO && $expiryGateInstalled) {
        try {
            $pdo->exec('DROP TRIGGER IF EXISTS webhook_expiry_order_test_gate ON webhook_subscriptions');
            $pdo->exec('DROP FUNCTION IF EXISTS cpe_webhook_expiry_order_test_gate()');
        } catch (Throwable) {
        }
    }
    if (isset($pdo) && $pdo instanceof PDO && $expiryReverseIndexInstalled) {
        try {
            $pdo->exec('DROP INDEX IF EXISTS webhook_expiry_reverse_order_test_idx');
        } catch (Throwable) {
        }
    }
    Database::reset();
    putenv('CPE_WEBHOOK_ENCRYPTION_KEYS');
    putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION');
    putenv('CPE_WEBHOOK_ENDPOINT_CONCURRENCY');
    putenv('CPE_WEBHOOK_INSTITUTION_CONCURRENCY');
    putenv('CPE_LOG_PATH');
    webhook_capture_remove_tree($testRoot);
}
