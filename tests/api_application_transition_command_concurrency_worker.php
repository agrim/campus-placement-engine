<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Api\Commands\ApiApplicationTransitionCommandService;
use App\Api\Commands\ApiApplicationTransitionInput;
use App\Api\Commands\ApiCommandHasher;
use App\Api\Commands\ApiCommandIdempotencyStore;
use App\Api\Commands\ApiCommandConflict;
use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiPrincipal;
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
$mode = (string) (getenv('CPE_API_COMMAND_TEST_MODE') ?: 'foundation');
if ($ready === '' || $start === '' || $accountId === false) {
    fwrite(STDERR, "Invalid API command concurrency worker fixture.\n");
    exit(2);
}

try {
    $pdo = Database::connection();
    $keyring = ApiKeyring::fromEnvironment();
    if ($mode === 'transition') {
        $tokenId = filter_var(getenv('CPE_API_COMMAND_TEST_TOKEN_ID'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $institutionId = filter_var(getenv('CPE_API_COMMAND_TEST_INSTITUTION_ID'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $tokenLookupId = (string) (getenv('CPE_API_COMMAND_TEST_TOKEN_LOOKUP_ID') ?: '');
        $transitionKey = (string) (getenv('CPE_API_COMMAND_TEST_TRANSITION_KEY') ?: '');
        $targetStatus = (string) (getenv('CPE_API_COMMAND_TEST_TARGET_STATUS') ?: '');
        $ifMatch = (string) (getenv('CPE_API_COMMAND_TEST_IF_MATCH') ?: '');
        $requestId = (string) (getenv('CPE_API_COMMAND_TEST_REQUEST_ID') ?: '');
        if ($tokenId === false
            || $institutionId === false
            || preg_match('/\A[a-f0-9]{32}\z/D', $tokenLookupId) !== 1
            || preg_match('/\Areq_[a-f0-9]{32}\z/D', $requestId) !== 1) {
            throw new RuntimeException('Invalid full transition race fixture.');
        }
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
        $outcome = (new ApiApplicationTransitionCommandService($pdo, $keyring))->execute(
            new ApiPrincipal(
                (int) $institutionId,
                $institutionPublicId,
                (int) $accountId,
                $accountPublicId,
                (int) $tokenId,
                $tokenLookupId,
                ['applications.read', 'applications.transition'],
            ),
            $applicationPublicId,
            new ApiApplicationTransitionInput(
                $transitionKey,
                $targetStatus,
                'concurrent governed transition',
                $clearKey,
                $ifMatch,
            ),
            $requestId,
        );
        echo json_encode([
            'status' => 'ok',
            'outcome' => $outcome->isReplay() ? 'replay' : 'new',
            'record_id' => 0,
            'response_json' => $outcome->responseJson(),
            'response_etag' => $outcome->etag(),
            'response_request_id' => $outcome->responseRequestId(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        Database::reset();
        exit(0);
    }
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
