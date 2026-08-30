<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Core\Http\UserVisibleException;
use RuntimeException;

/**
 * External API root keys and purpose-separated derived keys.
 *
 * @internal This is an Engine-local security primitive, not a public API.
 */
final class ApiKeyring
{
    public const KEYRING_ENV = 'CPE_API_KEYRING';
    public const ACTIVE_VERSION_ENV = 'CPE_API_ACTIVE_KEY_VERSION';

    private const MAX_KEYS = 8;
    private const MAX_ENCODED_KEYRING_BYTES = 1024;
    private const PURPOSES = ['token', 'cursor', 'source', 'command'];

    /** @param array<string, string> $keys Raw 32-byte root keys indexed by version. */
    public function __construct(
        private readonly array $keys,
        private readonly string $activeVersion,
    ) {
        if (count($keys) < 1 || count($keys) > self::MAX_KEYS) {
            throw new UserVisibleException(
                'API_KEYRING_INVALID',
                'The API keyring must contain one to eight keys.',
            );
        }
        if (!$this->validVersion($activeVersion) || !isset($keys[$activeVersion])) {
            throw new UserVisibleException(
                'API_ACTIVE_KEY_INVALID',
                'Configure a valid active API key version.',
            );
        }
        foreach ($keys as $version => $key) {
            if (!$this->validVersion((string) $version) || !is_string($key) || strlen($key) !== 32) {
                throw new UserVisibleException(
                    'API_KEYRING_INVALID',
                    'API keyring entries must use a short version and a 32-byte key.',
                );
            }
        }
    }

    public static function fromEnvironment(): self
    {
        $encoded = getenv(self::KEYRING_ENV);
        $active = getenv(self::ACTIVE_VERSION_ENV);
        if (!is_string($encoded) || trim($encoded) === '' || !is_string($active) || trim($active) === '') {
            throw new UserVisibleException(
                'API_KEYRING_REQUIRED',
                'Configure the external API keyring before creating or rotating an API token.',
            );
        }
        if (strlen($encoded) > self::MAX_ENCODED_KEYRING_BYTES) {
            throw new UserVisibleException('API_KEYRING_INVALID', 'The API keyring value is too large.');
        }

        $keys = [];
        foreach (explode(';', trim($encoded)) as $entry) {
            if (substr_count($entry, '=') !== 1) {
                throw new UserVisibleException('API_KEYRING_INVALID', 'The API keyring format is invalid.');
            }
            [$version, $material] = explode('=', $entry, 2);
            if ($version === '' || isset($keys[$version])) {
                throw new UserVisibleException('API_KEYRING_INVALID', 'API keyring versions must be unique.');
            }
            $key = self::base64UrlDecode($material);
            if ($key === null || strlen($key) !== 32 || !hash_equals(self::base64UrlEncode($key), $material)) {
                throw new UserVisibleException(
                    'API_KEYRING_INVALID',
                    'Each API root key must be exactly 32 bytes encoded as canonical unpadded base64url.',
                );
            }
            $keys[$version] = $key;
        }
        return new self($keys, trim($active));
    }

    /** @return array{present: bool, active_version: string, versions: list<string>, issue: string} */
    public static function environmentStatus(): array
    {
        try {
            $keyring = self::fromEnvironment();
            return [
                'present' => true,
                'active_version' => $keyring->activeVersion(),
                'versions' => $keyring->versions(),
                'issue' => '',
            ];
        } catch (\Throwable $failure) {
            return [
                'present' => false,
                'active_version' => '',
                'versions' => [],
                'issue' => $failure instanceof UserVisibleException
                    ? $failure->publicMessage()
                    : 'The API keyring is unavailable.',
            ];
        }
    }

    public function activeVersion(): string
    {
        return $this->activeVersion;
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_keys($this->keys);
    }

    public function hasVersion(string $version): bool
    {
        return isset($this->keys[$version]);
    }

    public function tokenVerifier(
        string $secretBytes,
        string $institutionPublicId,
        string $lookupId,
        string $version,
    ): string {
        if (strlen($secretBytes) !== 32 || preg_match('/^[a-f0-9]{32}$/D', $lookupId) !== 1) {
            throw new RuntimeException('API token verification input is invalid.');
        }
        $key = $this->derivedKey('token', $institutionPublicId, $version);
        return hash_hmac(
            'sha256',
            implode("\0", ['cpe-api-token-verifier', '1', $institutionPublicId, $lookupId, $version])
                . "\0" . $secretBytes,
            $key,
            true,
        );
    }

    public function cursorMac(string $payload, string $institutionPublicId, ?string $version = null): string
    {
        $version ??= $this->activeVersion;
        return hash_hmac(
            'sha256',
            implode("\0", ['cpe-api-cursor', '1', $institutionPublicId, $version]) . "\0" . $payload,
            $this->derivedKey('cursor', $institutionPublicId, $version),
            true,
        );
    }

    public function sourceFingerprint(string $source, string $institutionPublicId, ?string $version = null): string
    {
        $version ??= $this->activeVersion;
        return hash_hmac(
            'sha256',
            implode("\0", ['cpe-api-source', '1', $institutionPublicId, $version]) . "\0" . $source,
            $this->derivedKey('source', $institutionPublicId, $version),
        );
    }

    public function commandIdempotencyHash(
        string $clearKey,
        string $institutionPublicId,
        string $operation,
        ?string $version = null,
    ): string {
        if (preg_match('/\A[a-f0-9]{32,64}\z/D', $clearKey) !== 1
            || $operation !== 'application.transition') {
            throw new RuntimeException('API command idempotency input is invalid.');
        }
        $version ??= $this->activeVersion;
        return hash_hmac(
            'sha256',
            implode("\0", [
                'cpe-api-command-idempotency-key',
                '1',
                $institutionPublicId,
                $operation,
                $version,
            ]) . "\0" . $clearKey,
            $this->derivedKey('command', $institutionPublicId, $version),
        );
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }
        $remainder = strlen($value) % 4;
        if ($remainder === 1) {
            return null;
        }
        $decoded = base64_decode(
            strtr($value, '-_', '+/') . str_repeat('=', (4 - $remainder) % 4),
            true,
        );
        return is_string($decoded) ? $decoded : null;
    }

    private function derivedKey(string $purpose, string $institutionPublicId, string $version): string
    {
        if (!in_array($purpose, self::PURPOSES, true)
            || preg_match('/^(?:inst|tenant)_[a-f0-9]{32}$/D', $institutionPublicId) !== 1
            || !$this->validVersion($version)
            || !isset($this->keys[$version])) {
            throw new ApiAuthenticationUnavailable('A required API key version is unavailable.');
        }
        $derived = hash_hkdf(
            'sha256',
            $this->keys[$version],
            32,
            implode('|', ['cpe-api', $purpose, 'v1', $institutionPublicId]),
            implode('|', ['cpe-api-root', 'v1', $version]),
        );
        if (!is_string($derived) || strlen($derived) !== 32) {
            throw new RuntimeException('API key derivation failed.');
        }
        return $derived;
    }

    private function validVersion(string $version): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', $version) === 1;
    }
}
