<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Install\Installer;
use App\Support\Database;

function install_worker_wait(string $file, string $label, int $timeoutMilliseconds = 30000): void
{
    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    while (!is_file($file)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Install concurrency worker timed out waiting for ' . $label . '.');
        }
        usleep(1000);
    }
}

function install_worker_emit(array $result): void
{
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
}

$participant = (string) getenv('CPE_INSTALL_TEST_PARTICIPANT');
$readyFile = (string) getenv('CPE_INSTALL_TEST_READY');
$startFile = (string) getenv('CPE_INSTALL_TEST_START');
$encodedInput = (string) getenv('CPE_INSTALL_TEST_INPUT');

try {
    $decodedInput = base64_decode($encodedInput, true);
    if (!is_string($decodedInput)) {
        throw new RuntimeException('Install concurrency worker input is not valid base64.');
    }
    $input = json_decode($decodedInput, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new RuntimeException('Install concurrency worker input must be an object.');
    }
    $tenantPublicId = (string) ($input['tenant_public_id'] ?? '');
    unset($input['tenant_public_id']);
    if (preg_match('/^tenant_[a-f0-9]{32}$/', $tenantPublicId) !== 1) {
        throw new RuntimeException('Install concurrency worker tenant identity is invalid.');
    }
    if ($participant === '' || $readyFile === '' || $startFile === '') {
        throw new RuntimeException('Install concurrency worker coordination is incomplete.');
    }
    if (file_put_contents($readyFile, "ready\n") === false) {
        throw new RuntimeException('Install concurrency worker could not publish readiness.');
    }
    install_worker_wait($startFile, 'the shared start barrier');

    $pdo = Database::connection();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $backendPidBefore = $driver === 'pgsql'
        ? trim((string) $pdo->query('SELECT pg_backend_pid()::text')->fetchColumn())
        : null;
    $adminId = (new Installer())->installHosted($input, $tenantPublicId);
    $backendPidAfter = $driver === 'pgsql'
        ? trim((string) $pdo->query('SELECT pg_backend_pid()::text')->fetchColumn())
        : null;
    $institutionPublicId = (string) $pdo
        ->query("SELECT public_id FROM institutions WHERE slug = 'default'")
        ->fetchColumn();
    install_worker_emit([
        'status' => 'success',
        'participant' => $participant,
        'admin_id' => $adminId,
        'institution_public_id' => $institutionPublicId,
        'backend_pid_before' => $backendPidBefore,
        'backend_pid_after' => $backendPidAfter,
    ]);
    exit(0);
} catch (Throwable $e) {
    install_worker_emit([
        'status' => 'error',
        'participant' => $participant,
        'error' => $e->getMessage(),
    ]);
    exit(2);
} finally {
    Database::reset();
}
