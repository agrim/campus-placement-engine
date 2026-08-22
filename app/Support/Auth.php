<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Http\AuthorizationException;
use PDO;

final class Auth
{
    public static function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
        $stmt->execute([(int) $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if (!$user) {
            redirect(url('login'));
        }
        return $user;
    }

    /**
     * @param array<int, string> $roles
     */
    public static function requireRole(array $roles, string $message = 'Your role cannot open this page.'): array
    {
        $user = self::requireUser();
        if (!in_array((string) ($user['role'] ?? ''), $roles, true)) {
            throw new AuthorizationException($message);
        }
        return $user;
    }

    /**
     * @param array<int, string> $roles
     */
    public static function hasRole(?array $user, array $roles): bool
    {
        return $user !== null && in_array((string) ($user['role'] ?? ''), $roles, true);
    }

    public static function requireCapability(string $capability, string $message = 'You cannot perform this action.'): array
    {
        $user = self::requireUser();
        if (!self::hasCapability($user, $capability)) {
            throw new AuthorizationException($message);
        }
        return $user;
    }

    public static function hasCapability(?array $user, string $capability): bool
    {
        try {
            return \App\Core\Portal::context()->capabilities()->allows($user, $capability);
        } catch (\Throwable $e) {
            StructuredLogger::log('error', 'authorization.capability_resolution_failed', [
                'capability' => $capability,
                'user_id' => isset($user['id']) ? (int) $user['id'] : null,
                'exception' => get_class($e),
            ]);
            return false;
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE lower(email) = lower(?) AND active = 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['auth_method'] = 'password';
        self::audit((int) $user['id'], 'login', 'user', (int) $user['id'], 'Successful login');
        return true;
    }

    public static function loginById(int $id, string $method = 'local'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $id;
        $_SESSION['auth_method'] = $method;
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['auth_method']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function createAdmin(string $name, string $email, string $password): int
    {
        $stmt = Database::connection()->prepare('INSERT INTO users (name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin', cpe_now()]);
        return Database::lastInsertId(Database::connection());
    }

    public static function createUser(string $name, string $email, string $password, string $role, string $scopeType = '', string $scopeValue = ''): int
    {
        if (trim($name) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new \RuntimeException('Name, valid email, and an 8+ character password are required.');
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (name, email, password_hash, role, scope_type, scope_value, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $scopeType, $scopeValue, cpe_now()]);
        return Database::lastInsertId(Database::connection());
    }

    public static function users(): array
    {
        return Database::connection()->query('SELECT id, name, email, role, scope_type, scope_value, active, created_at FROM users ORDER BY role, name')->fetchAll();
    }

    public static function setActive(int $id, bool $active): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    /** @param array<int, int|string> $activeIds */
    public static function setActiveBulk(array $activeIds, int $actorId): void
    {
        $ids = array_map('intval', $activeIds);
        if (!in_array($actorId, $ids, true)) {
            $ids[] = $actorId;
        }
        foreach (self::users() as $user) {
            $active = in_array((int) $user['id'], $ids, true);
            self::setActive((int) $user['id'], $active);
        }
        self::audit($actorId, 'users.active_bulk', 'user', null, 'Updated active user flags');
    }

    public static function setPassword(int $id, string $password, int $actorId): void
    {
        if (strlen($password) < 8) {
            throw new \RuntimeException('Password must be at least 8 characters.');
        }
        $stmt = Database::connection()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        self::audit($actorId, 'user.password_reset', 'user', $id, 'Password reset by administrator');
    }

    public static function audit(?int $actorId, string $action, string $subjectType, ?int $subjectId, string $detail = ''): void
    {
        [$ipAddress, $userAgent] = self::requestMetadata();
        $stmt = Database::connection()->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, subject_type, subject_id, detail, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$actorId, $action, $subjectType, $subjectId, $detail, $ipAddress, $userAgent, cpe_now()]);
    }

    private static function requestMetadata(): array
    {
        $mode = strtolower(trim(cpe_setting('audit_request_metadata', cpe_config('settings.audit_request_metadata', 'none'))));
        if (!in_array($mode, ['none', 'ip', 'user_agent', 'both'], true)) {
            $mode = 'none';
        }
        $ipAddress = '';
        $userAgent = '';
        if ($mode === 'ip' || $mode === 'both') {
            $ipAddress = substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
        }
        if ($mode === 'user_agent' || $mode === 'both') {
            $userAgent = substr(preg_replace('/\s+/', ' ', trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) ?? '', 0, 180);
        }
        return [$ipAddress, $userAgent];
    }
}
