<?php

declare(strict_types=1);

namespace App\Support;

final class StructuredLogger
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        self::$requestId ??= 'req_' . bin2hex(random_bytes(12));
        return self::$requestId;
    }

    public static function log(string $level, string $event, array $context = []): void
    {
        $record = [
            'timestamp' => gmdate('c'),
            'level' => strtolower($level),
            'event' => $event,
            'request_id' => self::requestId(),
            'context' => self::sanitize($context),
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        $path = trim((string) (getenv('CPE_LOG_PATH') ?: ''));
        if ($path !== '') {
            $directory = dirname($path);
            if ((is_dir($directory) || mkdir($directory, 0775, true))
                && file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false) {
                return;
            }
        }
        error_log(rtrim($line));
    }

    private static function sanitize(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $name = (string) $key;
            if (preg_match('/password|secret|token|authorization|cookie|payload/i', $name)) {
                $clean[$name] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $clean[$name] = self::sanitize($value);
            } elseif (is_string($value)) {
                $clean[$name] = self::sanitizeString($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$name] = $value;
            } else {
                $clean[$name] = get_debug_type($value);
            }
        }
        return $clean;
    }

    private static function sanitizeString(string $value): string
    {
        $value = preg_replace('/[\r\n]+/', ' ', $value) ?? '';
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i', 'Bearer [redacted]', $value) ?? '';
        $value = preg_replace('/\b(password|secret|token|api_key)=([^\s&]+)/i', '$1=[redacted]', $value) ?? '';
        $value = preg_replace('~(postgres(?:ql)?://[^:\s/]+:)[^@\s]+@~i', '$1[redacted]@', $value) ?? '';
        return mb_substr($value, 0, 1000);
    }
}
