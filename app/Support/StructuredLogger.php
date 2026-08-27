<?php

declare(strict_types=1);

namespace App\Support;

final class StructuredLogger
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            try {
                self::$requestId = 'req_' . bin2hex(random_bytes(12));
            } catch (\Throwable) {
                self::$requestId = 'req_unavailable';
            }
        }
        return self::$requestId;
    }

    public static function log(string $level, string $event, array $context = []): void
    {
        try {
            $record = [
                'timestamp' => gmdate('c'),
                'level' => self::safeToken(strtolower($level), 'unknown'),
                'event' => self::safeToken($event, 'logger.invalid_event'),
                'request_id' => self::requestId(),
                'context' => self::sanitize($context),
            ];
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $line = (is_string($encoded) ? $encoded : '{"event":"logger.encoding_failed"}') . "\n";
            $path = trim((string) (getenv('CPE_LOG_PATH') ?: ''));
            if ($path !== '' && self::appendProtected($path, $line)) {
                return;
            }
            @error_log(rtrim($line));
        } catch (\Throwable) {
            // Logging is diagnostic only and must not alter application flow.
        }
    }

    private static function safeToken(string $value, string $fallback): string
    {
        return preg_match('/\A[A-Za-z0-9_.:-]{1,96}\z/D', $value) === 1 ? $value : $fallback;
    }

    private static function sanitize(array $context, int $depth = 0): array
    {
        if ($depth >= 6) {
            return ['truncated' => true];
        }
        $clean = [];
        foreach ($context as $key => $value) {
            $originalName = is_string($key) ? self::normalizedKey($key) : $key;
            $name = self::sanitizeKey($key);
            if (is_string($originalName) && self::secretKey($originalName)) {
                $clean[$name] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $clean[$name] = self::sanitize($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$name] = self::safeStringForKey($originalName, $value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$name] = $value;
            } else {
                $clean[$name] = get_debug_type($value);
            }
        }
        return $clean;
    }

    private static function sanitizeKey(int|string $key): int|string
    {
        if (is_int($key)) {
            return $key;
        }
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/D', $key) === 1) {
            return $key;
        }
        return 'field_' . substr(hash('sha256', $key), 0, 12);
    }

    private static function normalizedKey(string $key): string
    {
        $key = strtolower(trim($key));
        return trim(preg_replace('/[^a-z0-9]+/', '_', $key) ?? '', '_');
    }

    private static function secretKey(string $name): bool
    {
        return preg_match(
            '/(?:password|passphrase|secret|token|authorization|cookie|credential|session|csrf|payload|message|error|detail|trace|file|path|dsn|url|email|person|user_?name|api_?key|private_?key|access_?key|client_?secret|refresh_?token)/i',
            $name,
        ) === 1;
    }

    private static function safeStringForKey(int|string $key, string $value): string
    {
        if (!is_string($key)) {
            return '[redacted]';
        }
        $allowed = match ($key) {
            'incident_id', 'request_id' => preg_match('/\A(?:inc|req)_[a-f0-9]{24,32}\z/D', $value) === 1 || in_array($value, ['inc_unavailable', 'req_unavailable'], true),
            'diagnostic_code' => preg_match('/\ACPE_[A-Z0-9_]{3,59}\z/D', $value) === 1,
            'exception', 'exception_class' => preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]{0,191}\z/D', $value) === 1,
            'source_category' => in_array($value, ['bootstrap', 'web', 'setup', 'health', 'metrics', 'controller', 'cli', 'worker', 'persistence', 'unknown'], true),
            'method' => in_array($value, ['GET', 'POST', 'HEAD', 'UNKNOWN'], true),
            'mode' => in_array($value, ['readiness', 'liveness', 'environment_token', 'local'], true),
            'phase' => in_array($value, [
                'error_handler', 'uncaught', 'shutdown', 'authorization_header', 'payload_json', 'rollback',
                'acknowledgment', 'failure_state', 'session_not_active', 'session_response_started',
                'session_write', 'session_reopen', 'session_id_create',
                'session_id_read', 'session_cookie_reset', 'session_warning_storage', 'session_warning_response',
                'session_warning_other', 'session_returned_false', 'session_threw',
            ], true),
            'status' => in_array($value, ['failed'], true),
            'route' => preg_match('/\A(?:bootstrap|unknown|login|sso|logout|portal|modules|public|student|board|move|return-to-idle|board-preferences|notifications|notification-acknowledge|candidate|records(?:-(?:candidate|company|round|schedule|panelist|slot-assignment|application))?|reports|import(?:-rollback)?|admin(?:-(?:user|users|password|workflow))?|preferences(?:-resolve)?|wanted(?:-resolve)?|system(?:-clear-demo)?|advising(?:-(?:appointment|status|note|task))?)\z/D', $value) === 1,
            'operation' => preg_match('/\A(?:placement\.command|host_(?:resolution|bootstrap)|collection|probe|dispatch|session|authorization|unlock|install|installation|request|database_restore\.cleanup|domain_event\.(?:dispatch|delivery)|notification\.(?:delivery|certification|acknowledge)|admin\.(?:password\.reset|settings|user\.create|users\.update|workflow)|advising\.(?:create|note|task|update)|auth\.(?:logout|password|sso)|board\.(?:move|preferences|return_to_idle)|import\.(?:rollback|run)|module\.update|preference\.(?:create|resolve)|record\.(?:application|assignment|candidate|company|panelist|round|schedule)|system\.clear_demo_data|wanted\.(?:create|resolve)|configuration\.import|portability\.import|privacy\.erasure)\z/D', $value) === 1,
            'tenant' => in_array($value, ['hosted', 'self-hosted'], true),
            default => false,
        };
        if ($allowed) {
            return $value;
        }
        return '[redacted]';
    }

    private static function appendProtected(string $path, string $line): bool
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) || str_contains($path, "\0")) {
            return false;
        }
        $directory = dirname($path);
        if (!is_dir($directory)) {
            $previousUmask = umask(0077);
            try {
                if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
                    return false;
                }
            } finally {
                umask($previousUmask);
            }
            if (!@chmod($directory, 0700)) {
                return false;
            }
        }
        if (!self::safeDirectoryChain($directory) || !is_writable($directory)) {
            return false;
        }

        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (is_array($stat) && (($stat['mode'] ?? 0) & 0170000) !== 0100000) {
            return false;
        }
        if ($stat === false) {
            $previousUmask = umask(0077);
            try {
                $handle = @fopen($path, 'x+b');
            } finally {
                umask($previousUmask);
            }
        } else {
            $handle = @fopen($path, 'ab');
        }
        if (!is_resource($handle) && $stat === false) {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000) {
                return false;
            }
            $handle = @fopen($path, 'ab');
        }
        if (!is_resource($handle)) {
            return false;
        }
        try {
            $openStat = fstat($handle);
            $pathStat = @lstat($path);
            if (!is_array($openStat)
                || !is_array($pathStat)
                || (($openStat['mode'] ?? 0) & 0170000) !== 0100000
                || (int) ($openStat['nlink'] ?? 0) !== 1
                || !self::safeOwner((int) ($openStat['uid'] ?? -1))
                || ($openStat['dev'] ?? null) !== ($pathStat['dev'] ?? null)
                || ($openStat['ino'] ?? null) !== ($pathStat['ino'] ?? null)) {
                return false;
            }
            if (!@chmod($path, 0600)) {
                return false;
            }
            clearstatcache(true, $path);
            $secured = @lstat($path);
            if (!is_array($secured) || (($secured['mode'] ?? 0) & 0777) !== 0600) {
                return false;
            }
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            try {
                return fwrite($handle, $line) === strlen($line);
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private static function safeDirectoryChain(string $directory): bool
    {
        $current = DIRECTORY_SEPARATOR;
        foreach (array_filter(explode(DIRECTORY_SEPARATOR, trim($directory, DIRECTORY_SEPARATOR)), 'strlen') as $component) {
            $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $component;
            $stat = @lstat($current);
            if (!is_array($stat)
                || (($stat['mode'] ?? 0) & 0170000) !== 0040000
                || !self::safeOwner((int) ($stat['uid'] ?? -1))
                || (((int) ($stat['mode'] ?? 0) & 0022) !== 0
                    && !((int) ($stat['uid'] ?? -1) === 0 && ((int) ($stat['mode'] ?? 0) & 01000) !== 0))) {
                return false;
            }
        }
        return true;
    }

    private static function safeOwner(int $owner): bool
    {
        $effective = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        return $owner === 0 || $owner === $effective;
    }
}
