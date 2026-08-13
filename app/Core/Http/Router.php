<?php

declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

final class Router
{
    private array $routes = [];

    public function register(array $routes): void
    {
        foreach ($routes as $route) {
            $name = trim((string) ($route['name'] ?? ''));
            $method = strtoupper(trim((string) ($route['method'] ?? 'GET')));
            $controller = (string) ($route['controller'] ?? '');
            $action = (string) ($route['action'] ?? '');
            if ($name === '' || $controller === '' || $action === '') {
                throw new RuntimeException('Route definitions require name, controller, and action.');
            }
            $key = $method . ' ' . $name;
            if (isset($this->routes[$key])) {
                throw new RuntimeException('Duplicate route definition: ' . $key);
            }
            $this->routes[$key] = [
                'name' => $name,
                'method' => $method,
                'controller' => $controller,
                'action' => $action,
                'module' => (string) ($route['module'] ?? 'core'),
            ];
        }
    }

    public function dispatch(string $name, string $method): void
    {
        $key = strtoupper($method) . ' ' . $name;
        $route = $this->routes[$key] ?? null;
        if ($route === null) {
            throw new RuntimeException('Route not found.');
        }
        $controllerClass = $route['controller'];
        $action = $route['action'];
        if (!class_exists($controllerClass) || !method_exists($controllerClass, $action)) {
            throw new RuntimeException('Route target is unavailable: ' . $route['module'] . '.' . $name);
        }
        (new $controllerClass())->{$action}();
    }

    public function definitions(): array
    {
        return array_values($this->routes);
    }
}
