<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Http\UserVisibleException;
use App\Core\Modules\ModuleLifecycleService;
use App\Modules\Placement\Application\PlacementService;
use App\Support\Database;

$operation = (string) ($argv[1] ?? '');
$barrier = (string) ($argv[2] ?? '');
$workerId = (string) ($argv[3] ?? '');
$payload = json_decode((string) ($argv[4] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
if ($operation === '' || $barrier === '' || $workerId === '' || !is_array($payload)) {
    throw new RuntimeException('Mutation concurrency worker arguments are invalid.');
}

file_put_contents($barrier . '/ready-' . $workerId, 'ready');
$deadline = microtime(true) + 15;
while (!is_file($barrier . '/start')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Mutation concurrency worker timed out waiting for start.');
    }
    usleep(10000);
}

try {
    if ($operation === 'board') {
        $result = (new PlacementService(Database::connection()))->applyBoardMove(
            (int) $payload['application_id'],
            1,
            'admin',
            (string) ($payload['to_status'] ?? ''),
            '',
            (string) ($payload['note'] ?? ''),
            (string) ($payload['expected_status'] ?? ''),
            (string) ($payload['key'] ?? '')
        );
        echo json_encode(['status' => 'ok', 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
    } elseif ($operation === 'module-enable') {
        (new ModuleLifecycleService(Database::connection()))->enable((string) $payload['module_key'], 1);
        echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR) . "\n";
    } elseif ($operation === 'module-disable') {
        (new ModuleLifecycleService(Database::connection()))->disable((string) $payload['module_key'], 1);
        echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR) . "\n";
    } else {
        throw new RuntimeException('Unknown mutation concurrency worker operation.');
    }
} catch (UserVisibleException $e) {
    echo json_encode([
        'status' => 'error',
        'code' => $e->publicCode(),
        'message' => $e->publicMessage(),
    ], JSON_THROW_ON_ERROR) . "\n";
}
