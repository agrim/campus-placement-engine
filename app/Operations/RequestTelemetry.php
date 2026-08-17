<?php

declare(strict_types=1);

namespace App\Operations;

use App\Hosted\HostedContext;
use App\Support\StructuredLogger;

final class RequestTelemetry
{
    private static float $startedAt = 0.0;
    private static string $route = '';
    private static string $method = '';
    private static bool $registered = false;

    public static function start(string $route, string $method): void
    {
        self::$startedAt = microtime(true);
        self::$route = $route;
        self::$method = strtoupper($method);
        if (!headers_sent()) {
            header('X-Request-ID: ' . StructuredLogger::requestId());
        }
        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function(self::finish(...));
        }
    }

    public static function finish(): void
    {
        if (self::$startedAt <= 0) {
            return;
        }
        $lastError = error_get_last();
        $fatal = is_array($lastError) && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
        StructuredLogger::log($fatal ? 'error' : 'info', 'http.request', [
            'route' => self::$route,
            'method' => self::$method,
            'status' => http_response_code() ?: 200,
            'duration_ms' => round((microtime(true) - self::$startedAt) * 1000, 2),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'tenant' => HostedContext::isActive() ? HostedContext::current()->slug() : 'self-hosted',
            'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            'fatal' => $fatal,
        ]);
        self::$startedAt = 0.0;
    }
}
