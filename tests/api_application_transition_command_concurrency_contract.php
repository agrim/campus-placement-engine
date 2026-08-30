<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/cpe-api-command-race-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create API command race directory.');
}
$testRoot = realpath($testRoot) ?: $testRoot;
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $testRoot . '/race.sqlite');
}
putenv('CPE_LOG_PATH=' . $testRoot . '/structured.log');
$encodedKeyOne = rtrim(strtr(base64_encode(str_repeat("\x71", 32)), '+/', '-_'), '=');
$encodedKeyTwo = rtrim(strtr(base64_encode(str_repeat("\x72", 32)), '+/', '-_'), '=');
putenv('CPE_API_KEYRING=race-v1=' . $encodedKeyOne . ';race-v2=' . $encodedKeyTwo);
putenv('CPE_API_ACTIVE_KEY_VERSION=race-v1');

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';
require $projectRoot . '/tests/authorized_setup_recovery_fixture.php';

use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiServiceAccountService;
use App\Install\Installer;
use App\Support\Database;

function api_command_race_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function api_command_race_wait(array $paths, string $label): void
{
    $deadline = hrtime(true) + 30_000_000_000;
    while (true) {
        if (array_filter($paths, static fn (string $path): bool => !is_file($path)) === []) {
            return;
        }
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for API command race ' . $label . '.');
        }
        usleep(1_000);
    }
}

/** @return array{process: resource, pipes: array<int, resource>} */
function api_command_race_spawn(array $fixture, string $ready, string $start): array
{
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    foreach ($fixture as $name => $value) {
        $environment[$name] = (string) $value;
    }
    $environment['CPE_API_COMMAND_TEST_READY'] = $ready;
    $environment['CPE_API_COMMAND_TEST_START'] = $start;
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/api_application_transition_command_concurrency_worker.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start API command race worker.');
    }
    fclose($pipes[0]);
    unset($pipes[0]);
    return ['process' => $process, 'pipes' => $pipes];
}

/** @param array<int, array{process: resource, pipes: array<int, resource>}> $workers */
function api_command_race_stop(array &$workers): void
{
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($worker['process'])) {
            proc_close($worker['process']);
        }
    }
    $workers = [];
}

/** @param array<int, array{process: resource, pipes: array<int, resource>}> $workers @return list<array<string, mixed>> */
function api_command_race_collect(array &$workers): array
{
    $results = [];
    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $code = proc_close($worker['process']);
        $decoded = json_decode(trim((string) $stdout), true);
        api_command_race_assert(
            $code === 0 && is_array($decoded) && ($decoded['status'] ?? '') === 'ok',
            'API command race worker failed: ' . $stderr,
        );
        $results[] = $decoded;
    }
    $workers = [];
    return $results;
}

function api_command_race_remove_tree(string $path): void
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

