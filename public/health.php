<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Hosted\HostedBootstrap;
use App\Support\Database;

header('Content-Type: application/json; charset=UTF-8');
$ready = (string) ($_GET['ready'] ?? '') === '1';
$status = 'ok';
$checks = ['process' => 'ok'];
if ($ready) {
    try {
        HostedBootstrap::resolveHttpRequest();
        if (!Database::isInstalled()) {
            throw new RuntimeException('not_installed');
        }
        Database::connection()->query('SELECT 1')->fetchColumn();
        $checks['database'] = 'ok';
        $checks['migrations'] = Database::pendingMigrations() === [] ? 'ok' : 'pending';
        if ($checks['migrations'] !== 'ok') {
            $status = 'unavailable';
        }
    } catch (Throwable) {
        $status = 'unavailable';
        $checks['database'] = 'unavailable';
    }
}
http_response_code($status === 'ok' ? 200 : 503);
echo json_encode([
    'status' => $status,
    'mode' => $ready ? 'readiness' : 'liveness',
    'version' => (string) cpe_config('app.version', '0.0.0'),
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
