<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

final class IncidentReporter
{
    private const SAFE_SOURCE_CATEGORIES = [
        'bootstrap' => true,
        'web' => true,
        'setup' => true,
        'health' => true,
        'metrics' => true,
        'controller' => true,
        'cli' => true,
        'worker' => true,
        'persistence' => true,
    ];

    private const SAFE_CONTEXT_VALUES = [
        'method' => ['GET', 'POST', 'HEAD', 'UNKNOWN'],
        'mode' => ['readiness', 'liveness', 'environment_token', 'local'],
        'phase' => [
            'error_handler', 'uncaught', 'shutdown', 'authorization_header', 'payload_json', 'rollback',
            'acknowledgment', 'failure_state', 'session_not_active', 'session_response_started',
            'session_write', 'session_reopen', 'session_id_create',
            'session_id_read', 'session_cookie_reset', 'session_warning_storage', 'session_warning_response',
            'session_warning_other', 'session_returned_false', 'session_threw', 'state_prepare',
            'state_permissions', 'session_fingerprint', 'state_write_prepare', 'state_write_io', 'state_sync',
        ],
        'status' => ['failed'],
        'route' => [
            'bootstrap', 'unknown', 'login', 'sso', 'logout', 'portal', 'modules', 'public', 'student',
            'board', 'move', 'return-to-idle', 'board-preferences', 'notifications',
            'notification-acknowledge', 'candidate', 'records', 'records-candidate', 'records-company',
            'records-round', 'records-schedule', 'records-panelist', 'records-slot-assignment',
            'records-application', 'reports', 'import', 'import-rollback', 'admin', 'admin-user',
            'admin-users', 'admin-password', 'admin-workflow', 'preferences', 'preferences-resolve',
            'wanted', 'wanted-resolve', 'system', 'system-clear-demo', 'advising',
            'advising-appointment', 'advising-status', 'advising-note', 'advising-task',
        ],
        'operation' => [
            'placement.command', 'host_resolution', 'host_bootstrap', 'collection', 'probe', 'dispatch',
            'session', 'authorization', 'unlock', 'install', 'request', 'domain_event.dispatch',
            'domain_event.delivery', 'notification.delivery', 'notification.certification',
            'admin.password.reset', 'admin.settings', 'admin.user.create', 'admin.users.update',
            'admin.workflow', 'advising.create', 'advising.note', 'advising.task', 'advising.update',
            'auth.logout', 'auth.password', 'auth.sso', 'board.move', 'board.preferences',
            'board.return_to_idle', 'import.rollback', 'import.run', 'module.update',
            'notification.acknowledge', 'preference.create', 'preference.resolve',
            'record.application', 'record.assignment', 'record.candidate', 'record.company',
            'record.panelist', 'record.round', 'record.schedule', 'system.clear_demo_data',
            'wanted.create', 'wanted.resolve', 'configuration.import', 'portability.import', 'privacy.erasure',
            'installation', 'database_restore.cleanup',
        ],
    ];

    /**
     * Reports an opaque incident without ever allowing reporting failure to
     * replace the primary failure.
     *
     * @param array<string, bool|int|float|string|null> $context
     */
    public static function report(
        Throwable $exception,
        string $diagnosticCode,
        string $sourceCategory,
        array $context = [],
    ): string {
        $incidentId = self::incidentId();
        try {
            StructuredLogger::log('error', 'incident.reported', [
                'incident_id' => $incidentId,
                'diagnostic_code' => self::diagnosticCode($diagnosticCode),
                'exception_class' => self::exceptionClass($exception),
                'source_category' => isset(self::SAFE_SOURCE_CATEGORIES[$sourceCategory])
                    ? $sourceCategory
                    : 'unknown',
                'safe_context' => self::safeContext($context),
            ]);
        } catch (Throwable) {
            // Incident reporting must never mask the primary failure.
        }
        return $incidentId;
    }

    /**
     * Formats a diagnostic reference that is safe to persist or return.
     * Neither argument is reflected unless it matches the reviewed format.
     */
    public static function reference(string $diagnosticCode, string $incidentId): string
    {
        $safeIncidentId = preg_match('/\Ainc_[a-f0-9]{32}\z/D', $incidentId) === 1
            ? $incidentId
            : 'inc_unavailable';
        return self::diagnosticCode($diagnosticCode) . ' Reference: ' . $safeIncidentId;
    }

    private static function incidentId(): string
    {
        try {
            return 'inc_' . bin2hex(random_bytes(16));
        } catch (Throwable) {
            // random_bytes is the primary platform CSPRNG. OpenSSL and the
            // kernel random device are emergency CSPRNG fallbacks only.
        }
        try {
            if (function_exists('openssl_random_pseudo_bytes')) {
                $strong = false;
                $bytes = openssl_random_pseudo_bytes(16, $strong);
                if (is_string($bytes) && strlen($bytes) === 16 && $strong) {
                    return 'inc_' . bin2hex($bytes);
                }
            }
        } catch (Throwable) {
        }
        try {
            $handle = @fopen('/dev/urandom', 'rb');
            if (is_resource($handle)) {
                $bytes = fread($handle, 16);
                fclose($handle);
                if (is_string($bytes) && strlen($bytes) === 16) {
                    return 'inc_' . bin2hex($bytes);
                }
            }
        } catch (Throwable) {
        }

        // Preserve the no-throw contract during a platform-wide entropy
        // outage without pretending that a weak identifier is an incident ID.
        return 'inc_unavailable';
    }

    private static function diagnosticCode(string $code): string
    {
        return preg_match('/\ACPE_[A-Z0-9_]{3,59}\z/D', $code) === 1
            ? $code
            : 'CPE_INVALID_DIAGNOSTIC_CODE';
    }

    private static function exceptionClass(Throwable $exception): string
    {
        $class = get_class($exception);
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]{0,191}\z/D', $class) === 1) {
            return $class;
        }
        return $exception instanceof \Error ? 'Error' : 'Exception';
    }

    /**
     * @param array<string, bool|int|float|string|null> $context
     * @return array<string, bool|int|float|string|null>
     */
    private static function safeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (!is_string($key) || !isset(self::SAFE_CONTEXT_VALUES[$key])) {
                continue;
            }
            if (($key === 'status' && is_int($value) && $value >= 100 && $value <= 599)) {
                $safe[$key] = $value;
                continue;
            }
            if (is_string($value) && in_array($value, self::SAFE_CONTEXT_VALUES[$key], true)) {
                $safe[$key] = $value;
            }
        }
        return $safe;
    }
}
