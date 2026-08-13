<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Database;
use PDO;
use RuntimeException;

final class LoginThrottle
{
    private const WINDOW_SECONDS = 900;
    private const IDENTITY_LIMIT = 5;
    private const NETWORK_LIMIT = 50;

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function assertAllowed(string $email): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);
        $identity = $this->identityHash($email);
        $network = $this->networkHash();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM auth_login_attempts WHERE identity_hash = ? AND succeeded = 0 AND attempted_at >= ?');
        $stmt->execute([$identity, $cutoff]);
        $identityFailures = (int) $stmt->fetchColumn();
        $networkFailures = 0;
        if ($network !== '') {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM auth_login_attempts WHERE network_hash = ? AND succeeded = 0 AND attempted_at >= ?');
            $stmt->execute([$network, $cutoff]);
            $networkFailures = (int) $stmt->fetchColumn();
        }
        if ($identityFailures >= self::IDENTITY_LIMIT || $networkFailures >= self::NETWORK_LIMIT) {
            throw new RuntimeException('Too many sign-in attempts. Try again in a few minutes.');
        }
    }

    public function recordFailure(string $email): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_login_attempts (identity_hash, network_hash, succeeded, attempted_at) VALUES (?, ?, 0, ?)'
        );
        $stmt->execute([$this->identityHash($email), $this->networkHash(), cpe_now()]);
        $this->cleanup();
    }

    public function recordSuccess(string $email): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM auth_login_attempts WHERE identity_hash = ?');
        $stmt->execute([$this->identityHash($email)]);
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM auth_login_attempts WHERE attempted_at < ?');
        $stmt->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
    }

    private function identityHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    private function networkHash(): string
    {
        $address = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $address === '' ? '' : hash('sha256', $address);
    }
}
