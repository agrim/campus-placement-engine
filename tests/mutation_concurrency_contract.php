<?php

declare(strict_types=1);

$temporarySqlite = null;
if (trim((string) (getenv('CPE_DATABASE_URL') ?: '')) === ''
    && !in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true)) {
    $temporarySqlite = sys_get_temp_dir() . '/cpe-mutation-concurrency-' . bin2hex(random_bytes(4)) . '.sqlite';
    putenv('CPE_DB_PATH=' . $temporarySqlite);
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/authorized_setup_recovery_fixture.php';

use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Support\Database;

function mutation_concurrency_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<array<string, mixed>> */
function mutation_concurrency_run(string $operation, array $payload): array
{
    $barrier = sys_get_temp_dir() . '/cpe-mutation-barrier-' . bin2hex(random_bytes(4));
    if (!mkdir($barrier, 0700, true) && !is_dir($barrier)) {
        throw new RuntimeException('Could not create mutation concurrency barrier.');
    }
    $workers = [];
    try {
        for ($index = 0; $index < 2; $index++) {
            $workerPayload = array_is_list($payload) && count($payload) === 2
                ? $payload[$index]
                : $payload;
            $command = [
                PHP_BINARY,
                __DIR__ . '/mutation_concurrency_worker.php',
                $operation,
                $barrier,
                (string) $index,
                json_encode($workerPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ];
            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start mutation concurrency worker.');
            }
            fclose($pipes[0]);
            $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }

        $deadline = microtime(true) + 15;
        while (count(glob($barrier . '/ready-*') ?: []) < 2) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Mutation concurrency workers did not become ready.');
            }
            usleep(10000);
        }
        file_put_contents($barrier . '/start', 'start');

        $results = [];
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($worker['stdout']);
            $stderr = stream_get_contents($worker['stderr']);
            fclose($worker['stdout']);
            fclose($worker['stderr']);
            $code = proc_close($worker['process']);
            if ($code !== 0) {
                throw new RuntimeException('Mutation concurrency worker failed: ' . trim((string) $stderr));
            }
            $decoded = json_decode(trim((string) $stdout), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('Mutation concurrency worker returned invalid output.');
            }
            $results[] = $decoded;
        }
        return $results;
    } finally {
        foreach (glob($barrier . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
    }
}

try {
    (new SystemRequirements())->assertReady();
    Database::migrate();
    (new Installer())->install([
        'college_name' => 'Mutation Concurrency College',
        'timezone' => 'UTC',
        'admin_name' => 'Mutation Administrator',
        'admin_email' => 'mutation-admin@example.test',
        'admin_password' => 'mutation-password-123',
        'seed_demo' => '1',
    ], test_authorized_setup_recovery_authority());
    $pdo = Database::connection();
    $applicationId = (int) $pdo->query("SELECT id FROM applications WHERE current_status = 'idle' ORDER BY id LIMIT 1")->fetchColumn();
    mutation_concurrency_assert($applicationId > 0, 'Concurrency contract requires an idle application.');

    $sameKey = bin2hex(random_bytes(16));
    $sameResults = mutation_concurrency_run('board', [
        'application_id' => $applicationId,
        'to_status' => 'scheduled',
        'expected_status' => 'idle',
        'note' => 'same concurrent request',
        'key' => $sameKey,
    ]);
    $duplicateFlags = array_map(
        static fn (array $result): mixed => $result['result']['duplicate'] ?? null,
        $sameResults
    );
    sort($duplicateFlags);
    mutation_concurrency_assert($duplicateFlags === [false, true], 'Same-key concurrent requests should apply once and return one duplicate.');
    mutation_concurrency_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM events WHERE application_id = {$applicationId} AND from_status = 'idle' AND to_status = 'scheduled'")->fetchColumn() === 1,
        'Same-key concurrency repeated transition evidence.'
    );

    $differentResults = mutation_concurrency_run('board', [[
        'application_id' => $applicationId,
        'to_status' => '',
        'expected_status' => 'scheduled',
        'note' => 'different concurrent key',
        'key' => bin2hex(random_bytes(16)),
    ], [
        'application_id' => $applicationId,
        'to_status' => '',
        'expected_status' => 'scheduled',
        'note' => 'different concurrent key',
        'key' => bin2hex(random_bytes(16)),
    ]]);
    $statuses = array_column($differentResults, 'status');
    sort($statuses);
    mutation_concurrency_assert($statuses === ['error', 'ok'], 'Different-key stale requests should produce one success and one stale error.');
    $error = array_values(array_filter($differentResults, static fn (array $result): bool => ($result['status'] ?? '') === 'error'))[0] ?? [];
    mutation_concurrency_assert(($error['code'] ?? '') === 'PLACEMENT_BOARD_STALE', 'Different-key loser should report stale board state.');
    mutation_concurrency_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM events WHERE application_id = {$applicationId} AND from_status = 'scheduled' AND to_status = 'intransit'")->fetchColumn() === 1,
        'Different-key concurrency repeated transition evidence.'
    );

    $enableResults = mutation_concurrency_run('module-enable', ['module_key' => 'advising']);
    mutation_concurrency_assert(array_column($enableResults, 'status') === ['ok', 'ok'], 'Concurrent module enables should both return successfully.');
    mutation_concurrency_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM module_lifecycle_events WHERE module_key = 'advising' AND event_type = 'enabled'")->fetchColumn() === 1,
        'Concurrent module enables duplicated lifecycle evidence.'
    );
    $disableResults = mutation_concurrency_run('module-disable', ['module_key' => 'advising']);
    mutation_concurrency_assert(array_column($disableResults, 'status') === ['ok', 'ok'], 'Concurrent module disables should both return successfully.');
    mutation_concurrency_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM module_lifecycle_events WHERE module_key = 'advising' AND event_type = 'disabled'")->fetchColumn() === 1,
        'Concurrent module disables duplicated lifecycle evidence.'
    );

    echo 'PASS mutation concurrency contract (' . Database::driver() . ' ' . Database::serverVersion() . ")\n";
} finally {
    Database::reset();
    if ($temporarySqlite !== null && is_file($temporarySqlite)) {
        unlink($temporarySqlite);
    }
}
