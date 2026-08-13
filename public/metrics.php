<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Hosted\HostedBootstrap;
use App\Operations\MetricsService;
use App\Support\Database;

$secret = (string) (getenv('CPE_METRICS_TOKEN') ?: '');
$authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$provided = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
if ($secret === '' || strlen($secret) < 24 || !hash_equals($secret, $provided)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found.\n";
    exit;
}
try {
    HostedBootstrap::resolveHttpRequest();
} catch (Throwable) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found.\n";
    exit;
}
if (!Database::isInstalled()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found.\n";
    exit;
}
header('Content-Type: text/plain; version=0.0.4; charset=UTF-8');
echo (new MetricsService())->prometheus();
