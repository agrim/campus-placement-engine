<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Http\AuthorizationException;
use App\Core\Http\UserVisibleException;
use App\Core\Security\AuthorizationUnavailable;
use PDO;

final class Auth
{
    private const AUDIT_DETAILS = [
        'advising.appointment_created' => 'Advising appointment created.',
        'advising.appointment_status' => 'Advising appointment status updated.',
        'advising.note_created' => 'Advising note created.',
        'advising.task_completed' => 'Advising task completed.',
        'application.auto_handoff' => 'Application handoff completed.',
        'application.return_to_idle' => 'Application returned to idle.',
        'application.save' => 'Application saved.',
        'board_preference.clear' => 'Board preference cleared.',
        'board_preference.save' => 'Board preference saved.',
        'candidate.anonymize' => 'Candidate anonymized.',
        'candidate.create' => 'Candidate created.',
        'candidate.update' => 'Candidate updated.',
        'company.create' => 'Company created.',
        'company.update' => 'Company updated.',
        'company_round.create' => 'Company round created.',
        'company_round.update' => 'Company round updated.',
        'demo_data.clear' => 'Dummy data cleared.',
        'import' => 'Import completed.',
        'import.rollback' => 'Import rollback completed.',
        'install' => 'Initial installation completed.',
        'internal_event_delivery.replay' => 'Dead-lettered internal observer delivery replayed.',
        'internal_event_fanout.replay' => 'Dead-lettered internal observer fanout replayed.',
        'login' => 'User signed in.',
        'login.sso' => 'User signed in through institutional SSO.',
        'notification.acknowledge' => 'Notification acknowledged.',
        'placement.cleanup_competing_applications' => 'Competing applications cleared.',
        'preference.create' => 'Preference request created.',
        'preference.resolve' => 'Preference request resolved.',
        'privacy.person_erased' => 'Portal privacy erasure completed.',
        'public_event.dead_letter_replay' => 'Dead-lettered public event requeued for delivery.',
        'round_panelist.create' => 'Round panelist created.',
        'round_panelist.update' => 'Round panelist updated.',
        'round_schedule.create' => 'Round schedule created.',
        'round_schedule.update' => 'Round schedule updated.',
        'settings.configuration_unfreeze' => 'Configuration changes unfrozen.',
        'settings.update' => 'Settings updated.',
        'slot_assignment.apply_suggestions' => 'Slot assignment suggestions applied.',
        'slot_assignment.create' => 'Slot assignment created.',
        'slot_assignment.update' => 'Slot assignment updated.',
        'transition' => 'Application status changed.',
        'user.create' => 'User created.',
        'user.password_reset' => 'User password reset.',
        'users.active_bulk' => 'User activation settings updated.',
        'wanted.create' => 'Wanted alert created.',
        'wanted.resolve' => 'Wanted alert resolved.',
        'workflow.instances.migrate' => 'Workflow instances migrated.',
        'workflow.publish' => 'Workflow version published.',
    ];

    private const AUDIT_SUBJECT_TYPES = [
        'advising_appointment', 'advising_note', 'advising_task', 'application', 'candidate', 'company',
        'company_round', 'import', 'notification', 'person', 'preference_request', 'round_panelist',
        'round_schedule', 'slot_assignment', 'system', 'user', 'wanted_alert', 'workflow_version',
        'candidate_unavailability',
        'internal_event_delivery', 'internal_event_fanout', 'public_event',
    ];

    private const AUDIT_SUBJECT_ALIASES = [
        'candidates' => 'candidate',
        'companies' => 'company',
        'rounds' => 'company_round',
        'schedules' => 'round_schedule',
        'panelists' => 'round_panelist',
        'assignments' => 'slot_assignment',
        'unavailability' => 'candidate_unavailability',
        'shortlists' => 'application',
        'legacy' => 'application',
    ];

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
        } catch (AuthorizationUnavailable $e) {
            throw $e;
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
            throw new UserVisibleException('USER_DETAILS_INVALID', 'Name, valid email, and an 8+ character password are required.');
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
            throw new UserVisibleException('USER_PASSWORD_INVALID', 'Password must be at least 8 characters.');
        }
        $stmt = Database::connection()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        self::audit($actorId, 'user.password_reset', 'user', $id, 'Password reset by administrator');
    }

    public static function audit(
        ?int $actorId,
        string $action,
        string $subjectType,
        ?int $subjectId,
        string $detail = '',
        ?PDO $pdo = null,
    ): void
    {
        $safeAction = isset(self::AUDIT_DETAILS[$action]) ? $action : 'audit.unclassified';
        $safeDetail = self::AUDIT_DETAILS[$action] ?? 'Unclassified audit event recorded.';
        $safeSubjectType = self::AUDIT_SUBJECT_ALIASES[$subjectType] ?? $subjectType;
        $safeSubjectType = in_array($safeSubjectType, self::AUDIT_SUBJECT_TYPES, true) ? $safeSubjectType : 'unknown';
        [$ipAddress, $userAgent] = self::requestMetadata();
        $stmt = ($pdo ?? Database::connection())->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, subject_type, subject_id, detail, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$actorId, $safeAction, $safeSubjectType, $subjectId, $safeDetail, $ipAddress, $userAgent, cpe_now()]);
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
            $ipAddress = self::coarsenedIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        }
        if ($mode === 'user_agent' || $mode === 'both') {
            $userAgent = self::userAgentFamily((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }
        return [$ipAddress, $userAgent];
    }

    private static function coarsenedIp(string $value): string
    {
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $value);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($value);
            if (is_string($packed)) {
                $prefix = substr($packed, 0, 6) . str_repeat("\0", 10);
                $normalized = inet_ntop($prefix);
                return is_string($normalized) ? $normalized . '/48' : '';
            }
        }
        return '';
    }

    private static function userAgentFamily(string $value): string
    {
        return match (true) {
            preg_match('/(?:Edg|Edge)\//i', $value) === 1 => 'browser.edge',
            preg_match('/(?:Chrome|Chromium)\//i', $value) === 1 => 'browser.chrome',
            preg_match('/Firefox\//i', $value) === 1 => 'browser.firefox',
            preg_match('/Safari\//i', $value) === 1 && preg_match('/Chrome|Chromium|Edg/i', $value) !== 1 => 'browser.safari',
            preg_match('/curl\//i', $value) === 1 => 'client.curl',
            trim($value) === '' => '',
            default => 'client.other',
        };
    }
}
