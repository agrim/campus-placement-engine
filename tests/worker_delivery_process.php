<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Events\DomainEventOutboxWorker;
use App\Domain\NotificationDeliveryService;
use App\Support\Database;

$kind = (string) ($argv[1] ?? '');
$barrier = (string) ($argv[2] ?? '');
if (!in_array($kind, ['domain-event', 'notification'], true) || $barrier === '') {
    fwrite(STDERR, "Invalid worker delivery contract arguments.\n");
    exit(2);
}

$deadline = hrtime(true) + 20_000_000_000;
while (!is_file($barrier)) {
    if (hrtime(true) >= $deadline) {
        fwrite(STDERR, "Worker delivery contract barrier timed out.\n");
        exit(3);
    }
    usleep(1_000);
}

try {
    $result = $kind === 'notification'
        ? (new NotificationDeliveryService(Database::connection()))->deliverPending('file', 1, false)
        : (new DomainEventOutboxWorker(Database::connection()))->work(1);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $failure) {
    fwrite(STDERR, "Worker delivery contract process failed.\n");
    exit(1);
}
