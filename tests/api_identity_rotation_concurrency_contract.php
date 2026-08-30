<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/cpe-api-rotation-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create API rotation contract directory.');
}
$testRoot = realpath($testRoot) ?: $testRoot;
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $testRoot . '/rotation.sqlite');
}
putenv('CPE_LOG_PATH=' . $testRoot . '/structured.log');
$rootKey = rtrim(strtr(base64_encode(str_repeat("\x52", 32)), '+/', '-_'), '=');
putenv('CPE_API_KEYRING=rotation-v1=' . $rootKey);
putenv('CPE_API_ACTIVE_KEY_VERSION=rotation-v1');

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';
require $projectRoot . '/tests/authorized_setup_recovery_fixture.php';

use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiServiceAccountService;
use App\Api\Security\ApiTokenAuthenticator;
use App\Api\Security\InvalidApiCredential;
use App\Install\Installer;
use App\Support\Database;

function api_rotation_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function api_rotation_wait(array $paths, string $label): void
{
    $deadline = hrtime(true) + 30_000_000_000;
    while (true) {
        if (array_filter($paths, static fn (string $path): bool => !is_file($path)) === []) {
            return;
        }
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for API rotation ' . $label . '.');
        }
        usleep(1_000);
    }
}

/** @return array{process: resource, pipes: array<int, resource>} */
function api_rotation_spawn(string $accountId, string $ready, string $start): array
{
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['CPE_API_ROTATION_TEST_ACCOUNT'] = $accountId;
    $environment['CPE_API_ROTATION_TEST_READY'] = $ready;
    $environment['CPE_API_ROTATION_TEST_START'] = $start;
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/api_identity_rotation_concurrency_worker.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start API rotation worker.');
    }
    fclose($pipes[0]);
    unset($pipes[0]);
    return ['process' => $process, 'pipes' => $pipes];
}

/** @param array<int, array{process: resource, pipes: array<int, resource>}> $workers */
function api_rotation_stop(array &$workers): void
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

/** @param array<int, array{process: resource, pipes: array<int, resource>}> $workers @return list<array<string, string>> */
function api_rotation_collect(array &$workers): array
{
    $results = [];
    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $code = proc_close($worker['process']);
        $decoded = json_decode(trim((string) $stdout), true);
        api_rotation_assert($code === 0 && is_array($decoded) && ($decoded['status'] ?? '') === 'ok', 'Concurrent rotation worker failed: ' . $stderr);
        $results[] = array_map('strval', $decoded);
    }
    $workers = [];
    return $results;
}

function api_rotation_remove_tree(string $path): void
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
    (new Installer())->installHosted([
        'college_name' => 'API Rotation Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'API Rotation Administrator',
        'admin_email' => 'api-rotation@example.test',
        'admin_password' => 'api-rotation-password-123',
        'seed_demo' => '0',
    ], 'tenant_' . str_repeat('e', 32), test_authorized_setup_recovery_authority());
    $pdo = Database::connection();
    $service = new ApiServiceAccountService($pdo, ApiKeyring::fromEnvironment());
    $created = $service->create('Concurrent rotation', ['applications.read'], 1);
    $service->setApiEnabled(true, 1);

    $start = $testRoot . '/start';
    $ready = [$testRoot . '/ready-a', $testRoot . '/ready-b'];
    $workers[] = api_rotation_spawn($created['service_account_id'], $ready[0], $start);
    $workers[] = api_rotation_spawn($created['service_account_id'], $ready[1], $start);
    api_rotation_wait($ready, 'worker readiness');
    if (file_put_contents($start, "start\n") === false) {
        throw new RuntimeException('Could not publish API rotation start.');
    }
    $results = api_rotation_collect($workers);

    api_rotation_assert(count($results) === 2, 'Concurrent rotation did not return two results.');
    api_rotation_assert($results[0]['lookup_id'] !== $results[1]['lookup_id'], 'Concurrent rotation reused a lookup ID.');
    api_rotation_assert((int) $pdo->query('SELECT COUNT(*) FROM api_access_tokens')->fetchColumn() === 3, 'Concurrent rotation lost a committed token row.');
    api_rotation_assert((int) $pdo->query('SELECT COUNT(*) FROM api_access_tokens WHERE revoked_at IS NULL')->fetchColumn() === 2, 'Concurrent rotation exceeded the two-token usable bound.');
    api_rotation_assert((int) $pdo->query('SELECT COUNT(*) FROM api_access_tokens WHERE revoked_at IS NULL AND rotation_grace_expires_at IS NULL')->fetchColumn() === 1, 'Concurrent rotation did not leave exactly one current token.');
    api_rotation_assert((int) $pdo->query('SELECT COUNT(*) FROM api_access_tokens WHERE revoked_at IS NULL AND rotation_grace_expires_at IS NOT NULL')->fetchColumn() === 1, 'Concurrent rotation did not leave exactly one grace token.');

    $authenticator = new ApiTokenAuthenticator($pdo, ApiKeyring::fromEnvironment());
    foreach ($results as $result) {
        $authenticator->authenticate($result['token']);
    }
    try {
        $authenticator->authenticate($created['token']);
        throw new RuntimeException('Original token survived two concurrent rotations.');
    } catch (InvalidApiCredential) {
    }

    $structuredLog = is_file($testRoot . '/structured.log') ? (string) file_get_contents($testRoot . '/structured.log') : '';
    foreach ([$created['token'], $results[0]['token'], $results[1]['token']] as $token) {
        api_rotation_assert(!str_contains($structuredLog, $token), 'Concurrent rotation logged plaintext token material.');
    }
    echo 'PASS API token rotation concurrency (' . Database::driver() . ")\n";
} finally {
    api_rotation_stop($workers);
    Database::reset();
    putenv('CPE_API_KEYRING');
    putenv('CPE_API_ACTIVE_KEY_VERSION');
    putenv('CPE_LOG_PATH');
    api_rotation_remove_tree($testRoot);
}
