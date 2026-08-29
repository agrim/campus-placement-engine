<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/cpe-webhook-concurrency-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create webhook concurrency fixture directory.');
}
$temporarySqlite = null;
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    $temporarySqlite = $testRoot . '/contract.sqlite';
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DB_PATH=' . $temporarySqlite);
}
$keyMaterial = rtrim(strtr(base64_encode(str_repeat("\x43", 32)), '+/', '-_'), '=');
putenv('CPE_WEBHOOK_ENCRYPTION_KEYS=concurrency-v1=' . $keyMaterial);
putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION=concurrency-v1');
putenv('CPE_WEBHOOK_MAX_ATTEMPTS=10');
putenv('CPE_LOG_PATH=' . $testRoot . '/structured.log');

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';
require $projectRoot . '/tests/authorized_setup_recovery_fixture.php';

use App\Core\Events\DomainEvent;
use App\Core\Events\PublicEventProjection;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Integrations\Webhooks\WebhookHttpResult;
use App\Integrations\Webhooks\WebhookHttpTransport;
use App\Integrations\Webhooks\WebhookSecretCipher;
use App\Integrations\Webhooks\WebhookSubscriptionService;
use App\Support\Database;
use App\Support\StructuredLogger;

function webhook_concurrency_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function webhook_concurrency_wait(callable $condition, string $label, int $timeoutMilliseconds = 30000): void
{
    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    while (!$condition()) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for webhook ' . $label . '.');
        }
        usleep(5000);
    }
}

function webhook_concurrency_write(string $path): void
{
    webhook_concurrency_assert(file_put_contents($path, "release\n") !== false, 'Could not release webhook concurrency barrier.');
}

function webhook_concurrency_remove_tree(string $path): void
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

final class WebhookConcurrencyValidationTransport implements WebhookHttpTransport
{
    public function send(string $endpointUrl, string $body, array $headers, bool $allowPrivateNetwork): WebhookHttpResult
    {
        return new WebhookHttpResult(204);
    }
}

/** @return array{process: resource, stdout: resource, stderr: resource, participant: string} */
function webhook_concurrency_spawn(
    string $participant,
    string $mode,
    string $ready,
    string $start,
    string $sendReady,
    string $sendRelease,
    string $failureReady,
    string $failureRelease,
): array {
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['CPE_WEBHOOK_TEST_PARTICIPANT'] = $participant;
    $environment['CPE_WEBHOOK_TEST_MODE'] = $mode;
    $environment['CPE_WEBHOOK_TEST_READY'] = $ready;
    $environment['CPE_WEBHOOK_TEST_START'] = $start;
    $environment['CPE_WEBHOOK_TEST_SEND_READY'] = $sendReady;
    $environment['CPE_WEBHOOK_TEST_SEND_RELEASE'] = $sendRelease;
    $environment['CPE_WEBHOOK_TEST_FAILURE_READY'] = $failureReady;
    $environment['CPE_WEBHOOK_TEST_FAILURE_RELEASE'] = $failureRelease;
    $environment['CPE_WEBHOOK_TEST_LIMIT'] = '1';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/webhook_delivery_concurrency_worker.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start webhook concurrency worker ' . $participant . '.');
    }
    fclose($pipes[0]);
    return [
        'process' => $process,
        'stdout' => $pipes[1],
        'stderr' => $pipes[2],
        'participant' => $participant,
    ];
}

/**
 * @param list<array{process: resource, stdout: resource, stderr: resource, participant: string}> $workers
 * @return list<array<string, mixed>>
 */
function webhook_concurrency_collect(array &$workers): array
{
    $results = [];
    $deadline = hrtime(true) + 30_000_000_000;
    while ($workers !== []) {
        foreach ($workers as $index => $worker) {
            $status = proc_get_status($worker['process']);
            if (!is_array($status) || ($status['running'] ?? false)) {
                continue;
            }
            $stdout = (string) stream_get_contents($worker['stdout']);
            $stderr = (string) stream_get_contents($worker['stderr']);
            fclose($worker['stdout']);
            fclose($worker['stderr']);
            $closeCode = proc_close($worker['process']);
            $exitCode = (int) ($status['exitcode'] ?? $closeCode);
            if ($exitCode < 0) {
                $exitCode = $closeCode;
            }
            $decoded = json_decode(trim($stdout), true);
            webhook_concurrency_assert(
                $exitCode === 0 && is_array($decoded) && ($decoded['status'] ?? '') === 'ok',
                'Webhook concurrency worker failed: ' . $stdout . ' ' . $stderr,
            );
            $results[] = $decoded;
            unset($workers[$index]);
        }
        if ($workers === []) {
            break;
        }
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Webhook concurrency workers exceeded the bounded deadline.');
        }
        usleep(5000);
    }
    return $results;
}

