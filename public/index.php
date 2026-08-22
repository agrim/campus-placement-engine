<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Controllers\AuthController;
use App\Controllers\ModuleController;
use App\Controllers\PortalController;
use App\Core\Http\AuthorizationException;
use App\Core\Http\Router;
use App\Operations\RequestTelemetry;
use App\Support\Database;
use App\Support\Flash;
use App\Support\StructuredLogger;

if (!Database::isInstalled()) {
    redirect('/install.php');
}

$route = (string) ($_GET['r'] ?? 'portal');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
RequestTelemetry::start($route, $method);

try {
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
    $router->dispatch($route, $method);
} catch (AuthorizationException) {
    StructuredLogger::log('info', 'http.access_denied', ['route' => $route]);
    Flash::add('error', 'Access denied.');
    redirect('/');
} catch (Throwable $e) {
    $reference = StructuredLogger::requestId();
    StructuredLogger::log('error', 'http.exception', [
        'route' => $route,
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]);
    Flash::add('error', 'Request failed. Reference: ' . $reference);
    redirect('/');
}
