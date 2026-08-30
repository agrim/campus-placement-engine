<?php

declare(strict_types=1);

namespace App\Api\Http;

final class ApiHttpRequest
{
    private const MAX_REQUEST_TARGET_BYTES = 4096;
    private const MAX_QUERY_BYTES = 2048;
    private const MAX_HEADER_COUNT = 64;
    private const MAX_HEADER_BYTES = 4096;
    private const MAX_TOTAL_HEADER_BYTES = 16384;
    private const MAX_QUERY_PAIRS = 8;

    /** @param array<string, mixed> $server */
    private function __construct(
        private readonly array $server,
        private readonly string $requestId,
        private readonly string $method,
        private readonly string $path,
        private readonly string $queryString,
    ) {
    }

    /** @param array<string, mixed> $server */
    public static function fromServer(array $server, string $requestId): self
    {
        $methodValue = $server['REQUEST_METHOD'] ?? 'GET';
        $method = is_string($methodValue) ? strtoupper($methodValue) : 'INVALID';
        $requestUri = $server['REQUEST_URI'] ?? '/';
        if (!is_string($requestUri)
            || strlen($requestUri) > self::MAX_REQUEST_TARGET_BYTES) {
            throw self::requestTargetTooLarge();
        }
        $path = explode('?', $requestUri, 2)[0];
        if ($path === '' || $path[0] !== '/' || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw self::invalidRequest();
        }
        $queryValue = $server['QUERY_STRING'] ?? '';
        if (!is_string($queryValue)) {
            throw self::invalidRequest();
        }
        if (strlen($queryValue) > self::MAX_QUERY_BYTES) {
            throw self::requestTargetTooLarge();
        }
        self::assertHeaderBounds($server);
        return new self($server, $requestId, $method, $path, $queryValue);
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isHead(): bool
    {
        return $this->method === 'HEAD';
    }

    public function bearerToken(): string
    {
        $authorization = $this->server['HTTP_AUTHORIZATION'] ?? null;
        if (!is_string($authorization)
            || preg_match('/\ABearer ([^\s]{1,256})\z/D', $authorization, $matches) !== 1) {
            return '';
        }
        return $matches[1];
    }

    public function source(): string
    {
        return self::sourceFromServer($this->server);
    }

    /** @param array<string, mixed> $server */
    public static function sourceFromServer(array $server): string
    {
        $source = $server['REMOTE_ADDR'] ?? '';
        return is_string($source)
            && strlen($source) <= 45
            && filter_var($source, FILTER_VALIDATE_IP) !== false
                ? $source
                : 'unknown';
    }

    public function ifNoneMatch(): string
    {
        $value = $this->server['HTTP_IF_NONE_MATCH'] ?? '';
        return is_string($value) && strlen($value) <= self::MAX_HEADER_BYTES ? $value : '';
    }

    public function ifMatch(): string
    {
        $value = $this->server['HTTP_IF_MATCH'] ?? '';
        return is_string($value) && strlen($value) <= self::MAX_HEADER_BYTES ? trim($value) : '';
    }

    public function idempotencyKey(): string
    {
        $value = $this->server['HTTP_IDEMPOTENCY_KEY'] ?? '';
        return is_string($value) && strlen($value) <= self::MAX_HEADER_BYTES ? trim($value) : '';
    }

    public function contentType(): string
    {
        $value = $this->server['CONTENT_TYPE'] ?? '';
        return is_string($value) && strlen($value) <= self::MAX_HEADER_BYTES ? $value : '';
    }

    /** Read one bounded required request body; production callers omit the override. */
    public function requiredBody(int $maxBytes, ?string $bodyOverride = null): string
    {
        if ($maxBytes < 1 || $maxBytes > 1048576) {
            throw new \RuntimeException('API request body boundary is invalid.');
        }
        $contentLength = $this->server['CONTENT_LENGTH'] ?? '';
        if ($contentLength !== ''
            && (!is_string($contentLength) || preg_match('/\A[0-9]{1,10}\z/D', $contentLength) !== 1)) {
            throw self::invalidBody();
        }
        if ($contentLength !== '' && (int) $contentLength > $maxBytes) {
            throw self::payloadTooLarge();
        }
        $transferEncoding = $this->server['HTTP_TRANSFER_ENCODING'] ?? '';
        if (!is_string($transferEncoding) || trim($transferEncoding) !== '') {
            throw self::invalidBody();
        }

        if ($bodyOverride !== null) {
            $body = $bodyOverride;
        } else {
            $input = fopen('php://input', 'rb');
            if (!is_resource($input)) {
                throw self::invalidBody();
            }
            try {
                $body = stream_get_contents($input, $maxBytes + 1);
            } finally {
                fclose($input);
            }
            if (!is_string($body)) {
                throw self::invalidBody();
            }
        }
        if (strlen($body) > $maxBytes) {
            throw self::payloadTooLarge();
        }
        if ($body === '') {
            throw new ApiHttpException(
                400,
                'request_body_required',
                'A JSON request body is required.',
                'BODY_REQUIRED',
            );
        }
        if ($contentLength !== '' && (int) $contentLength !== strlen($body)) {
            throw self::invalidBody();
        }
        return $body;
    }

    public function assertNoBody(): void
    {
        $contentLength = $this->server['CONTENT_LENGTH'] ?? '';
        if ($contentLength !== ''
            && (!is_string($contentLength)
                || preg_match('/\A[0-9]{1,10}\z/D', $contentLength) !== 1
                || (int) $contentLength !== 0)) {
            throw self::requestBodyRejected();
        }
        $transferEncoding = $this->server['HTTP_TRANSFER_ENCODING'] ?? '';
        if (is_string($transferEncoding) && trim($transferEncoding) !== '') {
            throw self::requestBodyRejected();
        }
        $input = fopen('php://input', 'rb');
        if (is_resource($input)) {
            try {
                $sentinel = fread($input, 1);
                if (is_string($sentinel) && $sentinel !== '') {
                    throw self::requestBodyRejected();
                }
            } finally {
                fclose($input);
            }
        }
    }

    /**
     * @param list<string> $allowed
     * @return array<string, string>
     */
    public function queryParameters(array $allowed): array
    {
        if ($this->queryString === '') {
            return [];
        }
        $pairs = explode('&', $this->queryString);
        if (count($pairs) > self::MAX_QUERY_PAIRS) {
            throw self::invalidQuery();
        }
        $parameters = [];
        foreach ($pairs as $pair) {
            if ($pair === '' || substr_count($pair, '=') > 1) {
                throw self::invalidQuery();
            }
            [$encodedName, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            if (preg_match('/%(?![A-Fa-f0-9]{2})/', $encodedName . $encodedValue) === 1) {
                throw self::invalidQuery();
            }
            $name = rawurldecode($encodedName);
            $value = rawurldecode($encodedValue);
            if (!in_array($name, $allowed, true)
                || isset($parameters[$name])
                || str_contains($name, '[')
                || str_contains($name, ']')
                || strlen($name) > 32
                || strlen($value) > 1536) {
                throw self::invalidQuery();
            }
            $parameters[$name] = $value;
        }
        return $parameters;
    }

    /** @param array<string, mixed> $server */
    private static function assertHeaderBounds(array $server): void
    {
        $count = 0;
        $total = 0;
        foreach ($server as $name => $value) {
            if (!is_string($name)
                || (!str_starts_with($name, 'HTTP_')
                    && !in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true))) {
                continue;
            }
            $count++;
            if (!is_string($value)
                || strlen($name) + strlen($value) > self::MAX_HEADER_BYTES) {
                throw self::headersTooLarge();
            }
            $total += strlen($name) + strlen($value);
        }
        if ($count > self::MAX_HEADER_COUNT || $total > self::MAX_TOTAL_HEADER_BYTES) {
            throw self::headersTooLarge();
        }
    }

    private static function invalidRequest(): ApiHttpException
    {
        return new ApiHttpException(400, 'invalid_request', 'The API request is invalid.', 'REQUEST_INVALID');
    }

    private static function invalidQuery(): ApiHttpException
    {
        return new ApiHttpException(400, 'invalid_query', 'The API query is invalid.', 'QUERY_INVALID');
    }

    private static function requestBodyRejected(): ApiHttpException
    {
        return new ApiHttpException(400, 'request_body_not_allowed', 'Request bodies are not allowed.', 'BODY_NOT_ALLOWED');
    }

    private static function invalidBody(): ApiHttpException
    {
        return new ApiHttpException(400, 'invalid_request_body', 'The API request body is invalid.', 'BODY_INVALID');
    }

    private static function payloadTooLarge(): ApiHttpException
    {
        return new ApiHttpException(413, 'payload_too_large', 'The API request body is too large.', 'BODY_TOO_LARGE');
    }

    private static function requestTargetTooLarge(): ApiHttpException
    {
        return new ApiHttpException(414, 'request_target_too_large', 'The API request target is too large.', 'TARGET_TOO_LARGE');
    }

    private static function headersTooLarge(): ApiHttpException
    {
        return new ApiHttpException(431, 'request_headers_too_large', 'The API request headers are too large.', 'HEADERS_TOO_LARGE');
    }
}
