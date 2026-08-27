<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Controllers\AuthController;
use App\Controllers\ModuleController;
use App\Controllers\PortalController;
use App\Core\Http\AuthorizationException;
use App\Core\Http\Router;
use App\Core\Http\UserVisibleException;
use App\Operations\RequestTelemetry;
use App\Support\Database;
use App\Support\Flash;
use App\Support\StructuredLogger;

$route = 'bootstrap';
$method = 'GET';
try {
    if (!Database::isInstalled()) {
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
} catch (AuthorizationException) {
    StructuredLogger::log('info', 'http.access_denied', ['route' => $route]);
    Flash::add('error', 'Access denied.');
    redirect('/');
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
