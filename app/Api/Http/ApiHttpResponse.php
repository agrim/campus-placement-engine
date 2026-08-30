<?php

declare(strict_types=1);

namespace App\Api\Http;

use RuntimeException;

final class ApiHttpResponse
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public static function send(
        int $status,
        array $payload,
        string $requestId,
        bool $head = false,
        array $headers = [],
    ): void {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!is_string($body)) {
            throw new RuntimeException('API response encoding failed.');
        }
        self::emit($status, $body, $requestId, $head, $headers);
    }

    /** Send already-validated canonical command bytes for exact replay. */
    public static function sendEncoded(
        int $status,
        string $body,
        string $requestId,
        bool $head = false,
        array $headers = [],
    ): void {
        try {
            $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new RuntimeException('Encoded API response is invalid.', 0, $failure);
        }
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded) || strlen($body) > 16384) {
            throw new RuntimeException('Encoded API response is invalid.');
        }
        self::emit($status, $body, $requestId, $head, $headers);
    }

    /** @param array<string, string> $headers */
    private static function emit(
        int $status,
        string $body,
        string $requestId,
        bool $head,
        array $headers,
    ): void {
        if (!headers_sent()) {
            header_remove('Set-Cookie');
            header_remove('Location');
            foreach ([
                'Access-Control-Allow-Origin',
                'Access-Control-Allow-Credentials',
                'Access-Control-Allow-Headers',
                'Access-Control-Allow-Methods',
            ] as $corsHeader) {
                header_remove($corsHeader);
            }
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8', true);
            header('Cache-Control: no-store', true);
            header('X-Content-Type-Options: nosniff', true);
            header('X-Request-ID: ' . $requestId, true);
            header('Vary: Authorization', true);
            foreach ($headers as $name => $value) {
                if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $name) !== 1
                    || preg_match('/[\r\n]/', $value) === 1) {
                    throw new RuntimeException('API response header is invalid.');
                }
                header($name . ': ' . $value, true);
            }
            header('Content-Length: ' . strlen($body), true);
        }
        if (!$head) {
            echo $body;
        }
    }

    /** @param array<string, string> $headers */
    public static function error(
        int $status,
        string $code,
        string $message,
        string $requestId,
        bool $head = false,
        array $headers = [],
        ?string $incidentId = null,
    ): void {
        $error = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];
        if ($incidentId !== null) {
            $error['incident_id'] = $incidentId;
        }
        self::send($status, ['error' => $error], $requestId, $head, $headers);
    }

    /** @param array<string, string> $headers */
    public static function notModified(string $requestId, array $headers): void
    {
        if (!headers_sent()) {
            header_remove('Set-Cookie');
            header_remove('Location');
            foreach ([
                'Access-Control-Allow-Origin',
                'Access-Control-Allow-Credentials',
                'Access-Control-Allow-Headers',
                'Access-Control-Allow-Methods',
            ] as $corsHeader) {
                header_remove($corsHeader);
            }
            http_response_code(304);
            header('Cache-Control: no-store', true);
            header('X-Content-Type-Options: nosniff', true);
            header('X-Request-ID: ' . $requestId, true);
            header('Vary: Authorization', true);
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            header('Content-Length: 0', true);
        }
    }
}
