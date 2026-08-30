<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Integrations\Webhooks\WebhookSubscriptionService;
use App\Support\Database;

function webhook_revoke_worker_wait(string $path): void
{
    $deadline = hrtime(true) + 30_000_000_000;
    while (!is_file($path)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for webhook revoke release.');
        }
        usleep(1000);
    }
}

$subscriptionPublicId = (string) getenv('CPE_WEBHOOK_REVOKE_TEST_SUBSCRIPTION');
$ready = (string) getenv('CPE_WEBHOOK_REVOKE_TEST_READY');
$release = (string) getenv('CPE_WEBHOOK_REVOKE_TEST_RELEASE');
$pdo = null;

try {
    if ($subscriptionPublicId === '' || $ready === '' || $release === '') {
        throw new RuntimeException('Webhook revoke concurrency coordination is incomplete.');
    }
    $pdo = Database::connection();
    if (strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'pgsql') {
        throw new RuntimeException('Webhook revoke lock worker requires PostgreSQL.');
    }
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT id FROM webhook_subscriptions WHERE public_id = ? FOR UPDATE');
    $lock->execute([$subscriptionPublicId]);
    if ($lock->fetchColumn() === false) {
        throw new RuntimeException('Webhook revoke target is unavailable.');
    }
    if (file_put_contents($ready, "ready\n") === false) {
        throw new RuntimeException('Could not publish webhook revoke lock readiness.');
    }
    webhook_revoke_worker_wait($release);
    (new WebhookSubscriptionService($pdo))->revoke($subscriptionPublicId, 1);
    $pdo->commit();
    echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
} catch (Throwable $failure) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(
        ['status' => 'error', 'error' => $failure->getMessage()],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ) . "\n";
    exit(2);
} finally {
    Database::reset();
}