/** @param list<array{process: resource, stdout: resource, stderr: resource, participant: string}> $workers */
function webhook_concurrency_stop(array &$workers): void
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

function webhook_concurrency_subscription(PDO $pdo, string $label): string
{
    $service = new WebhookSubscriptionService(
        $pdo,
        new WebhookConcurrencyValidationTransport(),
        WebhookSecretCipher::fromEnvironment(),
    );
    $created = $service->create(
        $label,
        'https://' . strtolower(str_replace(' ', '-', $label)) . '.example.test/hook',
        true,
        false,
        1,
    );
    $service->validate($created['subscription_id'], 1);
    $service->activate($created['subscription_id'], 1);
    return $created['subscription_id'];
}

function webhook_concurrency_dispatch(PDO $pdo, int $sequence): void
{
    $instance = (string) $pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $aggregate = 'application_' . str_pad(dechex(4096 + $sequence), 32, '0', STR_PAD_LEFT);
    cpe_context()->events()->dispatch(new DomainEvent(
        'placement.application.transitioned',
        'placement_application',
        $aggregate,
        'placement',
        ['private_application_id' => $sequence],
        cpe_now(),
        PublicEventProjection::applicationStatusChanged(
            $instance,
            $aggregate,
            2,
            'idle',
            'scheduled',
            StructuredLogger::requestId(),
        ),
    ));
}

/** @param list<array<string, mixed>> $results */
function webhook_concurrency_total(array $results, string $field): int
{
    return array_sum(array_map(
        static fn (array $row): int => (int) ($row['result'][$field] ?? 0),
        $results,
    ));
}

