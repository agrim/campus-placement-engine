<?php

declare(strict_types=1);

namespace App\Security;

use App\Hosted\HostedContext;
use App\Support\Auth;
use App\Support\Database;
use PDO;
use RuntimeException;

final class ExternalIdentityService
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public static function enabled(): bool
    {
        return self::truthy(getenv('CPE_SSO_ENABLED'));
    }

    public function authenticateRequest(): array
    {
        if (!self::enabled()) {
            throw new RuntimeException('Institutional SSO is not enabled.');
        }
        $provider = $this->provider((string) (getenv('CPE_SSO_PROVIDER_KEY') ?: 'oidc_proxy'));
        $secret = (string) (getenv('CPE_SSO_SHARED_SECRET') ?: '');
        if (strlen($secret) < 32) {
            throw new RuntimeException('Institutional SSO is not configured safely.');
        }
        $subject = $this->header('HTTP_X_CPE_SSO_SUBJECT', 255, 'SSO subject');
        $email = strtolower($this->header('HTTP_X_CPE_SSO_EMAIL', 254, 'SSO email'));
        $name = $this->header('HTTP_X_CPE_SSO_NAME', 120, 'SSO name', true);
        $timestamp = $this->header('HTTP_X_CPE_SSO_TIMESTAMP', 20, 'SSO timestamp');
        $nonce = $this->header('HTTP_X_CPE_SSO_NONCE', 128, 'SSO nonce');
        $signature = strtolower($this->header('HTTP_X_CPE_SSO_SIGNATURE', 64, 'SSO signature'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)
            || preg_match('/^[0-9]{10}$/', $timestamp) !== 1
            || abs(time() - (int) $timestamp) > 120
            || preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            throw new RuntimeException('Institutional SSO assertion is invalid or expired.');
        }
        $tenant = $this->tenantIdentity();
        $canonical = implode("\n", [$provider, $subject, $email, $name, $timestamp, $nonce, $tenant]);
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Institutional SSO assertion signature is invalid.');
        }
        $this->consumeNonce($nonce, (int) $timestamp);

        $stmt = $this->pdo->prepare(
            'SELECT u.* FROM external_identities ei JOIN users u ON u.id = ei.user_id
             WHERE ei.provider_key = ? AND ei.subject = ? AND u.active = 1'
        );
        $stmt->execute([$provider, $subject]);
        $user = $stmt->fetch();
        if (!$user && self::truthy(getenv('CPE_SSO_AUTO_LINK_EMAIL'))) {
            $stmt = $this->pdo->prepare('SELECT * FROM users WHERE lower(email) = lower(?) AND active = 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $this->linkUser($provider, $subject, (int) $user['id'], $email);
            }
        }
        if (!$user) {
            throw new RuntimeException('Institutional identity is not linked to an active portal user.');
        }
        Auth::loginById((int) $user['id'], 'sso:' . $provider);
        Auth::audit((int) $user['id'], 'login.sso', 'user', (int) $user['id'], 'Successful institutional SSO login');
        return $user;
    }

    public function link(string $provider, string $subject, string $userEmail): array
    {
        $provider = $this->provider($provider);
        $subject = $this->text($subject, 255, 'External identity subject');
        $userEmail = strtolower(trim($userEmail));
        $stmt = $this->pdo->prepare('SELECT id, email FROM users WHERE lower(email) = lower(?)');
        $stmt->execute([$userEmail]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new RuntimeException('Portal user was not found for external identity link.');
        }
        $this->linkUser($provider, $subject, (int) $user['id'], (string) $user['email']);
        return ['provider' => $provider, 'subject' => $subject, 'user_id' => (int) $user['id'], 'email' => (string) $user['email']];
    }

    private function linkUser(string $provider, string $subject, int $userId, string $email): void
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM external_identities WHERE provider_key = ? AND subject = ?');
        $stmt->execute([$provider, $subject]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            if ((int) $existing !== $userId) {
                throw new RuntimeException('External identity is already linked to another portal user.');
            }
            return;
        }
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO external_identities (provider_key, subject, user_id, linked_email, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$provider, $subject, $userId, strtolower($email), $now, $now]);
    }

    private function consumeNonce(string $nonce, int $timestamp): void
    {
        $now = cpe_now();
        $this->pdo->prepare('DELETE FROM auth_sso_nonces WHERE expires_at <= ?')->execute([$now]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_sso_nonces (nonce_hash, expires_at, created_at) VALUES (?, ?, ?)
             ON CONFLICT(nonce_hash) DO NOTHING'
        );
        $stmt->execute([
            hash('sha256', $nonce),
            gmdate('Y-m-d H:i:s', $timestamp + 300),
            $now,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Institutional SSO assertion has already been used.');
        }
    }

    private function tenantIdentity(): string
    {
        if (HostedContext::isActive()) {
            return HostedContext::current()->publicId();
        }
        $value = $this->pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn();
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Institutional SSO requires an institution context.');
        }
        return $value;
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $provider) !== 1) {
            throw new RuntimeException('External identity provider key is invalid.');
        }
        return $provider;
    }

    private function header(string $key, int $maxLength, string $label, bool $allowEmpty = false): string
    {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        if ((!$allowEmpty && $value === '') || strlen($value) > $maxLength || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException($label . ' is missing or invalid.');
        }
        return $value;
    }

    private function text(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new RuntimeException($label . ' is missing or invalid.');
        }
        return $value;
    }

    private static function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
