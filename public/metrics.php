<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Operations\MetricsService;
use App\Security\OperationalBearerAuthorization;
use App\Support\Database;
use App\Support\IncidentReporter;

$secret = (string) (getenv('CPE_METRICS_TOKEN') ?: '');
if (!OperationalBearerAuthorization::authorizes($_SERVER, $secret)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found.\n";
    exit;
}
try {
    cpe_resolve_hosted_http_request();
} catch (Throwable $e) {
    IncidentReporter::report(
        $e,
        'CPE_METRICS_RESOLUTION_FAILED',
        'metrics',
        ['operation' => 'host_resolution'],
    );
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found.\n";
    exit;
}
try {
    if (!Database::isInstalled()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Not found.\n";
        exit;
    }
    $metrics = (new MetricsService())->prometheus();
} catch (Throwable $e) {
    IncidentReporter::report(
        $e,
        'CPE_METRICS_COLLECTION_FAILED',
        'metrics',
        ['operation' => 'collection'],
    );
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Metrics unavailable.\n";
    exit;
}
header('Content-Type: text/plain; version=0.0.4; charset=UTF-8');
echo $metrics;
