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
    private static ?string $apiRequestId = null;
    private static bool $registered = false;

    public static function start(string $route, string $method, ?string $apiRequestId = null): void
    {
        self::$startedAt = microtime(true);
        self::$route = preg_match('/\A(?:bootstrap|unknown|login|sso|logout|portal|modules|public|student|board|move|return-to-idle|board-preferences|notifications|notification-acknowledge|candidate|records|records-candidate|records-company|records-round|records-schedule|records-panelist|records-slot-assignment|records-application|reports|import|import-rollback|admin|admin-user|admin-users|admin-password|admin-workflow|integrations|integration-create|integration-secret-generate|integration-secret-rotate|integration-validate|integration-activate|integration-disable|integration-revoke|integration-replay|preferences|preferences-resolve|wanted|wanted-resolve|system|system-clear-demo|advising|advising-appointment|advising-status|advising-note|advising-task|api-v1-root|api-v1-opportunities-(?:list|item)|api-v1-applications-(?:list|item)|api-v1-unknown)\z/D', $route) === 1
            ? $route
            : 'unknown';
        $normalizedMethod = strtoupper($method);
        self::$method = in_array($normalizedMethod, ['GET', 'POST', 'HEAD', 'OPTIONS'], true) ? $normalizedMethod : 'UNKNOWN';
        self::$apiRequestId = is_string($apiRequestId)
            && preg_match('/\Areq_[a-f0-9]{32}\z/D', $apiRequestId) === 1
                ? $apiRequestId
                : null;
        if (!headers_sent()) {
            header('X-Request-ID: ' . (self::$apiRequestId ?? StructuredLogger::requestId()));
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
        $context = [
            'route' => self::$route,
            'method' => self::$method,
            'status' => http_response_code() ?: 200,
            'duration_ms' => round((microtime(true) - self::$startedAt) * 1000, 2),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'tenant' => HostedContext::isActive() ? 'hosted' : 'self-hosted',
            'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            'fatal' => $fatal,
        ];
        if (self::$apiRequestId !== null) {
            $context['api_request_id'] = self::$apiRequestId;
        }
        StructuredLogger::log($fatal ? 'error' : 'info', 'http.request', $context);
        self::$startedAt = 0.0;
        self::$apiRequestId = null;
    }
}