$workers = [];
try {
    Database::migrate();
    $institutionPublicId = 'tenant_' . str_repeat('8', 32);
    (new Installer())->installHosted([
        'college_name' => 'API Command Race College',
        'timezone' => 'UTC',
        'admin_name' => 'API Command Race Administrator',
        'admin_email' => 'api-command-race@example.test',
        'admin_password' => 'api-command-race-password-123',
        'seed_demo' => '1',
    ], $institutionPublicId, test_authorized_setup_recovery_authority());
    $pdo = Database::connection();
    $created = (new ApiServiceAccountService($pdo, ApiKeyring::fromEnvironment()))
        ->create('Concurrent transition connector', ['applications.read'], 1);
    $account = $pdo->prepare('SELECT id FROM api_service_accounts WHERE public_id = ?');
    $account->execute([$created['service_account_id']]);
    $accountId = (int) $account->fetchColumn();
    $applicationPublicId = (string) $pdo->query(
        'SELECT application.public_id
         FROM applications application
         JOIN placement_cycle_participants participant ON participant.id = application.participant_id
         JOIN placement_cycles cycle ON cycle.id = participant.cycle_id
         WHERE cycle.institution_id = (SELECT id FROM institutions WHERE slug = \'default\')
         ORDER BY application.id LIMIT 1',
    )->fetchColumn();
    api_command_race_assert($accountId > 0 && $applicationPublicId !== '', 'API command race fixture is incomplete.');

    $fixture = [
        'CPE_API_COMMAND_TEST_ACCOUNT_ID' => $accountId,
        'CPE_API_COMMAND_TEST_ACCOUNT_PUBLIC_ID' => $created['service_account_id'],
        'CPE_API_COMMAND_TEST_INSTITUTION_PUBLIC_ID' => $institutionPublicId,
        'CPE_API_COMMAND_TEST_APPLICATION_PUBLIC_ID' => $applicationPublicId,
        'CPE_API_COMMAND_TEST_KEY' => str_repeat('f', 32),
    ];
    // Release the fixture connection before concurrent SQLite writers start;
    // an unfinalized read statement on the parent connection can retain a
    // shared journal lock even though the parent performs no race mutation.
    Database::reset();
    unset($account, $pdo);
    $ready = [$testRoot . '/ready-a', $testRoot . '/ready-b'];
    $start = $testRoot . '/start';
    $workers[] = api_command_race_spawn($fixture, $ready[0], $start);
    $workers[] = api_command_race_spawn($fixture, $ready[1], $start);
    api_command_race_wait($ready, 'worker readiness');
    if (file_put_contents($start, "start\n") === false) {
        throw new RuntimeException('Could not publish API command race start.');
    }
    $results = api_command_race_collect($workers);
    $pdo = Database::connection();
    $outcomes = array_column($results, 'outcome');
    sort($outcomes, SORT_STRING);
    api_command_race_assert($outcomes === ['new', 'replay'], 'Same-key race did not serialize to one reservation and one replay.');
    api_command_race_assert(
        count(array_unique(array_map(static fn (array $row): int => (int) $row['record_id'], $results))) === 1,
        'Same-key race returned different command records.',
    );
    api_command_race_assert(
        count(array_unique(array_column($results, 'response_json'))) === 1,
        'Same-key race returned different committed responses.',
    );
    api_command_race_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM api_command_idempotency_keys WHERE lifecycle_state = 'completed'")->fetchColumn() === 1,
        'Same-key race did not leave exactly one completed command row.',
    );
    api_command_race_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM api_command_idempotency_keys WHERE lifecycle_state = 'pending'")->fetchColumn() === 0,
        'Same-key race left a durable pending command row.',
    );

    $secondCreated = (new ApiServiceAccountService($pdo, ApiKeyring::fromEnvironment()))
        ->create('Concurrent transition connector two', ['applications.read'], 1);
    $secondAccount = $pdo->prepare('SELECT id FROM api_service_accounts WHERE public_id = ?');
    $secondAccount->execute([$secondCreated['service_account_id']]);
    $secondAccountId = (int) $secondAccount->fetchColumn();
    api_command_race_assert($secondAccountId > 0, 'Second API command race account was not created.');

    $crossAccountKey = str_repeat('e', 32);
    $crossAccountFixtureOne = [
        'CPE_API_COMMAND_TEST_ACCOUNT_ID' => $accountId,
        'CPE_API_COMMAND_TEST_ACCOUNT_PUBLIC_ID' => $created['service_account_id'],
        'CPE_API_COMMAND_TEST_INSTITUTION_PUBLIC_ID' => $institutionPublicId,
        'CPE_API_COMMAND_TEST_APPLICATION_PUBLIC_ID' => $applicationPublicId,
        'CPE_API_COMMAND_TEST_KEY' => $crossAccountKey,
        'CPE_API_ACTIVE_KEY_VERSION' => 'race-v1',
    ];
    $crossAccountFixtureTwo = [
        'CPE_API_COMMAND_TEST_ACCOUNT_ID' => $secondAccountId,
        'CPE_API_COMMAND_TEST_ACCOUNT_PUBLIC_ID' => $secondCreated['service_account_id'],
        'CPE_API_COMMAND_TEST_INSTITUTION_PUBLIC_ID' => $institutionPublicId,
        'CPE_API_COMMAND_TEST_APPLICATION_PUBLIC_ID' => $applicationPublicId,
        'CPE_API_COMMAND_TEST_KEY' => $crossAccountKey,
        'CPE_API_ACTIVE_KEY_VERSION' => 'race-v2',
    ];
    Database::reset();
    unset($secondAccount, $pdo);
    $crossReady = [$testRoot . '/ready-c', $testRoot . '/ready-d'];
    $crossStart = $testRoot . '/cross-start';
    $workers[] = api_command_race_spawn($crossAccountFixtureOne, $crossReady[0], $crossStart);
    $workers[] = api_command_race_spawn($crossAccountFixtureTwo, $crossReady[1], $crossStart);
    api_command_race_wait($crossReady, 'cross-account worker readiness');
    if (file_put_contents($crossStart, "start\n") === false) {
        throw new RuntimeException('Could not publish cross-account API command race start.');
    }
    $crossResults = api_command_race_collect($workers);
    $crossOutcomes = array_column($crossResults, 'outcome');
    sort($crossOutcomes, SORT_STRING);
    api_command_race_assert(
        $crossOutcomes === ['account_conflict', 'new'],
        'Cross-account/key-version race did not serialize to one command and one account conflict.',
    );
    api_command_race_assert(
        count(array_filter(
            $crossResults,
            static fn (array $result): bool => (int) ($result['record_id'] ?? 0) > 0,
        )) === 1,
        'Cross-account/key-version race returned more than one completed command record.',
    );
    $pdo = Database::connection();
    api_command_race_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM api_command_idempotency_keys WHERE lifecycle_state = 'completed'")->fetchColumn() === 2,
        'Cross-account/key-version race did not leave exactly one additional completed command row.',
    );
    api_command_race_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM api_command_idempotency_keys WHERE lifecycle_state = 'pending'")->fetchColumn() === 0,
        'Cross-account/key-version race left a durable pending command row.',
    );
    api_command_race_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM api_command_idempotency_keys')->fetchColumn() === 2,
        'Cross-account/key-version race created duplicate command records.',
    );

    echo 'PASS API application transition command concurrency (' . Database::driver() . ")\n";
} finally {
    api_command_race_stop($workers);
    Database::reset();
    putenv('CPE_API_KEYRING');
    putenv('CPE_API_ACTIVE_KEY_VERSION');
    putenv('CPE_LOG_PATH');
    api_command_race_remove_tree($testRoot);
}
