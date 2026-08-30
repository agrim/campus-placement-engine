<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Api\Commands\ApiCommandHasher;
use App\Api\Commands\ApiCommandIdempotencyStore;
use App\Api\Commands\ApiCommandConflict;
use App\Api\Security\ApiKeyring;
use App\Core\Persistence\WriteTransaction;
use App\Support\Database;

$ready = (string) (getenv('CPE_API_COMMAND_TEST_READY') ?: '');
$start = (string) (getenv('CPE_API_COMMAND_TEST_START') ?: '');
$accountId = filter_var(getenv('CPE_API_COMMAND_TEST_ACCOUNT_ID'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$accountPublicId = (string) (getenv('CPE_API_COMMAND_TEST_ACCOUNT_PUBLIC_ID') ?: '');
$institutionPublicId = (string) (getenv('CPE_API_COMMAND_TEST_INSTITUTION_PUBLIC_ID') ?: '');
$applicationPublicId = (string) (getenv('CPE_API_COMMAND_TEST_APPLICATION_PUBLIC_ID') ?: '');
$clearKey = (string) (getenv('CPE_API_COMMAND_TEST_KEY') ?: '');
if ($ready === '' || $start === '' || $accountId === false) {
    fwrite(STDERR, "Invalid API command concurrency worker fixture.\n");
    exit(2);
}

try {
    $pdo = Database::connection();
    $keyring = ApiKeyring::fromEnvironment();
    $fingerprint = (new ApiCommandHasher($keyring))->fingerprintApplicationTransition(
        $clearKey,
        $institutionPublicId,
        $accountPublicId,
        $applicationPublicId,
        [
            'expected_etag' => '"' . str_repeat('3', 64) . '"',
            'to_status' => 'applied',
            'transition_key' => 'advance',
        ],
    );
    if (file_put_contents($ready, "ready\n") === false) {
        throw new RuntimeException('Could not publish worker readiness.');
    }
    $deadline = hrtime(true) + 30_000_000_000;
    while (!is_file($start)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for command race start.');
        }
        usleep(1_000);
    }
    $store = new ApiCommandIdempotencyStore($pdo, $keyring);
    $result = WriteTransaction::run($pdo, static function () use ($store, $accountId, $fingerprint) {
        $reservation = $store->reserve((int) $accountId, $fingerprint);
        if ($reservation->isReplay()) {
            return ['outcome' => 'replay', 'reservation' => $reservation];
        }
        $completed = $store->complete(
            (int) $accountId,
            $fingerprint,
            $reservation,
            [
                'data' => ['status' => 'applied'],
                'meta' => ['request_id' => 'req_' . str_repeat('6', 32)],
            ],
            200,
            '"' . str_repeat('6', 64) . '"',
        );
        return ['outcome' => 'new', 'reservation' => $completed];
    });
    echo json_encode([
        'status' => 'ok',
        'outcome' => $result['outcome'],
        'record_id' => $result['reservation']->recordId(),
        'response_json' => $result['reservation']->responseJson(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (ApiCommandConflict $failure) {
    if ($failure->reason() !== ApiCommandConflict::ACCOUNT) {
        fwrite(STDERR, 'API command concurrency worker returned an unexpected conflict: ' . $failure->reason() . "\n");
        exit(1);
    }
    echo json_encode([
        'status' => 'ok',
        'outcome' => 'account_conflict',
        'record_id' => 0,
        'response_json' => null,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $failure) {
    fwrite(STDERR, 'API command concurrency worker failed: ' . get_class($failure) . ': ' . $failure->getMessage() . "\n");
    exit(1);
} finally {
    Database::reset();
}
