<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Hosted\HostedBootstrap;
use App\Install\SystemRequirements;
use App\Security\OperationalBearerAuthorization;
use App\Support\Database;
use App\Support\IncidentReporter;

$ready = (string) ($_GET['ready'] ?? '') === '1';
$platformBootstrap = trim((string) (getenv('CPE_PLATFORM_BOOTSTRAP') ?: ''));
if (
    $ready
    && (HostedBootstrap::enabled() || $platformBootstrap !== '')
    && !OperationalBearerAuthorization::authorizes(
        $_SERVER,
        (string) (getenv('CPE_METRICS_TOKEN') ?: ''),
    )
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found.\n";
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
$status = 'ok';
$checks = ['process' => 'ok'];
if ($ready) {
    try {
        cpe_resolve_hosted_http_request();
        $runtimeFailures = array_filter(
            (new SystemRequirements())->runtimeChecks(),
            static fn (array $check): bool => !$check['ok'],
        );
        $checks['runtime'] = $runtimeFailures === [] ? 'ok' : 'unavailable';
        if ($runtimeFailures !== []) {
            throw new RuntimeException('runtime_requirements');
        }
        if (!Database::isInstalled()) {
            throw new RuntimeException('not_installed');
        }
        Database::connection()->query('SELECT 1')->fetchColumn();
        $checks['database'] = 'ok';
        $checks['migrations'] = Database::pendingMigrations() === [] ? 'ok' : 'pending';
        if ($checks['migrations'] !== 'ok') {
            $status = 'unavailable';
        }
    } catch (Throwable $e) {
        IncidentReporter::report(
            $e,
            'CPE_HEALTH_READINESS_FAILED',
            'health',
            ['mode' => 'readiness', 'operation' => 'probe'],
        );
        $status = 'unavailable';
        $checks['database'] = ($checks['runtime'] ?? '') === 'unavailable' ? 'not_checked' : 'unavailable';
    }
}
http_response_code($status === 'ok' ? 200 : 503);
echo json_encode([
    'status' => $status,
    'mode' => $ready ? 'readiness' : 'liveness',
    'version' => (string) cpe_config('app.version', '0.0.0'),
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
