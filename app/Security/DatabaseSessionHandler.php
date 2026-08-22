<?php

declare(strict_types=1);

namespace App\Security;

use PDO;
use RuntimeException;
use SessionHandlerInterface;

final class DatabaseSessionHandler implements SessionHandlerInterface
{
    private string $activeKey = '';
    private string $lockToken = '';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $lifetime,
    ) {
    }

    public function __destruct()
    {
        try {
            $this->releaseLock();
        } catch (\Throwable) {
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->releaseLock();
        return true;
    }

    public function read(string $id): string|false
    {
        $this->acquireLock($id);
        $stmt = $this->pdo->prepare('SELECT payload FROM web_sessions WHERE session_key = ? AND expires_at > ?');
        $stmt->execute([$this->key($id), cpe_now()]);
        $payload = $stmt->fetchColumn();
        return $payload === false ? '' : (string) $payload;
    }

    public function write(string $id, string $data): bool
    {
        $this->acquireLock($id);
        $now = cpe_now();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(300, $this->lifetime));
        $stmt = $this->pdo->prepare(
            'UPDATE web_sessions SET payload = ?, expires_at = ?, updated_at = ?, locked_at = ?
             WHERE session_key = ? AND lock_token = ?'
        );
        $stmt->execute([$data, $expiresAt, $now, $now, $this->activeKey, $this->lockToken]);
        return $stmt->rowCount() === 1;
    }

    public function destroy(string $id): bool
    {
        $this->acquireLock($id);
        $stmt = $this->pdo->prepare('DELETE FROM web_sessions WHERE session_key = ? AND lock_token = ?');
        $result = $stmt->execute([$this->activeKey, $this->lockToken]);
        $this->activeKey = '';
        $this->lockToken = '';
        return $result;
    }

    public function gc(int $max_lifetime): int|false
    {
        $stale = gmdate('Y-m-d H:i:s', time() - $this->lockSeconds());
        $stmt = $this->pdo->prepare("DELETE FROM web_sessions WHERE expires_at <= ? AND (lock_token = '' OR locked_at IS NULL OR locked_at < ?)");
        $stmt->execute([cpe_now(), $stale]);
        return $stmt->rowCount();
    }

    private function acquireLock(string $id): void
    {
        $key = $this->key($id);
        if ($key === $this->activeKey && $this->lockToken !== '') {
            return;
        }
        $this->releaseLock();
        $token = 'session_' . bin2hex(random_bytes(16));
        $deadline = microtime(true) + max(0.1, min(10.0, (float) (getenv('CPE_SESSION_LOCK_WAIT_SECONDS') ?: 2)));
        do {
            $now = cpe_now();
            $expiresAt = gmdate('Y-m-d H:i:s', time() + max(300, $this->lifetime));
            $stale = gmdate('Y-m-d H:i:s', time() - $this->lockSeconds());
            $stmt = $this->pdo->prepare(
                "INSERT INTO web_sessions (session_key, payload, expires_at, updated_at, lock_token, locked_at)
                 VALUES (?, '', ?, ?, ?, ?)
                 ON CONFLICT(session_key) DO UPDATE
                 SET lock_token = excluded.lock_token, locked_at = excluded.locked_at
                 WHERE web_sessions.lock_token = '' OR web_sessions.locked_at IS NULL OR web_sessions.locked_at < ?"
            );
            $stmt->execute([$key, $expiresAt, $now, $token, $now, $stale]);
            if ($stmt->rowCount() === 1) {
                $this->activeKey = $key;
                $this->lockToken = $token;
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Session is busy. Retry the request.');
    }

    private function releaseLock(): void
    {
        if ($this->activeKey === '' || $this->lockToken === '') {
            return;
        }
        $stmt = $this->pdo->prepare("UPDATE web_sessions SET lock_token = '', locked_at = NULL WHERE session_key = ? AND lock_token = ?");
        $stmt->execute([$this->activeKey, $this->lockToken]);
        $this->activeKey = '';
        $this->lockToken = '';
    }

    private function lockSeconds(): int
    {
        return max(15, min(600, (int) (getenv('CPE_SESSION_LOCK_SECONDS') ?: 120)));
    }

    private function key(string $id): string
    {
        return hash('sha256', $id);
    }
}
