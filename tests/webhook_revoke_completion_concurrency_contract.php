<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/cpe-webhook-revoke-completion-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create webhook revoke concurrency fixture directory.');
}
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DB_PATH=' . $testRoot . '/contract.sqlite');
}
$keyMaterial = rtrim(strtr(base64_encode(str_repeat("\x52", 32)), '+/', '-_'), '=');
putenv('CPE_WEBHOOK_ENCRYPTION_KEYS=revoke-v1=' . $keyMaterial);
putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION=revoke-v1');
putenv('CPE_WEBHOOK_ENDPOINT_CONCURRENCY=1');
putenv('CPE_WEBHOOK_INSTITUTION_CONCURRENCY=1');
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

function webhook_revoke_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function webhook_revoke_wait(callable $condition, string $label): void
{
    $deadline = hrtime(true) + 30_000_000_000;
    while (!$condition()) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for webhook ' . $label . '.');
        }
        usleep(5000);
    }
}

function webhook_revoke_write(string $path): void
{
    webhook_revoke_assert(file_put_contents($path, "release\n") !== false, 'Could not release webhook revoke barrier.');
}

/** @return array{process: resource, stdout: resource, stderr: resource} */
function webhook_revoke_spawn(string $script, array $extraEnvironment): array
{
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    foreach ($extraEnvironment as $name => $value) {
        $environment[$name] = $value;
    }
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/' . $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start webhook revoke concurrency worker.');
    }
    fclose($pipes[0]);
    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
}

/** @param array{process: resource, stdout: resource, stderr: resource} $worker */
function webhook_revoke_collect(array $worker): array
{
    webhook_revoke_wait(
        static function () use ($worker): bool {
            $status = proc_get_status($worker['process']);
            return is_array($status) && !($status['running'] ?? false);
        },
        'worker completion',
    );
    $status = proc_get_status($worker['process']);
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
    webhook_revoke_assert(
        $exitCode === 0 && is_array($decoded) && ($decoded['status'] ?? '') === 'ok',
        'Webhook revoke concurrency worker failed: ' . $stdout . ' ' . $stderr,
    );
    return $decoded;
}

/** @param list<array{process: resource, stdout: resource, stderr: resource}> $workers */
function webhook_revoke_stop(array &$workers): void
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

function webhook_revoke_remove_tree(string $path): void
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

final class WebhookRevokeValidationTransport implements WebhookHttpTransport
{
    public function send(string $endpointUrl, string $body, array $headers, bool $allowPrivateNetwork): WebhookHttpResult
    {
        return new WebhookHttpResult(204);
    }
}

