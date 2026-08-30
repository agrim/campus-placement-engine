<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Api\Security\ApiServiceAccountService;
use App\Support\Database;

function api_rotation_worker_wait(string $path): void
{
    $deadline = hrtime(true) + 30_000_000_000;
    while (!is_file($path)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for API rotation start.');
        }
        usleep(1_000);
    }
}

$accountId = (string) getenv('CPE_API_ROTATION_TEST_ACCOUNT');
$ready = (string) getenv('CPE_API_ROTATION_TEST_READY');
$start = (string) getenv('CPE_API_ROTATION_TEST_START');

try {
    if ($accountId === '' || $ready === '' || $start === '') {
        throw new RuntimeException('API rotation concurrency coordination is incomplete.');
    }
    Database::connection();
    if (file_put_contents($ready, "ready\n") === false) {
        throw new RuntimeException('Could not publish API rotation worker readiness.');
    }
    api_rotation_worker_wait($start);
    $result = (new ApiServiceAccountService())->rotateToken($accountId, 1);
    echo json_encode([
        'status' => 'ok',
        'lookup_id' => $result['token_lookup_id'],
        'token' => $result['token'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (Throwable $failure) {
    echo json_encode(
        ['status' => 'error', 'error' => $failure->getMessage()],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ) . "\n";
    exit(2);
} finally {
    Database::reset();
}
