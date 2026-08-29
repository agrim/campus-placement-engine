<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Integrations\Webhooks\WebhookDeliveryWorker;
use App\Integrations\Webhooks\WebhookHttpResult;
use App\Integrations\Webhooks\WebhookHttpTransport;
use App\Integrations\Webhooks\WebhookSecretCipher;
use App\Integrations\Webhooks\WebhookTransportException;
use App\Support\Database;

function webhook_concurrency_worker_wait(string $path, string $label): void
{
    $deadline = hrtime(true) + 30_000_000_000;
    while (!is_file($path)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for webhook ' . $label . '.');
        }
        usleep(1000);
    }
}

final class WebhookConcurrencyWorkerTransport implements WebhookHttpTransport
{
    public function __construct(
        private readonly string $mode,
        private readonly string $sendReady,
        private readonly string $sendRelease,
    ) {
    }

    public function send(string $endpointUrl, string $body, array $headers, bool $allowPrivateNetwork): WebhookHttpResult
    {
        if (!in_array($this->mode, ['cap', 'failure'], true)) {
            throw new RuntimeException('Webhook concurrency worker mode is invalid.');
        }
        if ($this->sendReady === '' || $this->sendRelease === '') {
            throw new RuntimeException('Webhook transport coordination is incomplete.');
        }
        if (file_put_contents($this->sendReady, "ready\n") === false) {
            throw new RuntimeException('Could not publish webhook send readiness.');
        }
        webhook_concurrency_worker_wait($this->sendRelease, 'send release');
        if ($this->mode === 'failure') {
            throw new WebhookTransportException(WebhookTransportException::TIMEOUT, true);
        }
        return new WebhookHttpResult(204);
    }
}

$participant = (string) getenv('CPE_WEBHOOK_TEST_PARTICIPANT');
$mode = (string) getenv('CPE_WEBHOOK_TEST_MODE');
$ready = (string) getenv('CPE_WEBHOOK_TEST_READY');
$start = (string) getenv('CPE_WEBHOOK_TEST_START');
$sendReady = (string) getenv('CPE_WEBHOOK_TEST_SEND_READY');
$sendRelease = (string) getenv('CPE_WEBHOOK_TEST_SEND_RELEASE');
$failureReady = (string) getenv('CPE_WEBHOOK_TEST_FAILURE_READY');
$failureRelease = (string) getenv('CPE_WEBHOOK_TEST_FAILURE_RELEASE');
$limit = max(1, min(10, (int) (getenv('CPE_WEBHOOK_TEST_LIMIT') ?: 1)));

try {
    if ($participant === '' || $ready === '' || $start === '' || !in_array($mode, ['cap', 'failure'], true)) {
        throw new RuntimeException('Webhook concurrency worker coordination is incomplete.');
    }
    if (file_put_contents($ready, "ready\n") === false) {
        throw new RuntimeException('Could not publish webhook worker readiness.');
    }
    webhook_concurrency_worker_wait($start, 'worker start');

    $failureObserver = null;
    if ($mode === 'failure') {
        if ($failureReady === '' || $failureRelease === '') {
            throw new RuntimeException('Webhook failure coordination is incomplete.');
        }
        $failureObserver = static function (int $_subscriptionId, int $_count) use ($failureReady, $failureRelease): void {
            if (file_put_contents($failureReady, "ready\n") === false) {
                throw new RuntimeException('Could not publish webhook failure-state readiness.');
            }
            webhook_concurrency_worker_wait($failureRelease, 'failure-state release');
        };
    }

    $worker = new WebhookDeliveryWorker(
        Database::connection(),
        new WebhookConcurrencyWorkerTransport($mode, $sendReady, $sendRelease),
        WebhookSecretCipher::fromEnvironment(),
        null,
        static fn (): int => 0,
        $failureObserver,
    );
    $result = $worker->work($limit);
    echo json_encode([
        'status' => 'ok',
        'participant' => $participant,
        'result' => $result,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (Throwable $failure) {
    echo json_encode([
        'status' => 'error',
        'participant' => $participant,
        'error' => $failure->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    exit(2);
} finally {
    Database::reset();
}