/** @return array{0: int, 1: int} */
function webhook_revoke_wait_state(PDO $pdo): array
{
    $query = $pdo->query(
        "SELECT
            SUM(CASE WHEN lock.locktype = 'advisory'
                      AND lock.classid = 1900000002::oid AND lock.objid = 5002::oid
                     THEN 1 ELSE 0 END) AS advisory_waiters,
            SUM(CASE WHEN lock.locktype = 'transactionid' THEN 1 ELSE 0 END) AS transaction_waiters
         FROM pg_catalog.pg_locks lock
         JOIN pg_catalog.pg_stat_activity activity ON activity.pid = lock.pid
         WHERE activity.datname = current_database() AND lock.granted = false",
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    return [(int) ($query['advisory_waiters'] ?? 0), (int) ($query['transaction_waiters'] ?? 0)];
}

$workers = [];
$completionRelease = $testRoot . '/completion-send-release';
$revokeRelease = $testRoot . '/revoke-release';
$advisoryLocked = false;
$gateInstalled = false;
try {
    (new SystemRequirements())->assertReady();
    $pdo = Database::connection();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'pgsql') {
        webhook_revoke_assert(
            (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()")->fetchColumn() === 0,
            'PostgreSQL webhook revoke concurrency contract requires a fresh dedicated database.',
        );
    }
    Database::migrate();
    (new Installer())->installHosted([
        'college_name' => 'Webhook Revoke Concurrency College',
        'timezone' => 'UTC',
        'admin_name' => 'Webhook Revoke Administrator',
        'admin_email' => 'webhook-revoke@example.test',
        'admin_password' => 'webhook-revoke-password-123',
        'seed_demo' => '0',
    ], 'tenant_' . str_repeat('d', 32), test_authorized_setup_recovery_authority());
    $pdo = Database::connection();
    $service = new WebhookSubscriptionService(
        $pdo,
        new WebhookRevokeValidationTransport(),
        WebhookSecretCipher::fromEnvironment(),
    );
    $created = $service->create('Revocation race receiver', 'https://revoke-race.example.test/hook', true, false, 1);
    $subscriptionPublicId = (string) $created['subscription_id'];
    $service->validate($subscriptionPublicId, 1);
    $service->activate($subscriptionPublicId, 1);

    $instance = (string) $pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $aggregate = 'application_' . str_repeat('d', 32);
    cpe_context()->events()->dispatch(new DomainEvent(
        'placement.application.transitioned',
        'placement_application',
        $aggregate,
        'placement',
        ['private_application_id' => 1],
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

    if ($driver === 'pgsql') {
        $pdo->exec(
            "CREATE FUNCTION cpe_webhook_completion_order_test_gate() RETURNS trigger LANGUAGE plpgsql AS $$
             BEGIN
                 IF OLD.status = 'processing' AND NEW.status IN ('succeeded', 'retrying', 'dead_lettered') THEN
                     PERFORM pg_advisory_xact_lock(1900000002, 5002);
                 END IF;
                 RETURN NEW;
             END;
             $$",
        );
        $pdo->exec(
            'CREATE TRIGGER webhook_completion_order_test_gate BEFORE UPDATE OF status ON webhook_deliveries
             FOR EACH ROW EXECUTE FUNCTION cpe_webhook_completion_order_test_gate()',
        );
        $gateInstalled = true;
        $pdo->query('SELECT pg_advisory_lock(1900000002, 5002)')->fetchColumn();
        $advisoryLocked = true;
    }

    $completionReady = $testRoot . '/completion-ready';
    $completionStart = $testRoot . '/completion-start';
    $completionSendReady = $testRoot . '/completion-send-ready';
    $completionWorker = webhook_revoke_spawn('webhook_delivery_concurrency_worker.php', [
        'CPE_WEBHOOK_TEST_PARTICIPANT' => 'completion',
        'CPE_WEBHOOK_TEST_MODE' => 'cap',
        'CPE_WEBHOOK_TEST_READY' => $completionReady,
        'CPE_WEBHOOK_TEST_START' => $completionStart,
        'CPE_WEBHOOK_TEST_SEND_READY' => $completionSendReady,
        'CPE_WEBHOOK_TEST_SEND_RELEASE' => $completionRelease,
        'CPE_WEBHOOK_TEST_FAILURE_READY' => '',
        'CPE_WEBHOOK_TEST_FAILURE_RELEASE' => '',
        'CPE_WEBHOOK_TEST_LIMIT' => '1',
    ]);
    $workers[] = $completionWorker;
    webhook_revoke_wait(static fn (): bool => is_file($completionReady), 'completion worker readiness');
    webhook_revoke_write($completionStart);
    webhook_revoke_wait(static fn (): bool => is_file($completionSendReady), 'claimed delivery send');

    $revokeResult = null;
    if ($driver === 'pgsql') {
        $revokeReady = $testRoot . '/revoke-lock-ready';
        $revokeWorker = webhook_revoke_spawn('webhook_revoke_completion_concurrency_worker.php', [
            'CPE_WEBHOOK_REVOKE_TEST_SUBSCRIPTION' => $subscriptionPublicId,
            'CPE_WEBHOOK_REVOKE_TEST_READY' => $revokeReady,
            'CPE_WEBHOOK_REVOKE_TEST_RELEASE' => $revokeRelease,
        ]);
        $workers[] = $revokeWorker;
        webhook_revoke_wait(static fn (): bool => is_file($revokeReady), 'subscription-first revoke lock');
        webhook_revoke_write($completionRelease);
        webhook_revoke_wait(
            static function () use ($pdo): bool {
                [$advisoryWaiters, $transactionWaiters] = webhook_revoke_wait_state($pdo);
                return $advisoryWaiters + $transactionWaiters > 0;
            },
            'completion lock-order evidence',
        );
        [$advisoryWaiters, $transactionWaiters] = webhook_revoke_wait_state($pdo);
        webhook_revoke_assert(
            $advisoryWaiters === 0 && $transactionWaiters >= 1,
            'Webhook completion reached the delivery update before acquiring the subscription lock.',
        );
        webhook_revoke_assert(
            (bool) $pdo->query('SELECT pg_advisory_unlock(1900000002, 5002)')->fetchColumn(),
            'Could not release PostgreSQL completion-order gate.',
        );
        $advisoryLocked = false;
        webhook_revoke_write($revokeRelease);
        $revokeResult = webhook_revoke_collect($revokeWorker);
        array_pop($workers);
    } else {
        // SQLite serializes writers with BEGIN IMMEDIATE. Revoke after the
        // external send but before completion to prove the portable fence.
        $service->revoke($subscriptionPublicId, 1);
        webhook_revoke_write($completionRelease);
    }

    $completionResult = webhook_revoke_collect($completionWorker);
    array_shift($workers);
    webhook_revoke_assert(
        (int) ($completionResult['result']['claimed'] ?? 0) === 1
            && (int) ($completionResult['result']['succeeded'] ?? 0) === 0
            && (int) ($completionResult['result']['claim_lost'] ?? 0) === 1,
        'Revocation did not fence the in-flight completion idempotently.',
    );
    if ($driver === 'pgsql') {
        webhook_revoke_assert(($revokeResult['status'] ?? '') === 'ok', 'Concurrent PostgreSQL revocation did not commit.');
    }

    $subscription = $pdo->prepare(
        'SELECT lifecycle_state, current_secret_ciphertext, current_secret_nonce, current_secret_tag, current_secret_key_version
         FROM webhook_subscriptions WHERE public_id = ?',
    );
    $subscription->execute([$subscriptionPublicId]);
    $subscriptionState = $subscription->fetch(PDO::FETCH_ASSOC);
    webhook_revoke_assert(
        is_array($subscriptionState)
            && (string) $subscriptionState['lifecycle_state'] === 'disabled'
            && $subscriptionState['current_secret_ciphertext'] === null
            && $subscriptionState['current_secret_nonce'] === null
            && $subscriptionState['current_secret_tag'] === null
            && $subscriptionState['current_secret_key_version'] === null,
        'Concurrent revocation did not disable the subscription and erase current secret metadata.',
    );
    $delivery = $pdo->prepare(
        'SELECT status, lock_token, locked_at, lease_generation, last_error_code
         FROM webhook_deliveries WHERE subscription_id = (SELECT id FROM webhook_subscriptions WHERE public_id = ?)',
    );
    $delivery->execute([$subscriptionPublicId]);
    $deliveryState = $delivery->fetch(PDO::FETCH_ASSOC);
    webhook_revoke_assert(
        is_array($deliveryState)
            && (string) $deliveryState['status'] === 'dead_lettered'
            && $deliveryState['lock_token'] === null
            && $deliveryState['locked_at'] === null
            && (int) $deliveryState['lease_generation'] >= 2
            && (string) $deliveryState['last_error_code'] === 'subscription_revoked',
        'Concurrent revocation did not preserve delivery fencing and terminal state.',
    );

    echo 'PASS webhook revoke/completion concurrency contract (' . $driver . ")\n";
} finally {
    if (!is_file($completionRelease)) {
        webhook_revoke_write($completionRelease);
    }
    if (!is_file($revokeRelease)) {
        webhook_revoke_write($revokeRelease);
    }
    webhook_revoke_stop($workers);
    if (isset($pdo) && $pdo instanceof PDO && $advisoryLocked) {
        try {
            $pdo->query('SELECT pg_advisory_unlock(1900000002, 5002)')->fetchColumn();
        } catch (Throwable) {
        }
    }
    if (isset($pdo) && $pdo instanceof PDO && $gateInstalled) {
        try {
            $pdo->exec('DROP TRIGGER IF EXISTS webhook_completion_order_test_gate ON webhook_deliveries');
            $pdo->exec('DROP FUNCTION IF EXISTS cpe_webhook_completion_order_test_gate()');
        } catch (Throwable) {
        }
    }
    Database::reset();
    putenv('CPE_WEBHOOK_ENCRYPTION_KEYS');
    putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION');
    putenv('CPE_WEBHOOK_ENDPOINT_CONCURRENCY');
    putenv('CPE_WEBHOOK_INSTITUTION_CONCURRENCY');
    putenv('CPE_LOG_PATH');
    webhook_revoke_remove_tree($testRoot);
}
