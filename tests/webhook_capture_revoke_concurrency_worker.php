<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Events\DomainEvent;
use App\Core\Events\PublicEventProjection;
use App\Integrations\Webhooks\WebhookDeliveryWorker;
use App\Integrations\Webhooks\WebhookHttpResult;
use App\Integrations\Webhooks\WebhookHttpTransport;
use App\Integrations\Webhooks\WebhookSubscriptionService;
use App\Support\Database;
use App\Support\StructuredLogger;

function webhook_capture_worker_wait(string $path): void
{
    $deadline = hrtime(true) + 30_000_000_000;
    while (!is_file($path)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for webhook capture/revoke start.');
        }
        usleep(1000);
    }
}

final class WebhookCaptureExpiryNoNetworkTransport implements WebhookHttpTransport
{
    public int $calls = 0;

    public function send(string $endpointUrl, string $body, array $headers, bool $allowPrivateNetwork): WebhookHttpResult
    {
        $this->calls++;
        return new WebhookHttpResult(204);
    }
}

$mode = (string) getenv('CPE_WEBHOOK_CAPTURE_TEST_MODE');
$ready = (string) getenv('CPE_WEBHOOK_CAPTURE_TEST_READY');
$start = (string) getenv('CPE_WEBHOOK_CAPTURE_TEST_START');
$done = (string) getenv('CPE_WEBHOOK_CAPTURE_TEST_DONE');
$subscriptionPublicId = (string) getenv('CPE_WEBHOOK_CAPTURE_TEST_SUBSCRIPTION');
$aggregateId = (string) getenv('CPE_WEBHOOK_CAPTURE_TEST_AGGREGATE');
$pdo = null;

try {
    if (!in_array($mode, ['capture', 'revoke', 'expire'], true)
        || $ready === '' || $start === '' || $done === '') {
        throw new RuntimeException('Webhook capture/revoke/expiry worker coordination is incomplete.');
    }
    $pdo = Database::connection();
    if (file_put_contents($ready, "ready\n") === false) {
        throw new RuntimeException('Could not publish webhook capture/revoke worker readiness.');
    }
    webhook_capture_worker_wait($start);

    if ($mode === 'expire') {
        if (strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql'
            && (string) getenv('CPE_WEBHOOK_CAPTURE_TEST_REVERSE_SCAN') === '1') {
            $pdo->exec('SET enable_indexscan = off');
            $pdo->exec('SET enable_bitmapscan = off');
        }
        $transport = new WebhookCaptureExpiryNoNetworkTransport();
        $result = (new WebhookDeliveryWorker($pdo, $transport))->work(1);
        if (file_put_contents($done, "done\n") === false) {
            throw new RuntimeException('Could not publish webhook expiry completion.');
        }
        echo json_encode([
            'status' => 'ok',
            'mode' => $mode,
            'claimed' => (int) $result['claimed'],
            'transport_calls' => $transport->calls,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($mode === 'revoke') {
        if ($subscriptionPublicId === '') {
            throw new RuntimeException('Webhook revoke worker target is missing.');
        }
        (new WebhookSubscriptionService($pdo))->revoke($subscriptionPublicId, 1);
        if (file_put_contents($done, "done\n") === false) {
            throw new RuntimeException('Could not publish webhook revoke completion.');
        }
        echo json_encode(['status' => 'ok', 'mode' => $mode], JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }

    if (preg_match('/^application_[a-f0-9]{32}$/D', $aggregateId) !== 1) {
        throw new RuntimeException('Webhook capture worker aggregate is invalid.');
    }
    $instanceId = (string) $pdo->query(
        "SELECT public_id FROM institutions WHERE slug = 'default'",
    )->fetchColumn();
    $eventId = cpe_context()->events()->dispatch(new DomainEvent(
        'placement.application.transitioned',
        'placement_application',
        $aggregateId,
        'placement',
        ['private_application_id' => 1],
        cpe_now(),
        PublicEventProjection::applicationStatusChanged(
            $instanceId,
            $aggregateId,
            2,
            'idle',
            'scheduled',
            StructuredLogger::requestId(),
        ),
    ));
    if (file_put_contents($done, "done\n") === false) {
        throw new RuntimeException('Could not publish webhook capture completion.');
    }
    echo json_encode(
        ['status' => 'ok', 'mode' => $mode, 'event_id' => $eventId],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ) . "\n";
    exit(0);
} catch (Throwable $failure) {
    if ($done !== '') {
        file_put_contents($done, "error\n");
    }
    echo json_encode(
        ['status' => 'error', 'mode' => $mode, 'error' => $failure->getMessage()],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ) . "\n";
    exit(2);
} finally {
    Database::reset();
}
