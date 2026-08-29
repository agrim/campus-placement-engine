<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Core\Http\UserVisibleException;
use RuntimeException;

/** Authenticated encryption for institution-local webhook signing secrets. */
final class WebhookSecretCipher
{
    public const KEYRING_ENV = 'CPE_WEBHOOK_ENCRYPTION_KEYS';
    public const ACTIVE_VERSION_ENV = 'CPE_WEBHOOK_ACTIVE_KEY_VERSION';

    /** @param array<string, string> $keys Raw 32-byte keys indexed by version. */
    public function __construct(
        private readonly array $keys,
        private readonly string $activeVersion,
    ) {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new UserVisibleException(
                'WEBHOOK_ENCRYPTION_UNAVAILABLE',
                'Webhook signing requires the OpenSSL extension when an integration is configured.',
            );
        }
        if (preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', $activeVersion) !== 1
            || !isset($keys[$activeVersion])) {
            throw new UserVisibleException(
                'WEBHOOK_ACTIVE_KEY_INVALID',
                'Configure a valid active webhook encryption key version.',
            );
        }
        foreach ($keys as $version => $key) {
            if (preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', $version) !== 1 || strlen($key) !== 32) {
                throw new UserVisibleException(
                    'WEBHOOK_KEYRING_INVALID',
                    'Webhook encryption keyring entries must use a short version and a 32-byte key.',
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
                'WEBHOOK_KEYRING_REQUIRED',
                'Configure the external webhook encryption keyring before generating a signing secret.',
            );
        }

        $keys = [];
        foreach (explode(';', trim($encoded)) as $entry) {
            if (substr_count($entry, '=') !== 1) {
                throw new UserVisibleException('WEBHOOK_KEYRING_INVALID', 'Webhook encryption keyring format is invalid.');
            }
            [$version, $material] = explode('=', $entry, 2);
            if ($version === '' || isset($keys[$version])) {
                throw new UserVisibleException('WEBHOOK_KEYRING_INVALID', 'Webhook encryption keyring versions must be unique.');
            }
            $key = self::base64UrlDecode($material);
            if ($key === null || strlen($key) !== 32 || !hash_equals(self::base64UrlEncode($key), $material)) {
                throw new UserVisibleException(
                    'WEBHOOK_KEYRING_INVALID',
                    'Each webhook encryption key must be 32 bytes encoded as unpadded base64url.',
                );
            }
            $keys[$version] = $key;
        }
        if (count($keys) < 1 || count($keys) > 8) {
            throw new UserVisibleException('WEBHOOK_KEYRING_INVALID', 'Webhook encryption keyring must contain one to eight keys.');
        }
        return new self($keys, trim($active));
    }

    /** @return array{present: bool, active_version: string, versions: list<string>, issue: string} */
    public static function environmentStatus(): array
    {
        try {
            $cipher = self::fromEnvironment();
            return [
                'present' => true,
                'active_version' => $cipher->activeVersion,
                'versions' => array_keys($cipher->keys),
                'issue' => '',
            ];
        } catch (\Throwable $failure) {
            return [
                'present' => false,
                'active_version' => '',
                'versions' => [],
                'issue' => $failure instanceof UserVisibleException
                    ? $failure->publicMessage()
                    : 'Webhook encryption keyring is unavailable.',
            ];
        }
    }

    public static function generateSigningSecret(): string
    {
        return 'whsec_' . self::base64UrlEncode(random_bytes(32));
    }

    /** @return array{ciphertext: string, nonce: string, tag: string, key_version: string} */
    public function encrypt(
        string $secret,
        string $institutionPublicId,
        string $subscriptionPublicId,
    ): array {
        if (preg_match('/^whsec_[A-Za-z0-9_-]{43}$/D', $secret) !== 1) {
            throw new RuntimeException('Webhook signing secret format is invalid.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            $this->keys[$this->activeVersion],
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->associatedData($institutionPublicId, $subscriptionPublicId, $this->activeVersion),
            16,
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Webhook signing secret encryption failed.');
        }
        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'key_version' => $this->activeVersion,
        ];
    }

    /** @param array{ciphertext: mixed, nonce: mixed, tag: mixed, key_version: mixed} $encrypted */
    public function decrypt(
        array $encrypted,
        string $institutionPublicId,
        string $subscriptionPublicId,
    ): string {
        $version = (string) ($encrypted['key_version'] ?? '');
        if (!isset($this->keys[$version])) {
            throw new RuntimeException('Webhook encryption key version is unavailable.');
        }
        $ciphertext = base64_decode((string) ($encrypted['ciphertext'] ?? ''), true);
        $nonce = base64_decode((string) ($encrypted['nonce'] ?? ''), true);
        $tag = base64_decode((string) ($encrypted['tag'] ?? ''), true);
        if (!is_string($ciphertext) || !is_string($nonce) || !is_string($tag)
            || strlen($nonce) !== 12 || strlen($tag) !== 16) {
            throw new RuntimeException('Webhook encrypted secret metadata is invalid.');
        }
        $secret = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->keys[$version],
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->associatedData($institutionPublicId, $subscriptionPublicId, $version),
        );
        if (!is_string($secret) || preg_match('/^whsec_[A-Za-z0-9_-]{43}$/D', $secret) !== 1) {
            throw new RuntimeException('Webhook signing secret authentication failed.');
        }
        return $secret;
    }

    private function associatedData(string $institutionPublicId, string $subscriptionPublicId, string $version): string
    {
        if (preg_match('/^(?:inst|tenant)_[a-f0-9]{32}$/D', $institutionPublicId) !== 1
            || preg_match('/^whsub_[a-f0-9]{32}$/D', $subscriptionPublicId) !== 1) {
            throw new RuntimeException('Webhook secret binding identity is invalid.');
        }
        return implode('|', ['cpe-webhook-secret', '1', $institutionPublicId, $subscriptionPublicId, $version]);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }
        $remainder = strlen($value) % 4;
        if ($remainder === 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - $remainder) % 4), true);
        return is_string($decoded) ? $decoded : null;
    }
}