$workers = [];
$claimGateLocked = false;
$claimGateInstalled = false;
try {
    (new SystemRequirements())->assertReady();
    $pdo = Database::connection();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'pgsql') {
        webhook_concurrency_assert(
            (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()")->fetchColumn() === 0,
            'PostgreSQL webhook concurrency contract requires a fresh dedicated database.',
        );
    }
    Database::migrate();
    (new Installer())->installHosted([
        'college_name' => 'Webhook Concurrency College',
        'timezone' => 'UTC',
        'admin_name' => 'Webhook Concurrency Administrator',
        'admin_email' => 'webhook-concurrency@example.test',
        'admin_password' => 'webhook-concurrency-password-123',
        'seed_demo' => '0',
    ], 'tenant_' . str_repeat('c', 32), test_authorized_setup_recovery_authority());
    $pdo = Database::connection();

    putenv('CPE_WEBHOOK_ENDPOINT_CONCURRENCY=1');
    putenv('CPE_WEBHOOK_INSTITUTION_CONCURRENCY=2');
    $capSubscription = webhook_concurrency_subscription($pdo, 'Concurrent cap receiver');
    for ($sequence = 0; $sequence < 50; $sequence++) {
        webhook_concurrency_dispatch($pdo, $sequence);
    }

    if ($driver === 'pgsql') {
        $pdo->exec(
            "CREATE FUNCTION cpe_webhook_claim_test_gate() RETURNS trigger LANGUAGE plpgsql AS $$
             BEGIN
                 IF NEW.status = 'processing' AND OLD.status <> 'processing' THEN
                     PERFORM pg_advisory_xact_lock(1900000001, 5001);
                 END IF;
                 RETURN NEW;
             END;
             $$",
        );
        $pdo->exec(
            'CREATE TRIGGER webhook_claim_test_gate BEFORE UPDATE OF status ON webhook_deliveries
             FOR EACH ROW EXECUTE FUNCTION cpe_webhook_claim_test_gate()',
        );
        $claimGateInstalled = true;
        $pdo->query('SELECT pg_advisory_lock(1900000001, 5001)')->fetchColumn();
        $claimGateLocked = true;
    }

    $capStart = $testRoot . '/cap-start';
    $capRelease = $testRoot . '/cap-send-release';
    for ($index = 0; $index < 2; $index++) {
        $workers[] = webhook_concurrency_spawn(
            'cap-' . $index,
            'cap',
            $testRoot . '/cap-ready-' . $index,
            $capStart,
            $testRoot . '/cap-send-ready-' . $index,
            $capRelease,
            '',
            '',
        );
    }
    webhook_concurrency_wait(
        static fn (): bool => count(glob($testRoot . '/cap-ready-*') ?: []) === 2,
        'cap worker readiness',
    );
    webhook_concurrency_write($capStart);
    if ($driver === 'pgsql') {
        webhook_concurrency_wait(
            static fn (): bool => (int) Database::connection()->query(
                "SELECT COUNT(*) FROM pg_catalog.pg_locks
                 WHERE locktype = 'advisory' AND classid = 1900000001::oid
                   AND objid = 5001::oid AND granted = false",
            )->fetchColumn() >= 1,
            'PostgreSQL claim gate',
        );
        usleep(250000);
        $advisoryWaiters = (int) $pdo->query(
            "SELECT COUNT(*) FROM pg_catalog.pg_locks
             WHERE locktype = 'advisory' AND classid = 1900000001::oid
               AND objid = 5001::oid AND granted = false",
        )->fetchColumn();
        webhook_concurrency_assert(
            $advisoryWaiters === 1,
            'More than one PostgreSQL claim transaction passed global cap coordination.',
        );
        webhook_concurrency_assert(
            (bool) $pdo->query('SELECT pg_advisory_unlock(1900000001, 5001)')->fetchColumn(),
            'Could not release PostgreSQL claim gate.',
        );
        $claimGateLocked = false;
    }
    webhook_concurrency_wait(
        static fn (): bool => count(glob($testRoot . '/cap-send-ready-*') ?: []) >= 1,
        'blocked cap delivery',
    );
    usleep(250000);
    $processing = $pdo->prepare(
        "SELECT COUNT(*) FROM webhook_deliveries delivery
         JOIN webhook_subscriptions subscription ON subscription.id = delivery.subscription_id
         WHERE subscription.public_id = ? AND delivery.status = 'processing'",
    );
    $processing->execute([$capSubscription]);
    $processingCount = (int) $processing->fetchColumn();
    $processing->closeCursor();
    webhook_concurrency_assert(
        $processingCount === 1,
        'Concurrent workers exceeded the global endpoint claim cap.',
    );
    webhook_concurrency_write($capRelease);
    $capResults = webhook_concurrency_collect($workers);
    webhook_concurrency_assert(
        webhook_concurrency_total($capResults, 'claimed') === 1,
        'Two workers committed more than one endpoint-capped delivery claim.',
    );
    if ($claimGateInstalled) {
        $pdo->exec('DROP TRIGGER webhook_claim_test_gate ON webhook_deliveries');
        $pdo->exec('DROP FUNCTION cpe_webhook_claim_test_gate()');
        $claimGateInstalled = false;
    }

    $service = new WebhookSubscriptionService(
        $pdo,
        new WebhookConcurrencyValidationTransport(),
        WebhookSecretCipher::fromEnvironment(),
    );
    $service->disable($capSubscription, 1);

    // Prove the institution cap independently: each endpoint has spare
    // capacity, but two subscriptions share one institution-wide slot.
    putenv('CPE_WEBHOOK_ENDPOINT_CONCURRENCY=2');
    putenv('CPE_WEBHOOK_INSTITUTION_CONCURRENCY=1');
    $institutionCapSubscriptions = [
        webhook_concurrency_subscription($pdo, 'Institution cap receiver A'),
        webhook_concurrency_subscription($pdo, 'Institution cap receiver B'),
    ];
    webhook_concurrency_dispatch($pdo, 50);

    $institutionStart = $testRoot . '/institution-start';
    $institutionRelease = $testRoot . '/institution-send-release';
    for ($index = 0; $index < 2; $index++) {
        $workers[] = webhook_concurrency_spawn(
            'institution-' . $index,
            'cap',
            $testRoot . '/institution-ready-' . $index,
            $institutionStart,
            $testRoot . '/institution-send-ready-' . $index,
            $institutionRelease,
            '',
            '',
        );
    }
    webhook_concurrency_wait(
        static fn (): bool => count(glob($testRoot . '/institution-ready-*') ?: []) === 2,
        'institution cap worker readiness',
    );
    webhook_concurrency_write($institutionStart);
    webhook_concurrency_wait(
        static fn (): bool => count(glob($testRoot . '/institution-send-ready-*') ?: []) >= 1,
        'institution-capped delivery',
    );
    usleep(250000);
    $institutionProcessing = $pdo->prepare(
        "SELECT COUNT(*) FROM webhook_deliveries delivery
         JOIN webhook_subscriptions subscription ON subscription.id = delivery.subscription_id
         WHERE subscription.public_id IN (?, ?) AND delivery.status = 'processing'",
    );
    $institutionProcessing->execute($institutionCapSubscriptions);
    webhook_concurrency_assert(
        (int) $institutionProcessing->fetchColumn() === 1,
        'Concurrent workers exceeded the global institution claim cap across endpoints.',
    );
    $institutionProcessing->closeCursor();
    webhook_concurrency_write($institutionRelease);
    $institutionResults = webhook_concurrency_collect($workers);
    webhook_concurrency_assert(
        webhook_concurrency_total($institutionResults, 'claimed') === 1,
        'Two workers committed more than one institution-capped delivery claim across endpoints.',
    );
    foreach ($institutionCapSubscriptions as $subscriptionPublicId) {
        $service->disable($subscriptionPublicId, 1);
    }

    putenv('CPE_WEBHOOK_ENDPOINT_CONCURRENCY=2');
    putenv('CPE_WEBHOOK_INSTITUTION_CONCURRENCY=2');
    $circuitSubscription = webhook_concurrency_subscription($pdo, 'Concurrent circuit receiver');
    webhook_concurrency_dispatch($pdo, 100);
    webhook_concurrency_dispatch($pdo, 101);
    $seedFailure = $pdo->prepare('UPDATE webhook_subscriptions SET consecutive_failures = 1 WHERE public_id = ?');
    $seedFailure->execute([$circuitSubscription]);

    $failureStart = $testRoot . '/failure-start';
    $failureSendRelease = $testRoot . '/failure-send-release';
    $failureRelease = $testRoot . '/failure-state-release';
    for ($index = 0; $index < 2; $index++) {
        $workers[] = webhook_concurrency_spawn(
            'failure-' . $index,
            'failure',
            $testRoot . '/failure-ready-' . $index,
            $failureStart,
            $testRoot . '/failure-send-ready-' . $index,
            $failureSendRelease,
            $testRoot . '/failure-state-ready-' . $index,
            $failureRelease,
        );
    }
    webhook_concurrency_wait(
        static fn (): bool => count(glob($testRoot . '/failure-ready-*') ?: []) === 2,
        'failure worker readiness',
    );
    webhook_concurrency_write($failureStart);
    webhook_concurrency_wait(
        static fn (): bool => count(glob($testRoot . '/failure-send-ready-*') ?: []) === 2,
        'parallel claimed deliveries',
    );
    webhook_concurrency_wait(
        static function () use ($pdo, $circuitSubscription): bool {
            $query = $pdo->prepare(
                "SELECT COUNT(*) FROM webhook_deliveries delivery
                 JOIN webhook_subscriptions subscription ON subscription.id = delivery.subscription_id
                 WHERE subscription.public_id = ? AND delivery.status = 'processing'",
            );
            $query->execute([$circuitSubscription]);
            $count = (int) $query->fetchColumn();
            $query->closeCursor();
            return $count === 2;
        },
        'parallel claimed delivery state',
    );
    webhook_concurrency_write($failureSendRelease);
    webhook_concurrency_wait(
        static fn (): bool => count(glob($testRoot . '/failure-state-ready-*') ?: []) >= 1,
        'locked failure state',
    );
    usleep(250000);
    webhook_concurrency_write($failureRelease);
    $failureResults = webhook_concurrency_collect($workers);
    webhook_concurrency_assert(
        webhook_concurrency_total($failureResults, 'claimed') === 2
            && webhook_concurrency_total($failureResults, 'retrying') === 2,
        'Parallel failure workers did not each complete one retryable delivery.',
    );
    $circuit = $pdo->prepare(
        'SELECT lifecycle_state, consecutive_failures, circuit_open_until
         FROM webhook_subscriptions WHERE public_id = ?',
    );
    $circuit->execute([$circuitSubscription]);
    $circuitState = $circuit->fetch(PDO::FETCH_ASSOC);
    webhook_concurrency_assert(
        is_array($circuitState)
            && (string) $circuitState['lifecycle_state'] === 'degraded'
            && (int) $circuitState['consecutive_failures'] === 3
            && (string) $circuitState['circuit_open_until'] > cpe_now(),
        'Parallel failures lost an increment or failed to open the endpoint circuit.',
    );

    echo 'PASS webhook delivery concurrency contract (' . $driver . ")\n";
} finally {
    webhook_concurrency_stop($workers);
    if (isset($pdo) && $pdo instanceof PDO && $claimGateLocked) {
        try {
            $pdo->query('SELECT pg_advisory_unlock(1900000001, 5001)')->fetchColumn();
        } catch (Throwable) {
        }
    }
    if (isset($pdo) && $pdo instanceof PDO && $claimGateInstalled) {
        try {
            $pdo->exec('DROP TRIGGER IF EXISTS webhook_claim_test_gate ON webhook_deliveries');
            $pdo->exec('DROP FUNCTION IF EXISTS cpe_webhook_claim_test_gate()');
        } catch (Throwable) {
        }
    }
    Database::reset();
    putenv('CPE_WEBHOOK_ENCRYPTION_KEYS');
    putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION');
    putenv('CPE_WEBHOOK_MAX_ATTEMPTS');
    putenv('CPE_WEBHOOK_ENDPOINT_CONCURRENCY');
    putenv('CPE_WEBHOOK_INSTITUTION_CONCURRENCY');
    putenv('CPE_LOG_PATH');
    webhook_concurrency_remove_tree($testRoot);
}
