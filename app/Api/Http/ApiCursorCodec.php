<?php

declare(strict_types=1);

namespace App\Api\Http;

use App\Api\Security\ApiAuthenticationUnavailable;
use App\Api\Security\ApiKeyring;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class ApiCursorCodec
{
    private const PREFIX = 'cpe_cursor_v1';
    private const MAX_CURSOR_BYTES = 2048;
    private const MAX_PAYLOAD_BYTES = 1024;

    public function __construct(private readonly ApiKeyring $keyring)
    {
    }

    /**
     * @param array{updated_at: string, id: string} $snapshot
     * @param array{updated_at: string, id: string} $last
     */
    public function encode(
        string $institutionPublicId,
        string $route,
        string $resource,
        ?string $updatedAfter,
        array $snapshot,
        array $last,
    ): string {
        $this->assertBinding($institutionPublicId, $route, $resource);
        $this->assertTuple($snapshot, $resource);
        $this->assertTuple($last, $resource);
        if ($this->compareTuples($last, $snapshot) > 0) {
            throw new \RuntimeException('API cursor last tuple exceeds its snapshot.');
        }
        $version = $this->keyring->activeVersion();
        $payload = [
            'version' => 1,
            'key_version' => $version,
            'institution_id' => $institutionPublicId,
            'route' => $route,
            'resource' => $resource,
            'filters' => ['updated_after' => $updatedAfter],
            'snapshot' => $snapshot,
            'last' => $last,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!is_string($json) || strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new \RuntimeException('API cursor payload is too large.');
        }
        $mac = $this->keyring->cursorMac($json, $institutionPublicId, $version);
        return self::PREFIX . '.' . ApiKeyring::base64UrlEncode($json) . '.' . ApiKeyring::base64UrlEncode($mac);
    }

    /**
     * @return array{
     *   updated_after: ?string,
     *   snapshot: array{updated_at: string, id: string},
     *   last: array{updated_at: string, id: string}
     * }
     */
    public function decode(
        string $cursor,
        string $institutionPublicId,
        string $route,
        string $resource,
    ): array {
        if ($cursor === '' || strlen($cursor) > self::MAX_CURSOR_BYTES) {
            throw $this->invalid();
        }
        $parts = explode('.', $cursor);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            throw $this->invalid();
        }
        $json = $this->canonicalDecode($parts[1], self::MAX_PAYLOAD_BYTES);
        $mac = $this->canonicalDecode($parts[2], 32);
        if ($json === null || $mac === null || strlen($mac) !== 32) {
            throw $this->invalid();
        }
        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalid();
        }
        if (!is_array($payload)
            || array_keys($payload) !== [
                'version',
                'key_version',
                'institution_id',
                'route',
                'resource',
                'filters',
                'snapshot',
                'last',
            ]) {
            throw $this->invalid();
        }
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!is_string($canonical) || !hash_equals($canonical, $json)) {
            throw $this->invalid();
        }
        $keyVersion = $payload['key_version'] ?? null;
        if (!is_string($keyVersion)
            || preg_match('/\A[A-Za-z0-9_.-]{1,32}\z/D', $keyVersion) !== 1) {
            throw $this->invalid();
        }
        if (!$this->keyring->hasVersion($keyVersion)) {
            throw new ApiAuthenticationUnavailable('A pagination cursor key version is unavailable.');
        }
        $expectedMac = $this->keyring->cursorMac($json, $institutionPublicId, $keyVersion);
        if (!hash_equals($expectedMac, $mac)) {
            throw $this->invalid();
        }
        if (($payload['version'] ?? null) !== 1
            || !is_string($payload['institution_id'] ?? null)
            || !hash_equals($institutionPublicId, $payload['institution_id'])
            || ($payload['route'] ?? null) !== $route
            || ($payload['resource'] ?? null) !== $resource) {
            throw $this->invalid();
        }
        $filters = $payload['filters'] ?? null;
        if (!is_array($filters)
            || array_keys($filters) !== ['updated_after']
            || (!is_string($filters['updated_after']) && $filters['updated_after'] !== null)) {
            throw $this->invalid();
        }
        if (is_string($filters['updated_after'])) {
            $this->assertApiTimestamp($filters['updated_after']);
        }
        $snapshot = $payload['snapshot'] ?? null;
        $last = $payload['last'] ?? null;
        if (!is_array($snapshot) || !is_array($last)) {
            throw $this->invalid();
        }
        try {
            $this->assertTuple($snapshot, $resource);
            $this->assertTuple($last, $resource);
        } catch (\Throwable) {
            throw $this->invalid();
        }
        if ($this->compareTuples($last, $snapshot) > 0) {
            throw $this->invalid();
        }
        return [
            'updated_after' => $filters['updated_after'],
            'snapshot' => $snapshot,
            'last' => $last,
        ];
    }

    private function assertBinding(string $institutionPublicId, string $route, string $resource): void
    {
        if (preg_match('/\A(?:inst|tenant)_[a-f0-9]{32}\z/D', $institutionPublicId) !== 1
            || !in_array($resource, ['opportunities', 'applications'], true)
            || $route !== 'api.v1.' . $resource . '.list') {
            throw new \RuntimeException('API cursor binding is invalid.');
        }
    }

    /** @param array<mixed> $tuple */
    private function assertTuple(array $tuple, string $resource): void
    {
        $prefix = $resource === 'opportunities' ? 'opportunity' : 'application';
        if (array_keys($tuple) !== ['updated_at', 'id']
            || !is_string($tuple['updated_at'])
            || !$this->validDatabaseTimestamp($tuple['updated_at'])
            || !is_string($tuple['id'])
            || preg_match('/\A' . $prefix . '_[a-f0-9]{32}\z/D', $tuple['id']) !== 1) {
            throw new \RuntimeException('API cursor tuple is invalid.');
        }
    }

    private function assertApiTimestamp(string $value): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw $this->invalid();
        }
    }

    private function validDatabaseTimestamp(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }

    /**
     * @param array{updated_at: string, id: string} $left
     * @param array{updated_at: string, id: string} $right
     */
    private function compareTuples(array $left, array $right): int
    {
        return [$left['updated_at'], $left['id']] <=> [$right['updated_at'], $right['id']];
    }

    private function canonicalDecode(string $value, int $maximumBytes): ?string
    {
        $decoded = ApiKeyring::base64UrlDecode($value);
        if (!is_string($decoded)
            || strlen($decoded) > $maximumBytes
            || !hash_equals(ApiKeyring::base64UrlEncode($decoded), $value)) {
            return null;
        }
        return $decoded;
    }

    private function invalid(): ApiHttpException
    {
        return new ApiHttpException(400, 'invalid_cursor', 'The pagination cursor is invalid.', 'CURSOR_INVALID');
    }
}
