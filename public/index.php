<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Controllers\AuthController;
use App\Controllers\ModuleController;
use App\Controllers\PortalController;
use App\Core\Http\AuthorizationException;
use App\Core\Http\Router;
use App\Core\Http\UserVisibleException;
use App\Core\Install\InstallationStateUnavailable;
use App\Core\Security\AuthorizationUnavailable;
use App\Operations\RequestTelemetry;
use App\Support\Database;
use App\Support\Flash;
use App\Support\IncidentReporter;
use App\Support\StructuredLogger;

function cpe_authorization_unavailable(AuthorizationUnavailable $exception): never
{
    $diagnosticCode = match ($exception->reason()) {
        AuthorizationUnavailable::INSTALLATION_STATE => 'CPE_AUTHORIZATION_INSTALLATION_STATE_UNAVAILABLE',
        AuthorizationUnavailable::MODULE_STATE => 'CPE_AUTHORIZATION_MODULE_STATE_UNAVAILABLE',
        default => 'CPE_AUTHORIZATION_CAPABILITY_STATE_UNAVAILABLE',
    };
    $incidentId = IncidentReporter::report(
        $exception,
        $diagnosticCode,
        'web',
        ['operation' => 'authorization'],
    );
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store, private');
    }
    echo 'Authorization temporarily unavailable. Reference: ' . $incidentId . "\n";
    exit;
}

function cpe_access_denied(): never
{
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store, private');
    }
    echo "Access denied.\n";
    exit;
}

$route = 'bootstrap';
$method = 'GET';
try {
    try {
        $installed = Database::hasInstalledMarkerStrict();
    } catch (InstallationStateUnavailable) {
        throw AuthorizationUnavailable::installationState();
    }
    if (!$installed) {
        redirect('/install.php');
    }

    $routeValue = $_GET['r'] ?? 'portal';
    $requestedRoute = is_string($routeValue) ? $routeValue : '';
    $methodValue = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $method = is_string($methodValue) ? strtoupper($methodValue) : 'INVALID';

    $router = new Router();
    $router->register([
        ['name' => 'login', 'method' => 'GET', 'controller' => AuthController::class, 'action' => 'show'],
        ['name' => 'login', 'method' => 'POST', 'controller' => AuthController::class, 'action' => 'login'],
        ['name' => 'sso', 'method' => 'GET', 'controller' => AuthController::class, 'action' => 'sso'],
        ['name' => 'logout', 'method' => 'POST', 'controller' => AuthController::class, 'action' => 'logout'],
        ['name' => 'portal', 'method' => 'GET', 'controller' => PortalController::class, 'action' => 'home'],
        ['name' => 'modules', 'method' => 'GET', 'controller' => ModuleController::class, 'action' => 'show'],
        ['name' => 'modules', 'method' => 'POST', 'controller' => ModuleController::class, 'action' => 'update'],
    ]);
    $router->register(cpe_context()->moduleManager()->routes());
    $route = $router->canonicalName($requestedRoute, $method);
    RequestTelemetry::start($route, $method);
    $router->dispatch($requestedRoute, $method);
} catch (AuthorizationUnavailable $e) {
    cpe_authorization_unavailable($e);
} catch (AuthorizationException) {
    StructuredLogger::log('info', 'http.access_denied', ['route' => $route]);
    cpe_access_denied();
} catch (UserVisibleException $e) {
    Flash::add('error', $e->publicMessage());
    redirect('/');
} catch (Throwable $e) {
    $incidentId = cpe_report_incident(
        $e,
        'CPE_WEB_REQUEST_FAILED',
        'web',
        ['route' => $route, 'method' => $method, 'operation' => 'dispatch'],
    );
    Flash::add('error', 'Request failed. Reference: ' . $incidentId);
    redirect('/');
}
