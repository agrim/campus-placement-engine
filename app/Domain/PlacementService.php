<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Http\UserVisibleException;
use App\Core\Events\DomainEvent;
use App\Core\Persistence\WriteTransaction;
use App\Modules\Placement\Install\LegacyDomainSynchronizer;
use App\Modules\Placement\Application\ApplicationStatusWriter;
use App\Modules\Placement\Workflow\WorkflowEngine;
use App\Modules\Placement\Workflow\WorkflowPublisher;
use App\Modules\Placement\Workflow\WorkflowRepository;
use App\Support\Auth;
use App\Support\Database;
use PDO;
use RuntimeException;

final class PlacementService
{
    private const SERVICE_ACCOUNT_ROLE = 'service_account';
    private const APPLICATION_TRANSITION_CAPABILITY = 'placement.application.transition';

    private ?WorkflowEngine $workflowEngine = null;
    private ApplicationStatusWriter $statusWriter;
    private const DEFAULT_EXACT_SLOT_OPTIMIZER_LIMIT = 10;
    private const DEFAULT_BOARD_CARD_FIELDS = [
        'candidate_id',
        'program',
        'tags',
        'company',
        'process',
        'tracker',
        'active_cap',
        'rounds',
        'schedule',
        'slot',
        'panel',
        'route',
        'location',
        'accommodation',
        'waitlist',
    ];
    private const BOARD_CARD_FIELD_OPTIONS = [
        'candidate_id' => 'Candidate ID',
        'program' => 'Program',
        'tags' => 'Tags',
        'company' => 'Company',
        'process' => 'Process and room',
        'tracker' => 'Tracker',
        'active_cap' => 'Active cap',
        'rounds' => 'Rounds',
        'schedule' => 'Schedule',
        'slot' => 'Candidate slot',
        'panel' => 'Panel',
        'route' => 'Movement route',
        'location' => 'Location',
        'accommodation' => 'Accommodation notes',
        'custom_fields' => 'Custom fields',
        'waitlist' => 'Waitlist rank',
    ];
    public function __construct(private ?PDO $pdo = null, private ?Workflow $workflow = null)
    {
        $this->pdo ??= Database::connection();
        $this->statusWriter = new ApplicationStatusWriter($this->pdo);
        (new WorkflowPublisher($this->pdo))->synchronizeLegacyMirrorIfChanged();
        $this->workflow ??= new Workflow();
        $repository = new WorkflowRepository($this->pdo);
        if ($repository->hasSchema() && $repository->activeVersionId() !== null) {
            $this->workflowEngine = new WorkflowEngine($this->pdo);
        }
    }

    public function dashboard(?array $user = null, array $filters = []): array
    {
        $params = [];
        $where = [];
        $role = (string) ($user['role'] ?? 'admin');
        $staleCutoff = $this->staleCutoff($this->staleMinutesForUser((int) ($user['id'] ?? 0)));
        $activeApplicationSql = $this->activeApplicationSql('ac');
        if ($this->isCompanyScopedUser($user) && ($user['scope_value'] ?? '') !== '') {
            $where[] = 'co.code = ?';
            $params[] = strtoupper((string) $user['scope_value']);
        }
        $company = strtoupper(trim((string) ($filters['company'] ?? '')));
        if ($company !== '' && !$this->isCompanyScopedUser($user)) {
            $where[] = 'co.code = ?';
            $params[] = $company;
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && array_key_exists($status, $this->workflow->statuses())) {
            $where[] = 'a.current_status = ?';
            $params[] = $status;
        }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . $query . '%';
            $searchFields = ['c.external_id', 'c.name', 'c.program', 'co.code', 'co.name', 'co.tags'];
            if ($this->canSeeCandidatePrivateFields($user)) {
                array_push($searchFields, 'c.tags', 'c.custom_fields_json', 'co.custom_fields_json');
            }
            if ($this->canSeeCandidateAccommodation($user)) {
                $searchFields[] = 'c.accommodation_notes';
            }
            $where[] = '(' . implode(' OR ', array_map(fn (string $field): string => "{$field} LIKE ?", $searchFields)) . ')';
            array_push($params, ...array_fill(0, count($searchFields), $like));
        }
        $flag = trim((string) ($filters['flag'] ?? ''));
        if ($flag === 'wanted') {
            $where[] = "EXISTS (SELECT 1 FROM wanted_alerts wa WHERE wa.candidate_id = c.id AND wa.status = 'open')";
        } elseif ($flag === 'preference') {
            $where[] = "EXISTS (SELECT 1 FROM preference_requests pr WHERE pr.candidate_id = c.id AND pr.status = 'open')";
        } elseif ($flag === 'opted_out') {
            $where[] = 'c.opted_out = 1';
        } elseif ($flag === 'waitlist') {
            $where[] = 'a.waitlist_rank IS NOT NULL';
        } elseif ($flag === 'stale') {
            $where[] = $this->activeApplicationSql('a') . ' AND a.updated_at < ?';
            $params[] = $staleCutoff;
        } elseif ($flag === 'conflict') {
            $where[] = "(SELECT COUNT(*) FROM applications ac WHERE ac.candidate_id = c.id AND {$activeApplicationSql}) > 1";
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $activeCompanyCodes = Database::groupConcat('co2.code');
        $stmt = $this->pdo->prepare(
            'SELECT a.*, c.external_id, c.name AS candidate_name, c.program, c.tags AS candidate_tags, c.custom_fields_json AS candidate_custom_fields_json, c.current_location, c.accommodation_notes, c.opted_out,
                    co.code AS company_code, co.name AS company_name, co.process_type,
                    co.room, co.tracker_name, co.max_active, co.process_notes, co.tags AS company_tags, co.custom_fields_json AS company_custom_fields_json,
                    pc.code AS previous_company_code, pc.name AS previous_company_name,
                    nc.code AS next_company_code, nc.name AS next_company_name,
                    (SELECT COUNT(*) FROM wanted_alerts wa WHERE wa.candidate_id = c.id AND wa.status = \'open\') AS open_wanted_count,
                    (SELECT COUNT(*) FROM preference_requests pr WHERE pr.candidate_id = c.id AND pr.status = \'open\') AS open_preference_count,
                    (SELECT COUNT(*) FROM applications ac WHERE ac.candidate_id = c.id AND ' . $activeApplicationSql . ') AS active_company_count,
                    (SELECT ' . $activeCompanyCodes . ' FROM applications ac JOIN companies co2 ON co2.id = ac.company_id WHERE ac.candidate_id = c.id AND ' . $activeApplicationSql . ') AS active_company_codes
             FROM applications a
             JOIN candidates c ON c.id = a.candidate_id
             JOIN companies co ON co.id = a.company_id
             LEFT JOIN companies pc ON pc.id = a.previous_company_id
             LEFT JOIN companies nc ON nc.id = a.next_company_id
             ' . $whereSql . '
             ORDER BY a.updated_at ASC, co.code, c.external_id'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $visibleStatuses = $this->visibleStatusesForRole($role);
        $actionableOnly = !empty($filters['actionable']);
        $roundSummaries = $this->companyRoundSummaries();
        $scheduleSummaries = $this->companyScheduleSummaries();
        $panelSummaries = $this->companyPanelSummaries();
        $slotSummaries = $this->applicationSlotSummaries();
        $rows = array_values(array_filter(array_map(
            function (array $row) use ($roundSummaries, $scheduleSummaries, $panelSummaries, $slotSummaries, $staleCutoff, $role, $user): array {
                $row = $this->applyBoardVisibility(
                    $this->enrichBoardRow($row, $roundSummaries, $scheduleSummaries, $panelSummaries, $slotSummaries, $staleCutoff),
                    $user
                );
                $row['workflow_actions'] = $this->availableTransitions((int) $row['id'], $role);
                $row['workflow_correction'] = $this->availableCorrection((int) $row['id'], $role);
                return $row;
            },
            $rows
        ), function (array $row) use ($visibleStatuses, $role, $actionableOnly): bool {
            if (!in_array($row['current_status'], $visibleStatuses, true)) {
                return false;
            }
            if (!$actionableOnly) {
                return true;
            }
            if ($this->workflowEngine !== null) {
                return $row['workflow_actions'] !== [];
            }
            $next = $this->workflow->nextStatus((string) $row['current_status']);
            return $next !== null && $this->workflow->canTransition((string) $row['current_status'], $next, $role);
        }));

        $groups = [];
        foreach ($this->workflow->statuses() as $key => $status) {
            if (in_array($key, $visibleStatuses, true)) {
                $groups[$key] = ['status' => $status, 'applications' => []];
            }
        }
        foreach ($rows as $row) {
            if (isset($groups[$row['current_status']])) {
                $groups[$row['current_status']]['applications'][] = $row;
            }
        }
        foreach ($groups as &$group) {
            $this->sortBoardApplications($group['applications'], $role);
        }
        unset($group);
        return $groups;
    }

    public function availableTransitions(int $applicationId, string $actorRole): array
    {
        if ($this->workflowEngine !== null) {
            return $this->workflowEngine->availableTransitions($applicationId, $actorRole);
        }
        $stmt = $this->pdo->prepare('SELECT current_status FROM applications WHERE id = ?');
        $stmt->execute([$applicationId]);
        $from = $stmt->fetchColumn();
        if ($from === false) {
            return [];
        }
        $to = $this->workflow->nextStatus((string) $from);
        if ($to === null || !$this->workflow->canTransition((string) $from, $to, $actorRole)) {
            return [];
        }
        return [[
            'key' => 'advance_' . $from . '_to_' . $to,
            'label' => 'Move to ' . $this->workflow->statusLabel($to),
            'from' => (string) $from,
            'to' => $to,
            'is_correction' => false,
        ]];
    }

    public function availableCorrection(int $applicationId, string $actorRole): ?array
    {
        if ($this->workflowEngine === null) {
            return null;
        }
        foreach ($this->workflowEngine->availableTransitions($applicationId, $actorRole, true, 'operator_return') as $transition) {
            if (!empty($transition['is_correction'])) {
                return $transition;
            }
        }
        return null;
    }

    public function boardFilterOptions(?array $user = null): array
    {
        $role = (string) ($user['role'] ?? 'admin');
        $visibleStatuses = $this->visibleStatusesForRole($role);
        $statuses = array_filter(
            $this->workflow->statuses(),
            fn (string $key): bool => in_array($key, $visibleStatuses, true),
            ARRAY_FILTER_USE_KEY
        );
        $companies = [];
        if ($this->isCompanyScopedUser($user) && trim((string) ($user['scope_value'] ?? '')) !== '') {
            $stmt = $this->pdo->prepare('SELECT code, name FROM companies WHERE code = ? ORDER BY code');
            $stmt->execute([strtoupper((string) $user['scope_value'])]);
            $companies = $stmt->fetchAll();
        } else {
            $companies = $this->pdo->query('SELECT code, name FROM companies ORDER BY code')->fetchAll();
        }

        return [
            'statuses' => $statuses,
            'companies' => $companies,
            'flags' => [
                '' => 'Any flag',
                'wanted' => 'Wanted alert',
                'preference' => 'Preference request',
                'opted_out' => 'Opted out',
                'waitlist' => 'Waitlist',
                'stale' => 'Stale active',
                'conflict' => 'Active conflict',
            ],
        ];
    }

    public function boardViewPresets(?array $user = null): array
    {
        $role = (string) ($user['role'] ?? 'admin');
        $presets = [
            ['key' => 'all', 'label' => 'All visible', 'params' => []],
            ['key' => 'actionable', 'label' => 'Actionable', 'params' => ['actionable' => '1']],
            ['key' => 'wanted', 'label' => 'Wanted', 'params' => ['flag' => 'wanted']],
            ['key' => 'stale', 'label' => 'Stale active', 'params' => ['flag' => 'stale']],
        ];
        $rolePresets = match ($role) {
            'mobile' => [
                ['key' => 'mobile-outbound', 'label' => 'Outbound', 'params' => ['status' => 'scheduled', 'compact' => '1']],
                ['key' => 'mobile-returning', 'label' => 'Returning', 'params' => ['status' => 'sent', 'compact' => '1']],
            ],
            'floor' => [
                ['key' => 'floor-arrivals', 'label' => 'Arrivals', 'params' => ['status' => 'arrived', 'compact' => '1']],
                ['key' => 'floor-inside', 'label' => 'Inside panels', 'params' => ['status' => 'inside', 'compact' => '1']],
            ],
            'placement' => [
                ['key' => 'placement-decisions', 'label' => 'Decisions', 'params' => ['status' => 'sent', 'compact' => '1']],
                ['key' => 'placement-requests', 'label' => 'Requests', 'params' => ['status' => 'requested', 'compact' => '1']],
            ],
            'company' => [
                ['key' => 'company-active', 'label' => 'Company active', 'params' => ['actionable' => '1', 'compact' => '1']],
            ],
            default => [
                ['key' => 'compact', 'label' => 'Compact board', 'params' => ['compact' => '1']],
            ],
        };
        return [...$presets, ...$rolePresets];
    }

    public function boardCardFieldOptions(): array
    {
        return self::BOARD_CARD_FIELD_OPTIONS;
    }

    public function boardCardFields(): array
    {
        $value = $this->setting('board_card_fields', implode(',', self::DEFAULT_BOARD_CARD_FIELDS));
        $fields = $this->normalizedBoardCardFieldKeys($value);
        return $fields === [] ? self::DEFAULT_BOARD_CARD_FIELDS : $fields;
    }

    public function normalizeBoardCardFields(array|string $value): string
    {
        $fields = $this->normalizedBoardCardFieldKeys($value);
        return implode(',', $fields === [] ? self::DEFAULT_BOARD_CARD_FIELDS : $fields);
    }

    public function normalizeCustomFieldsJson(string $value, string $label = 'Custom fields'): string
    {
        $value = trim($value);
        if ($value === '') {
            return '{}';
        }
        if (strlen($value) > 5000) {
            throw new UserVisibleException('PLACEMENT_CUSTOM_FIELDS_INVALID', $label . ' JSON must be 5000 bytes or fewer.');
        }
        $payload = json_decode($value);
        if (!$payload instanceof \stdClass) {
            throw new UserVisibleException('PLACEMENT_CUSTOM_FIELDS_INVALID', $label . ' must be a JSON object.');
        }
        $fields = get_object_vars($payload);
        ksort($fields);
        $normalized = new \stdClass();
        foreach ($fields as $key => $fieldValue) {
            $key = trim((string) $key);
            if ($key === '' || strlen($key) > 60) {
                throw new UserVisibleException('PLACEMENT_CUSTOM_FIELDS_INVALID', $label . ' keys must be 1 to 60 characters.');
            }
            if (is_array($fieldValue) || is_object($fieldValue)) {
                throw new UserVisibleException('PLACEMENT_CUSTOM_FIELDS_INVALID', $label . ' values must be strings, numbers, booleans, or null.');
            }
            $normalized->{$key} = $fieldValue;
        }
        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Could not normalize ' . strtolower($label) . '.');
        }
        return $encoded;
    }

    public function boardRefreshSeconds(): int
    {
        return $this->normalizeBoardRefreshSeconds($this->setting('board_refresh_seconds', (string) cpe_config('settings.board_refresh_seconds', '45')));
    }

    public function normalizeBoardRefreshSeconds(int|string|null $value): int
    {
        $seconds = (int) $value;
        if ($seconds <= 0) {
            return 0;
        }
        return max(15, min(600, $seconds));
    }

    /**
     * @return array{duplicate: bool, status: string}
     */
    public function applyBoardMove(
        int $applicationId,
        ?int $actorId,
        string $actorRole,
        string $toStatus,
        string $transitionKey,
        string $note,
        string $expectedFromStatus,
        string $idempotencyKey,
        array $actorContext = [],
    ): array {
        $payload = [
            'actor_role' => $actorRole,
            'expected_status' => $expectedFromStatus,
            'note' => $note,
            'to_status' => $toStatus,
            'transition_key' => $transitionKey,
        ];
        $this->addActorScopeToPayload($payload, $actorContext);
        return $this->executeBoardRequest(
            $idempotencyKey,
            $actorId,
            'board.move',
            $applicationId,
            $payload,
            $actorContext,
            function () use ($applicationId, $actorId, $actorRole, $toStatus, $transitionKey, $note, $expectedFromStatus): array {
                $status = $toStatus !== ''
                    ? $this->moveTo($applicationId, $toStatus, $transitionKey, $actorId, $actorRole, $note, $expectedFromStatus)
                    : $this->moveNext($applicationId, $actorId, $actorRole, $note, $expectedFromStatus);
                return ['status' => $status];
            }
        );
    }

    /**
     * Transaction-local API actor path. Public API idempotency is owned by the
     * keyed command store, so this deliberately bypasses browser form keys.
     *
     * @return array{duplicate: bool, status: string}
     */
    public function applyServiceAccountMove(
        int $applicationId,
        int $actorServiceAccountId,
        string $toStatus,
        string $transitionKey,
        string $note,
        string $expectedFromStatus,
    ): array {
        if ($actorServiceAccountId < 1) {
            throw new RuntimeException('Service-account transition identity is invalid.');
        }
        if (trim($transitionKey) === '') {
            throw new UserVisibleException(
                'WORKFLOW_TRANSITION_UNAVAILABLE',
                'Workflow transition is unavailable.',
            );
        }
        $status = $this->moveTo(
            $applicationId,
            $toStatus,
            $transitionKey,
            null,
            self::SERVICE_ACCOUNT_ROLE,
            $note,
            $expectedFromStatus,
            $actorServiceAccountId,
            true,
        );
        return ['duplicate' => false, 'status' => $status];
    }

    /**
     * @return array{duplicate: bool, status: string}
     */
    public function applyBoardReturnToIdle(
        int $applicationId,
        ?int $actorId,
        string $actorRole,
        string $reason,
        string $note,
        string $expectedFromStatus,
        string $idempotencyKey,
        array $actorContext = [],
    ): array {
        $reason = trim($reason) !== '' ? trim($reason) : 'operator_return';
        $payload = [
            'actor_role' => $actorRole,
            'expected_status' => $expectedFromStatus,
            'note' => $note,
            'reason' => $reason,
        ];
        $this->addActorScopeToPayload($payload, $actorContext);
        return $this->executeBoardRequest(
            $idempotencyKey,
            $actorId,
            'board.return_to_idle',
            $applicationId,
            $payload,
            $actorContext,
            function () use ($applicationId, $actorId, $actorRole, $reason, $note, $expectedFromStatus): array {
                $this->returnToIdle($applicationId, $actorId, $actorRole, $reason, $note, $expectedFromStatus);
                $stmt = $this->pdo->prepare('SELECT current_status FROM applications WHERE id = ?');
                $stmt->execute([$applicationId]);
                return ['status' => (string) $stmt->fetchColumn()];
            }
        );
    }

    /**
     * Compatibility helper for non-board callers. Board mutations must use the
     * atomic applyBoardMove/applyBoardReturnToIdle boundary instead.
     */
    public function consumeIdempotencyKey(string $key, ?int $actorUserId, string $action, ?int $applicationId = null): bool
    {
        $key = trim($key);
        if ($key === '') {
            return true;
        }
        if (!preg_match('/^[A-Fa-f0-9]{32,64}$/', $key)) {
            throw new UserVisibleException('FORM_SUBMISSION_KEY_INVALID', 'Invalid form submission key.');
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - 172800);
        $cleanup = $this->pdo->prepare('DELETE FROM idempotency_keys WHERE created_at < ?');
        $cleanup->execute([$cutoff]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO idempotency_keys (key, actor_user_id, action, application_id, created_at)
             VALUES (?, ?, ?, ?, ?) ON CONFLICT(key) DO NOTHING'
        );
        $stmt->execute([$key, $actorUserId, $action, $applicationId, cpe_now()]);
        return $stmt->rowCount() === 1;
    }

    public function boardPreferenceForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT q, company, status, flag, actionable, compact, stale_minutes FROM user_board_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return [];
        }
        return [
            'q' => (string) $row['q'],
            'company' => (string) $row['company'],
            'status' => (string) $row['status'],
            'flag' => (string) $row['flag'],
            'actionable' => (int) $row['actionable'] === 1 ? '1' : '',
            'compact' => (int) $row['compact'] === 1 ? '1' : '',
            'stale_minutes' => (string) $this->normalizeStaleMinutes($row['stale_minutes'] ?? null),
        ];
    }

    public function staleMinutesForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 90;
        }
        $stmt = $this->pdo->prepare('SELECT stale_minutes FROM user_board_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return 90;
        }
        return $this->normalizeStaleMinutes($value);
    }

    public function saveBoardPreference(int $userId, array $filters): void
    {
        if (!$this->recordExists('users', $userId)) {
            throw new UserVisibleException('BOARD_USER_NOT_FOUND', 'User not found.');
        }
        $now = cpe_now();
        $staleMinutes = $this->normalizeStaleMinutes($filters['stale_minutes'] ?? null);
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_board_preferences (user_id, q, company, status, flag, actionable, compact, stale_minutes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(user_id) DO UPDATE SET
                q = excluded.q,
                company = excluded.company,
                status = excluded.status,
                flag = excluded.flag,
                actionable = excluded.actionable,
                compact = excluded.compact,
                stale_minutes = excluded.stale_minutes,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            $userId,
            trim((string) ($filters['q'] ?? '')),
            strtoupper(trim((string) ($filters['company'] ?? ''))),
            trim((string) ($filters['status'] ?? '')),
            trim((string) ($filters['flag'] ?? '')),
            !empty($filters['actionable']) ? 1 : 0,
            !empty($filters['compact']) ? 1 : 0,
            $staleMinutes,
            $now,
            $now,
        ]);
        Auth::audit($userId, 'board_preference.save', 'user', $userId, 'Saved board default filters');
    }

    public function clearBoardPreference(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_board_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        Auth::audit($userId, 'board_preference.clear', 'user', $userId, 'Cleared board default filters');
    }

    public function visibleStatusesForRole(string $role): array
    {
        $all = array_keys($this->workflow->statuses());
        return match ($role) {
            'mobile' => array_values(array_intersect($all, ['scheduled', 'intransit', 'sent'])),
            'floor' => array_values(array_intersect($all, ['intransit', 'arrived', 'requested', 'sendin', 'inside', 'exit'])),
            'placement' => array_values(array_intersect($all, ['requested', 'sendin', 'requestaway', 'sendaway', 'sent', 'placed'])),
            default => $all,
        };
    }

    private function canSeeCandidatePrivateFields(?array $user): bool
    {
        return $user === null || Auth::hasCapability($user, 'placement.sensitive.view');
    }

    private function canSeeCandidateAccommodation(?array $user): bool
    {
        return $user === null || Auth::hasCapability($user, 'placement.accommodation.view');
    }

    private function canSeeCrossCompanyMovement(?array $user): bool
    {
        return $user === null || Auth::hasCapability($user, 'placement.cross_company.view');
    }

    private function isCompanyScopedUser(?array $user): bool
    {
        return $user !== null && (
            (string) ($user['scope_type'] ?? '') === 'company'
            || (string) ($user['role'] ?? '') === 'company'
        );
    }

    public function roleContext(array $user): array
    {
        $role = (string) ($user['role'] ?? 'auditor');
        $scope = trim((string) ($user['scope_value'] ?? ''));
        return match ($role) {
            'admin' => [
                'title' => 'Administrator Board',
                'summary' => 'Full workflow visibility with configuration and override responsibility.',
                'focus' => 'Before live use, confirm workflow validation, backup freshness, and active user access on the System page.',
            ],
            'control' => [
                'title' => 'Control Room Board',
                'summary' => 'Master movement view for scheduling, send-in/send-away decisions, and placement closure.',
                'focus' => 'Watch requested, request-away, sent, wanted, and preference items before moving candidates.',
            ],
            'placement' => [
                'title' => 'Placement Office Board',
                'summary' => 'Decision-stage view for send-in/send-away approvals and placement recording.',
                'focus' => 'Use this view for policy-sensitive moves, offer decisions, and unresolved preference requests.',
            ],
            'company' => [
                'title' => $scope !== '' ? "{$scope} Company Tracker" : 'Company Tracker Board',
                'summary' => $scope !== '' ? "Showing only applications scoped to company {$scope}." : 'Company tracker scope is not set.',
                'focus' => 'Use only the visible queue. Cross-company moves are blocked server-side.',
            ],
            'mobile' => [
                'title' => 'Mobile Tracker Board',
                'summary' => 'Movement-focused view for candidates leaving control room and returning after panels.',
                'focus' => 'Keep current location accurate and resolve handoffs quickly.',
            ],
            'floor' => [
                'title' => 'Floor Coordinator Board',
                'summary' => 'Arrival, room, and interview-floor status view.',
                'focus' => 'Watch arrivals, send-ins, inside-panel states, and exits.',
            ],
            default => [
                'title' => 'Read-only Board',
                'summary' => 'Audit view across the configured workflow.',
                'focus' => 'No mutation controls are available for this role.',
            ],
        };
    }

    public function assertCanActOnApplication(int $applicationId, array $user): void
    {
        $this->assertCanActOnApplicationContext($applicationId, $user, false);
    }

    private function assertCanActOnApplicationContext(int $applicationId, array $user, bool $lock): void
    {
        if (!$this->isCompanyScopedUser($user)) {
            return;
        }
        $lockClause = $lock && (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? ' FOR UPDATE OF a, co'
            : '';
        $stmt = $this->pdo->prepare(
            'SELECT co.code
             FROM applications a
             JOIN companies co ON co.id = a.company_id
             WHERE a.id = ?' . $lockClause
        );
        $stmt->execute([$applicationId]);
        $companyCode = $stmt->fetchColumn();
        if (!$companyCode) {
            throw new UserVisibleException('PLACEMENT_APPLICATION_NOT_FOUND', 'Application not found.');
        }
        $scope = strtoupper(trim((string) ($user['scope_value'] ?? '')));
        if ($scope === '' || strtoupper((string) $companyCode) !== $scope) {
            throw new UserVisibleException('PLACEMENT_COMPANY_SCOPE_FORBIDDEN', 'Company users cannot move applications outside their assigned company scope.');
        }
    }

    public function stats(): array
    {
        return [
            'candidates' => (int) $this->pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn(),
            'companies' => (int) $this->pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn(),
            'applications' => (int) $this->pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn(),
            'placed' => (int) $this->pdo->query("SELECT COUNT(DISTINCT candidate_id) FROM applications WHERE current_status = 'placed'")->fetchColumn(),
        ];
    }

    private function enrichBoardRow(array $row, array $roundSummaries, array $scheduleSummaries, array $panelSummaries, array $slotSummaries, string $staleCutoff): array
    {
        $row['open_wanted_count'] = (int) ($row['open_wanted_count'] ?? 0);
        $row['open_preference_count'] = (int) ($row['open_preference_count'] ?? 0);
        $row['active_company_count'] = (int) ($row['active_company_count'] ?? 0);
        $row['active_company_codes'] = (string) ($row['active_company_codes'] ?? '');
        $row['round_summary'] = $roundSummaries[(int) ($row['company_id'] ?? 0)] ?? '';
        $row['schedule_summary'] = $scheduleSummaries[(int) ($row['company_id'] ?? 0)] ?? '';
        $row['panel_summary'] = $panelSummaries[(int) ($row['company_id'] ?? 0)] ?? '';
        $row['slot_assignment_summary'] = $slotSummaries[(int) ($row['id'] ?? 0)] ?? '';
        $row['route_summary'] = $this->routeSummary($row);
        $row['is_stale'] = !in_array((string) $row['current_status'], ['idle', 'placed'], true)
            && (string) ($row['updated_at'] ?? '') < $staleCutoff;
        $row['has_active_conflict'] = $row['active_company_count'] > 1;
        return $row;
    }

    private function applyBoardVisibility(array $row, ?array $user): array
    {
        if (!$this->canSeeCandidatePrivateFields($user)) {
            $row['candidate_tags'] = '';
            $row['candidate_custom_fields_json'] = '{}';
            $row['company_custom_fields_json'] = '{}';
        }
        if (!$this->canSeeCandidateAccommodation($user)) {
            $row['accommodation_notes'] = '';
        }
        if (!$this->canSeeCrossCompanyMovement($user)) {
            $row['route_summary'] = '';
            $row['previous_company_code'] = '';
            $row['previous_company_name'] = '';
            $row['next_company_code'] = '';
            $row['next_company_name'] = '';
            $row['active_company_codes'] = $row['active_company_count'] > 1 ? 'another active company' : '';
        }
        return $row;
    }

    private function applyCandidateVisibility(array $candidate, ?array $user): array
    {
        if (!$this->canSeeCandidatePrivateFields($user)) {
            $candidate['tags'] = '';
            $candidate['custom_fields_json'] = '{}';
        }
        if (!$this->canSeeCandidateAccommodation($user)) {
            $candidate['accommodation_notes'] = '';
        }
        return $candidate;
    }

    private function applyEventVisibility(array $event, ?array $user): array
    {
        if (!$this->canSeeCandidatePrivateFields($user)) {
            $event['note'] = '';
        }
        return $event;
    }

    private function sortBoardApplications(array &$applications, string $role): void
    {
        usort($applications, function (array $a, array $b) use ($role): int {
            return [
                $this->boardPriority($a, $role),
                empty($a['waitlist_rank']) ? PHP_INT_MAX : (int) $a['waitlist_rank'],
                (string) $a['updated_at'],
                (string) $a['company_code'],
                (string) $a['external_id'],
            ] <=> [
                $this->boardPriority($b, $role),
                empty($b['waitlist_rank']) ? PHP_INT_MAX : (int) $b['waitlist_rank'],
                (string) $b['updated_at'],
                (string) $b['company_code'],
                (string) $b['external_id'],
            ];
        });
    }

    private function boardPriority(array $row, string $role): int
    {
        if ((int) ($row['open_wanted_count'] ?? 0) > 0) {
            return 0;
        }
        if ((int) ($row['open_preference_count'] ?? 0) > 0) {
            return 1;
        }
        if (!empty($row['has_active_conflict'])) {
            return 2;
        }
        if (!empty($row['is_stale'])) {
            return 3;
        }
        $status = (string) ($row['current_status'] ?? '');
        $roleHotStatuses = [
            'control' => ['requested', 'requestaway', 'sent'],
            'placement' => ['requested', 'requestaway', 'sent'],
            'mobile' => ['scheduled', 'intransit', 'sent'],
            'floor' => ['arrived', 'sendin', 'inside', 'exit'],
            'company' => ['arrived', 'sendin', 'inside', 'sendaway'],
        ];
        if (in_array($status, $roleHotStatuses[$role] ?? [], true)) {
            return 4;
        }
        return 5;
    }

    private function routeSummary(array $row): string
    {
        $status = (string) ($row['current_status'] ?? '');
        $company = trim((string) ($row['company_code'] ?? ''));
        if ($company === '') {
            return '';
        }

        if (in_array($status, ['scheduled', 'intransit'], true)) {
            $from = trim((string) ($row['previous_company_code'] ?? ''));
            if ($from === '') {
                $from = trim((string) ($row['current_location'] ?? 'CP'));
            }
            if ($from === '' || strtoupper($from) === strtoupper($company)) {
                return '';
            }
            return $from . ' -> ' . $company;
        }

        if (in_array($status, ['requestaway', 'sendaway', 'sent'], true)) {
            $to = trim((string) ($row['next_company_code'] ?? ''));
            if ($to === '') {
                $to = 'CP';
            }
            if (strtoupper($to) === strtoupper($company)) {
                return '';
            }
            return $company . ' -> ' . $to;
        }

        return '';
    }

    public function transition(
        int $applicationId,
        string $toStatus,
        ?int $actorId,
        string $actorRole,
        string $note = '',
        string $expectedFromStatus = '',
        string $transitionKey = '',
        ?int $actorServiceAccountId = null,
        bool $serviceCapability = false,
    ): void
    {
        $this->transactional(function () use (
            $applicationId,
            $toStatus,
            $actorId,
            $actorRole,
            $note,
            $expectedFromStatus,
            $transitionKey,
            $actorServiceAccountId,
            $serviceCapability,
        ): void {
        if (($actorId !== null && $actorServiceAccountId !== null)
            || ($serviceCapability && ($actorServiceAccountId === null || $actorRole !== self::SERVICE_ACCOUNT_ROLE))
            || (!$serviceCapability && $actorServiceAccountId !== null)) {
            throw new RuntimeException('Application transition actor attribution is invalid.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT a.*, c.external_id, c.opted_out, c.placed_company_id, c.current_location, co.code AS company_code
             FROM applications a
             JOIN candidates c ON c.id = a.candidate_id
             JOIN companies co ON co.id = a.company_id
             WHERE a.id = ?'
        );
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
        if (!$app) {
            throw new UserVisibleException('PLACEMENT_APPLICATION_NOT_FOUND', 'Application not found.');
        }

        $fromStatus = $app['current_status'];
        if ($expectedFromStatus !== '' && $expectedFromStatus !== $fromStatus) {
            throw new UserVisibleException('PLACEMENT_BOARD_STALE', 'This board card is stale. Reload the board before moving it.');
        }
        $versionedTransition = null;
        if ($this->workflowEngine !== null) {
            if ($serviceCapability) {
                $versionedTransition = $this->workflowEngine->resolveServiceAccountKey(
                    $applicationId,
                    $transitionKey,
                    self::APPLICATION_TRANSITION_CAPABILITY,
                );
            } else {
                $versionedTransition = $transitionKey !== ''
                    ? $this->workflowEngine->resolveKey($applicationId, $transitionKey, $actorRole)
                    : $this->workflowEngine->resolveTarget($applicationId, (string) $fromStatus, $toStatus, $actorRole);
            }
            if ((string) $versionedTransition['from'] !== (string) $fromStatus
                || (string) $versionedTransition['to'] !== $toStatus
                || !empty($versionedTransition['is_correction'])) {
                throw new UserVisibleException('PLACEMENT_TRANSITION_INVALID', 'Named workflow transition does not match the submitted application state.');
            }
        } else {
            if ($serviceCapability) {
                throw new UserVisibleException('WORKFLOW_TRANSITION_UNAVAILABLE', 'Workflow transition is unavailable.');
            }
            if ((int) ($app['opted_out'] ?? 0) === 1 && $toStatus !== 'idle') {
                throw new UserVisibleException('PLACEMENT_CANDIDATE_OPTED_OUT', 'Candidate has opted out of placement movement.');
            }
            if ($toStatus === 'placed') {
                if ($this->setting('placement_freeze', '0') === '1' && $actorRole !== 'admin') {
                    throw new UserVisibleException('PLACEMENT_FROZEN', 'Placement decisions are frozen. Admin override is required.');
                }
                $alreadyPlaced = (int) ($app['placed_company_id'] ?? 0);
                if ($alreadyPlaced > 0 && $alreadyPlaced !== (int) $app['company_id'] && $this->setting('allow_offer_upgrade', '0') !== '1') {
                    throw new UserVisibleException('PLACEMENT_UPGRADE_DISABLED', 'Candidate already has a placement. Offer upgrades are disabled.');
                }
            }
            if (!$this->workflow->canTransition($fromStatus, $toStatus, $actorRole)) {
                throw new UserVisibleException('PLACEMENT_TRANSITION_FORBIDDEN', 'Your role cannot move this application through that workflow transition.');
            }
        }

            $now = cpe_now();
            $effects = $versionedTransition['effects'] ?? [];
            $acceptOffer = $versionedTransition !== null
                ? in_array('placement.accept_offer', $effects, true)
                : $toStatus === 'placed';
            $moveToOpportunity = $versionedTransition !== null
                ? in_array('presence.move_to_opportunity', $effects, true)
                : in_array($toStatus, ['intransit', 'arrived', 'requested', 'sendin', 'inside', 'exit', 'requestaway', 'sendaway'], true);
            $returnToControl = $versionedTransition !== null
                ? in_array('presence.return_to_control', $effects, true)
                : $toStatus === 'sent';
            $this->statusWriter->changeStatus(
                $applicationId,
                (string) $fromStatus,
                (int) $app['aggregate_version'],
                $toStatus,
                $actorId,
                $actorRole,
                $note,
                $now,
                ['transition_key' => (string) ($versionedTransition['key'] ?? '')],
                $actorServiceAccountId,
            );

            if ($acceptOffer) {
                $this->acceptCandidateOffer($app, $now);
                if (($versionedTransition === null || in_array('placement.clear_competing_applications', $effects, true))
                    && $this->setting('allow_offer_upgrade', '0') !== '1') {
                    $this->clearCompetingActiveApplications(
                        $app,
                        $actorId,
                        $actorRole,
                        $now,
                        $actorServiceAccountId,
                    );
                }
            } elseif ($moveToOpportunity) {
                $this->updateCandidateLocation($app, (string) $app['company_code'], $now);
            } elseif ($returnToControl) {
                $this->updateCandidateLocation($app, 'CP', $now);
                if ($versionedTransition === null || in_array('placement.start_next_scheduled', $effects, true)) {
                    $this->handoffToNextScheduledApplication(
                        $app,
                        $applicationId,
                        $actorId,
                        $actorRole,
                        $now,
                        $actorServiceAccountId,
                    );
                }
            }

            if ($versionedTransition !== null) {
                $this->workflowEngine?->recordAppliedTransition(
                    $applicationId,
                    $versionedTransition,
                    $actorId,
                    $actorRole,
                    '',
                    $note,
                    ['source' => 'placement.transition'],
                    $actorServiceAccountId,
                );
            }
            if ($acceptOffer) {
                cpe_context()->events()->dispatch(new DomainEvent(
                    'placement.offer.accepted',
                    'placement_application',
                    (string) ($app['public_id'] ?? '') ?: 'application_' . $applicationId,
                    'placement',
                    [
                        'application_id' => $applicationId,
                        'candidate_public_id' => $this->publicIdFor('candidates', (int) $app['candidate_id']),
                        'company_public_id' => $this->publicIdFor('companies', (int) $app['company_id']),
                    ],
                    $now
                ));
            }
            Auth::audit(
                $actorId,
                'transition',
                'application',
                $applicationId,
                "{$fromStatus} -> {$toStatus}",
                $this->pdo,
                $actorServiceAccountId,
            );
            $this->synchronizeDurableDomain();
        });
    }

    public function moveNext(int $applicationId, ?int $actorId, string $actorRole, string $note = '', string $expectedFromStatus = ''): string
    {
        return $this->transactional(function () use ($applicationId, $actorId, $actorRole, $note, $expectedFromStatus): string {
            $stmt = $this->pdo->prepare('SELECT current_status FROM applications WHERE id = ?');
            $stmt->execute([$applicationId]);
            $from = $stmt->fetchColumn();
            if (!$from) {
                throw new UserVisibleException('PLACEMENT_APPLICATION_NOT_FOUND', 'Application not found.');
            }
            $preferredTransition = $this->workflowEngine?->preferredTransition($applicationId);
            $next = $preferredTransition !== null
                ? (string) $preferredTransition['to']
                : $this->workflow->nextStatus((string) $from);
            if (!$next) {
                throw new UserVisibleException('PLACEMENT_APPLICATION_FINAL', 'This application is already at the final status.');
            }
            $this->transition($applicationId, $next, $actorId, $actorRole, $note, $expectedFromStatus);
            return $next;
        });
    }

    public function moveTo(
        int $applicationId,
        string $toStatus,
        string $transitionKey,
        ?int $actorId,
        string $actorRole,
        string $note = '',
        string $expectedFromStatus = '',
        ?int $actorServiceAccountId = null,
        bool $serviceCapability = false,
    ): string {
        if ($toStatus === '') {
            throw new UserVisibleException('PLACEMENT_TRANSITION_REQUIRED', 'Workflow transition target is required.');
        }
        $this->transition(
            $applicationId,
            $toStatus,
            $actorId,
            $actorRole,
            $note,
            $expectedFromStatus,
            $transitionKey,
            $actorServiceAccountId,
            $serviceCapability,
        );
        return $toStatus;
    }

    public function returnToIdle(int $applicationId, ?int $actorId, string $actorRole, string $reason, string $note = '', string $expectedFromStatus = ''): void
    {
        $this->transactional(function () use ($applicationId, $actorId, $actorRole, $reason, $note, $expectedFromStatus): void {
        $reason = trim($reason) !== '' ? trim($reason) : 'operator_return';
        $stmt = $this->pdo->prepare(
            'SELECT a.*, c.external_id, c.current_location, co.code AS company_code
             FROM applications a
             JOIN candidates c ON c.id = a.candidate_id
             JOIN companies co ON co.id = a.company_id
             WHERE a.id = ?'
        );
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
        if (!$app) {
            throw new UserVisibleException('PLACEMENT_APPLICATION_NOT_FOUND', 'Application not found.');
        }

        $fromStatus = (string) $app['current_status'];
        if ($expectedFromStatus !== '' && $expectedFromStatus !== $fromStatus) {
            throw new UserVisibleException('PLACEMENT_BOARD_STALE', 'This board card is stale. Reload the board before moving it.');
        }
        $correctionTransition = null;
        $returnState = $this->workflow->initialStateKey();
        if ($this->workflowEngine !== null) {
            $correctionTransition = $this->workflowEngine->resolveCorrection($applicationId, $actorRole, $reason);
            $returnState = (string) $correctionTransition['to'];
        } elseif (!in_array($actorRole, ['admin', 'control', 'placement', 'company'], true)) {
            throw new UserVisibleException('PLACEMENT_CORRECTION_FORBIDDEN', 'Your role cannot return applications to idle.');
        }
        if ($fromStatus === $returnState) {
            throw new UserVisibleException('PLACEMENT_ALREADY_INITIAL', 'Application is already at the initial workflow state.');
        }
        if ($correctionTransition === null && $this->workflow->isTerminal($fromStatus)) {
            throw new UserVisibleException('PLACEMENT_CORRECTION_INVALID', 'Completed applications cannot be returned from the board.');
        }

            $now = cpe_now();
            $eventNote = 'Returned to ' . $returnState . ': ' . $reason . (trim($note) !== '' ? '. ' . trim($note) : '');
            $changed = $this->statusWriter->changeStatus(
                $applicationId,
                $fromStatus,
                (int) $app['aggregate_version'],
                $returnState,
                $actorId,
                $actorRole,
                $eventNote,
                $now,
                ['source' => 'placement.return_to_idle'],
            );
            $clearWaitlist = $this->pdo->prepare(
                'UPDATE applications SET waitlist_rank = NULL WHERE id = ? AND aggregate_version = ?',
            );
            $clearWaitlist->execute([$applicationId, (int) $changed['aggregate_version']]);
            if ($clearWaitlist->rowCount() !== 1) {
                throw new RuntimeException('Application changed while return-to-idle metadata was applied.');
            }
            $this->updateCandidateLocation($app, 'CP', $now);
            $slots = $this->pdo->prepare("UPDATE application_slot_assignments SET assignment_status = ?, updated_at = ? WHERE application_id = ? AND assignment_status != 'cancelled'");
            $slots->execute(['cancelled', $now, $applicationId]);

            if ($correctionTransition !== null) {
                $this->workflowEngine?->recordAppliedTransition(
                    $applicationId,
                    $correctionTransition,
                    $actorId,
                    $actorRole,
                    $reason,
                    $note,
                    ['source' => 'placement.return_to_idle']
                );
            }
            Auth::audit($actorId, 'application.return_to_idle', 'application', $applicationId, $eventNote);
            $this->synchronizeDurableDomain();
        });
    }

    public function candidate(int $candidateId, ?array $user = null): ?array
    {
        $role = (string) ($user['role'] ?? 'admin');
        $stmt = $this->pdo->prepare('SELECT * FROM candidates WHERE id = ?');
        $stmt->execute([$candidateId]);
        $candidate = $stmt->fetch();
        if (!$candidate) {
            return null;
        }
        $appWhere = ['a.candidate_id = ?'];
        $appParams = [$candidateId];
        if ($this->isCompanyScopedUser($user)) {
            $scope = strtoupper(trim((string) ($user['scope_value'] ?? '')));
            if ($scope === '') {
                return null;
            }
            $appWhere[] = 'co.code = ?';
            $appParams[] = $scope;
        }
        if ($user !== null && !in_array($role, ['admin', 'control', 'auditor', 'company'], true)) {
            $visibleStatuses = $this->visibleStatusesForRole($role);
            $appWhere[] = 'a.current_status IN (' . implode(',', array_fill(0, count($visibleStatuses), '?')) . ')';
            array_push($appParams, ...$visibleStatuses);
        }

        $apps = $this->pdo->prepare(
            'SELECT a.*, co.code AS company_code, co.name AS company_name,
                    pc.code AS previous_company_code, pc.name AS previous_company_name,
                    nc.code AS next_company_code, nc.name AS next_company_name,
                    c.current_location
             FROM applications a
             JOIN candidates c ON c.id = a.candidate_id
             JOIN companies co ON co.id = a.company_id
             LEFT JOIN companies pc ON pc.id = a.previous_company_id
             LEFT JOIN companies nc ON nc.id = a.next_company_id
             WHERE ' . implode(' AND ', $appWhere) . '
             ORDER BY co.code'
        );
        $apps->execute($appParams);
        $applications = $apps->fetchAll();
        $slotSummaries = $this->applicationSlotSummaries();
        foreach ($applications as &$application) {
            $application['slot_assignment_summary'] = $slotSummaries[(int) $application['id']] ?? '';
            $application['route_summary'] = $this->routeSummary($application);
            if (!$this->canSeeCrossCompanyMovement($user)) {
                $application['route_summary'] = '';
            }
        }
        unset($application);
        if ($user !== null
            && $applications === []
            && !Auth::hasCapability($user, 'placement.sensitive.view')) {
            return null;
        }

        $eventWhere = ['e.candidate_id = ?'];
        $eventParams = [$candidateId];
        if ($this->isCompanyScopedUser($user)) {
            $eventWhere[] = 'co.code = ?';
            $eventParams[] = strtoupper(trim((string) ($user['scope_value'] ?? '')));
        }
        if ($user !== null && !in_array($role, ['admin', 'control', 'auditor', 'company'], true)) {
            $visibleStatuses = $this->visibleStatusesForRole($role);
            $eventWhere[] = '(e.from_status IN (' . implode(',', array_fill(0, count($visibleStatuses), '?')) . ') OR e.to_status IN (' . implode(',', array_fill(0, count($visibleStatuses), '?')) . '))';
            array_push($eventParams, ...$visibleStatuses, ...$visibleStatuses);
        }

        $events = $this->pdo->prepare(
            'SELECT e.*, co.code AS company_code, co.name AS company_name, u.name AS actor_name
             FROM events e
             JOIN companies co ON co.id = e.company_id
             LEFT JOIN users u ON u.id = e.actor_user_id
             WHERE ' . implode(' AND ', $eventWhere) . '
             ORDER BY e.id DESC'
        );
        $events->execute($eventParams);
        return [
            'candidate' => $this->applyCandidateVisibility($candidate, $user),
            'applications' => $applications,
            'events' => array_map(fn (array $event): array => $this->applyEventVisibility($event, $user), $events->fetchAll()),
        ];
    }

    public function candidates(): array
    {
        return $this->pdo->query('SELECT * FROM candidates ORDER BY external_id')->fetchAll();
    }

    public function companies(): array
    {
        return $this->pdo->query('SELECT * FROM companies ORDER BY code')->fetchAll();
    }

    public function companyExists(string $companyCode): bool
    {
        $companyCode = strtoupper(trim($companyCode));
        if ($companyCode === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM companies WHERE code = ?');
        $stmt->execute([$companyCode]);
        return (bool) $stmt->fetchColumn();
    }

    public function applications(): array
    {
        return $this->pdo->query(
            'SELECT a.*, c.external_id AS candidate_external_id, c.name AS candidate_name,
                    co.code AS company_code, co.name AS company_name
             FROM applications a
             JOIN candidates c ON c.id = a.candidate_id
             JOIN companies co ON co.id = a.company_id
             ORDER BY co.code, c.external_id'
        )->fetchAll();
    }

    public function companyRounds(?int $companyId = null): array
    {
        $sql = 'SELECT cr.*, co.code AS company_code, co.name AS company_name
                FROM company_rounds cr
                JOIN companies co ON co.id = cr.company_id';
        $params = [];
        if ($companyId !== null) {
            $sql .= ' WHERE cr.company_id = ?';
            $params[] = $companyId;
        }
        $sql .= ' ORDER BY co.code, cr.sequence, cr.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function companyRoundSummaries(): array
    {
        $rounds = $this->pdo->query(
            'SELECT company_id, sequence, label
             FROM company_rounds
             ORDER BY company_id, sequence, id'
        )->fetchAll();
        $parts = [];
        foreach ($rounds as $round) {
            $companyId = (int) $round['company_id'];
            $parts[$companyId] ??= [];
            $parts[$companyId][] = (int) $round['sequence'] . '. ' . (string) $round['label'];
        }
        $summaries = [];
        foreach ($parts as $companyId => $items) {
            $summaries[$companyId] = implode(' -> ', $items);
        }
        return $summaries;
    }

    public function roundPanelists(?int $roundId = null): array
    {
        $sql = 'SELECT rp.*, cr.sequence AS round_sequence, cr.label AS round_label,
                       co.code AS company_code, co.name AS company_name
                FROM round_panelists rp
                JOIN company_rounds cr ON cr.id = rp.round_id
                JOIN companies co ON co.id = cr.company_id';
        $params = [];
        if ($roundId !== null) {
            $sql .= ' WHERE rp.round_id = ?';
            $params[] = $roundId;
        }
        $sql .= ' ORDER BY co.code, cr.sequence, rp.sequence, rp.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function roundSchedules(?int $roundId = null): array
    {
        $sql = 'SELECT rs.*, cr.sequence AS round_sequence, cr.label AS round_label,
                       co.code AS company_code, co.name AS company_name
                FROM round_schedules rs
                JOIN company_rounds cr ON cr.id = rs.round_id
                JOIN companies co ON co.id = cr.company_id';
        $params = [];
        if ($roundId !== null) {
            $sql .= ' WHERE rs.round_id = ?';
            $params[] = $roundId;
        }
        $sql .= ' ORDER BY co.code, cr.sequence, rs.schedule_day, rs.sequence, rs.starts_at, rs.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function companyScheduleSummaries(): array
    {
        $rows = $this->pdo->query(
            'SELECT cr.company_id, cr.label AS round_label, rs.room, rs.schedule_day,
                    rs.starts_at, rs.ends_at, rs.capacity, rs.schedule_status
             FROM round_schedules rs
             JOIN company_rounds cr ON cr.id = rs.round_id
             ORDER BY cr.company_id, cr.sequence, rs.schedule_day, rs.sequence, rs.starts_at, rs.id'
        )->fetchAll();
        $parts = [];
        $counts = [];
        foreach ($rows as $row) {
            $companyId = (int) $row['company_id'];
            $counts[$companyId] = ($counts[$companyId] ?? 0) + 1;
            if (count($parts[$companyId] ?? []) >= 4) {
                continue;
            }
            $label = (string) $row['round_label'];
            $day = trim((string) ($row['schedule_day'] ?? ''));
            $room = trim((string) ($row['room'] ?? ''));
            $startsAt = trim((string) ($row['starts_at'] ?? ''));
            $endsAt = trim((string) ($row['ends_at'] ?? ''));
            $capacity = (int) ($row['capacity'] ?? 0);
            $time = $startsAt !== '' || $endsAt !== '' ? trim($startsAt . '-' . $endsAt, '-') : '';
            $detail = $label;
            if ($day !== '') {
                $detail .= ' ' . $day;
            }
            if ($room !== '') {
                $detail .= ' @ ' . $room;
            }
            if ($time !== '') {
                $detail .= ' ' . $time;
            }
            if ($capacity > 0) {
                $detail .= ' cap ' . $capacity;
            }
            if (!$this->scheduleIsActive($row)) {
                $detail .= ' (' . (string) $row['schedule_status'] . ')';
            }
            $parts[$companyId] ??= [];
            $parts[$companyId][] = $detail;
        }
        $summaries = [];
        foreach ($parts as $companyId => $items) {
            $remaining = max(0, ($counts[$companyId] ?? 0) - count($items));
            $summaries[$companyId] = implode('; ', $items) . ($remaining > 0 ? ' +' . $remaining . ' more' : '');
        }
        return $summaries;
    }

    public function companyPanelSummaries(): array
    {
        $rows = $this->pdo->query(
            'SELECT cr.company_id, cr.label AS round_label, rp.name, rp.role, rp.availability_status
             FROM round_panelists rp
             JOIN company_rounds cr ON cr.id = rp.round_id
             ORDER BY cr.company_id, cr.sequence, rp.sequence, rp.id'
        )->fetchAll();
        $parts = [];
        $counts = [];
        foreach ($rows as $row) {
            $companyId = (int) $row['company_id'];
            $counts[$companyId] = ($counts[$companyId] ?? 0) + 1;
            if (count($parts[$companyId] ?? []) >= 4) {
                continue;
            }
            $label = (string) $row['round_label'];
            $name = (string) $row['name'];
            $role = trim((string) ($row['role'] ?? ''));
            $availability = $this->normalizePanelistAvailabilityStatus((string) ($row['availability_status'] ?? 'active'));
            $details = array_values(array_filter([
                $role,
                $availability !== 'active' ? $availability : '',
            ]));
            $parts[$companyId] ??= [];
            $parts[$companyId][] = $label . ': ' . $name . ($details !== [] ? ' (' . implode(', ', $details) . ')' : '');
        }
        $summaries = [];
        foreach ($parts as $companyId => $items) {
            $remaining = max(0, ($counts[$companyId] ?? 0) - count($items));
            $summaries[$companyId] = implode('; ', $items) . ($remaining > 0 ? ' +' . $remaining . ' more' : '');
        }
        return $summaries;
    }

    public function applicationSlotAssignments(?int $applicationId = null): array
    {
        $sql = 'SELECT asa.*, c.external_id AS candidate_external_id, c.name AS candidate_name,
                       co.code AS company_code, co.name AS company_name,
                       cr.sequence AS round_sequence, cr.label AS round_label,
                       rs.sequence AS schedule_sequence, rs.room, rs.schedule_day,
                       rs.starts_at, rs.ends_at, rs.capacity
                FROM application_slot_assignments asa
                JOIN applications a ON a.id = asa.application_id
                JOIN candidates c ON c.id = a.candidate_id
                JOIN companies co ON co.id = a.company_id
                JOIN round_schedules rs ON rs.id = asa.round_schedule_id
                JOIN company_rounds cr ON cr.id = rs.round_id';
        $params = [];
        if ($applicationId !== null) {
            $sql .= ' WHERE asa.application_id = ?';
            $params[] = $applicationId;
        }
        $sql .= ' ORDER BY co.code, c.external_id, asa.sequence, cr.sequence, rs.schedule_day, rs.sequence, rs.starts_at, asa.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function applicationSlotSummaries(): array
    {
        $rows = $this->pdo->query(
            'SELECT asa.application_id, cr.label AS round_label, rs.room, rs.schedule_day,
                    rs.starts_at, rs.ends_at, asa.assignment_status
             FROM application_slot_assignments asa
             JOIN round_schedules rs ON rs.id = asa.round_schedule_id
             JOIN company_rounds cr ON cr.id = rs.round_id
             ORDER BY asa.application_id, asa.sequence, cr.sequence, rs.schedule_day, rs.sequence, rs.starts_at, asa.id'
        )->fetchAll();
        $parts = [];
        $counts = [];
        foreach ($rows as $row) {
            $applicationId = (int) $row['application_id'];
            $counts[$applicationId] = ($counts[$applicationId] ?? 0) + 1;
            if (count($parts[$applicationId] ?? []) >= 3) {
                continue;
            }
            $label = (string) $row['round_label'];
            $day = trim((string) ($row['schedule_day'] ?? ''));
            $room = trim((string) ($row['room'] ?? ''));
            $startsAt = trim((string) ($row['starts_at'] ?? ''));
            $endsAt = trim((string) ($row['ends_at'] ?? ''));
            $status = trim((string) ($row['assignment_status'] ?? ''));
            $time = $startsAt !== '' || $endsAt !== '' ? trim($startsAt . '-' . $endsAt, '-') : '';
            $detail = $label;
            if ($day !== '') {
                $detail .= ' ' . $day;
            }
            if ($room !== '') {
                $detail .= ' @ ' . $room;
            }
            if ($time !== '') {
                $detail .= ' ' . $time;
            }
            if ($status !== '' && $status !== 'assigned') {
                $detail .= ' (' . $status . ')';
            }
            $parts[$applicationId] ??= [];
            $parts[$applicationId][] = $detail;
        }
        $summaries = [];
        foreach ($parts as $applicationId => $items) {
            $remaining = max(0, ($counts[$applicationId] ?? 0) - count($items));
            $summaries[$applicationId] = implode('; ', $items) . ($remaining > 0 ? ' +' . $remaining . ' more' : '');
        }
        return $summaries;
    }

    public function slotAssignmentSuggestions(string $companyCode = ''): array
    {
        $context = $this->slotPlanningContext($companyCode);
        $roundsByCompany = $context['rounds_by_company'];
        $schedulesByCompany = $context['schedules_by_company'];
        $panelistsByRound = $context['panelists_by_round'];
        $apps = $context['applications'];

        $assignedRounds = $this->activeAssignedRoundsByApplication();
        $latestAssignedEnd = $this->latestAssignedSlotEndByApplication();
        $candidateSlotWindows = $this->candidateSchedulingWindows();
        $scheduleBufferMinutes = $this->schedulingBufferMinutes();
        $slotPlannerStrategy = $this->slotPlannerStrategy();
        $suggestions = [];
        foreach ($apps as $app) {
            $companyId = (int) $app['company_id'];
            $applicationId = (int) $app['application_id'];
            $candidateId = (int) $app['candidate_id'];
            $rounds = $roundsByCompany[$companyId] ?? [];
            if ($rounds === []) {
                $suggestions[] = [
                    ...$app,
                    'round_schedule_id' => null,
                    'round_sequence' => '',
                    'round_label' => '',
                    'schedule_sequence' => '',
                    'room' => '',
                    'schedule_day' => '',
                    'starts_at' => '',
                    'ends_at' => '',
                    'capacity' => '',
                    'assigned_count' => '',
                    'reason' => 'No ordered rounds configured for this company.',
                ];
                continue;
            }
            $lastEnd = $latestAssignedEnd[$applicationId] ?? '';
            $createdSuggestion = false;
            foreach ($rounds as $round) {
                $roundId = (int) $round['id'];
                if (isset($assignedRounds[$applicationId][$roundId])) {
                    continue;
                }
                $createdSuggestion = true;
                if (!$this->roundHasActivePanelist($roundId, $panelistsByRound)) {
                    $suggestions[] = [
                        ...$app,
                        'round_schedule_id' => null,
                        'round_sequence' => (int) $round['sequence'],
                        'round_label' => (string) $round['label'],
                        'schedule_sequence' => '',
                        'room' => '',
                        'schedule_day' => '',
                        'starts_at' => '',
                        'ends_at' => '',
                        'capacity' => '',
                        'assigned_count' => '',
                        'reason' => $this->roundPanelistBlockReason(),
                    ];
                    continue;
                }
                $roundSchedules = $schedulesByCompany[$companyId][$roundId] ?? [];
                $chosenIndex = $this->chooseScheduleIndex($roundSchedules, $lastEnd, $candidateSlotWindows[$candidateId] ?? [], $scheduleBufferMinutes, $slotPlannerStrategy);
                if ($chosenIndex === null) {
                    $suggestions[] = [
                        ...$app,
                        'round_schedule_id' => null,
                        'round_sequence' => (int) $round['sequence'],
                        'round_label' => (string) $round['label'],
                        'schedule_sequence' => '',
                        'room' => '',
                        'schedule_day' => '',
                        'starts_at' => '',
                        'ends_at' => '',
                        'capacity' => '',
                        'assigned_count' => '',
                        'reason' => $this->roundSchedulingBlockReason($roundSchedules, $lastEnd, $candidateSlotWindows[$candidateId] ?? [], $scheduleBufferMinutes),
                    ];
                    continue;
                }
                $schedule = $schedulesByCompany[$companyId][$roundId][$chosenIndex];
                $schedulesByCompany[$companyId][$roundId][$chosenIndex]['assigned_count']++;
                $lastEnd = $this->schedulePointKey($schedule, 'end');
                $this->addCandidateSlotWindow($candidateSlotWindows, $candidateId, $schedule);
                $suggestions[] = [
                    ...$app,
                    'round_schedule_id' => (int) $schedule['id'],
                    'round_sequence' => (int) $schedule['round_sequence'],
                    'round_label' => (string) $schedule['round_label'],
                    'schedule_sequence' => (int) $schedule['schedule_sequence'],
                    'room' => (string) $schedule['room'],
                    'schedule_day' => (string) ($schedule['schedule_day'] ?? ''),
                    'starts_at' => (string) $schedule['starts_at'],
                    'ends_at' => (string) $schedule['ends_at'],
                    'capacity' => (int) $schedule['capacity'],
                    'assigned_count' => (int) $schedule['assigned_count'],
                    'reason' => 'Suggested first available schedule row for the missing company round.',
                ];
            }
            if (!$createdSuggestion) {
                continue;
            }
        }
        return $suggestions;
    }

    public function optimizedSlotAssignmentSuggestions(string $companyCode = ''): array
    {
        $context = $this->slotPlanningContext($companyCode);
        $roundsByCompany = $context['rounds_by_company'];
        $schedulesByCompany = $context['schedules_by_company'];
        $panelistsByRound = $context['panelists_by_round'];
        $apps = $context['applications'];

        $assignedRounds = $this->activeAssignedRoundsByApplication();
        $latestAssignedEnd = $this->latestAssignedSlotEndByApplication();
        $candidateSlotWindows = $this->candidateSchedulingWindows();
        $scheduleBufferMinutes = $this->schedulingBufferMinutes();
        $slotPlannerStrategy = $this->slotPlannerStrategy();
        $candidateApplicationCounts = $this->candidateApplicationCounts($apps);

        $exactMultiRoundSuggestions = $this->exactMultiRoundOptimizedSlotAssignmentSuggestions(
            $roundsByCompany,
            $schedulesByCompany,
            $panelistsByRound,
            $apps,
            $assignedRounds,
            $latestAssignedEnd,
            $candidateSlotWindows,
            $scheduleBufferMinutes,
            $slotPlannerStrategy,
            $candidateApplicationCounts
        );
        if ($exactMultiRoundSuggestions !== null) {
            return $exactMultiRoundSuggestions;
        }

        $exactSuggestions = $this->exactOptimizedSlotAssignmentSuggestions(
            $roundsByCompany,
            $schedulesByCompany,
            $panelistsByRound,
            $apps,
            $assignedRounds,
            $latestAssignedEnd,
            $candidateSlotWindows,
            $scheduleBufferMinutes,
            $slotPlannerStrategy,
            $candidateApplicationCounts
        );
        if ($exactSuggestions !== null) {
            return $exactSuggestions;
        }

        $processedRounds = [];
        $doneApps = [];
        $suggestions = [];

        while (true) {
            $items = [];
            $blocked = [];
            foreach ($apps as $app) {
                $applicationId = (int) $app['application_id'];
                if (isset($doneApps[$applicationId])) {
                    continue;
                }
                $companyId = (int) $app['company_id'];
                $candidateId = (int) $app['candidate_id'];
                $rounds = $roundsByCompany[$companyId] ?? [];
                if ($rounds === []) {
                    $suggestions[] = $this->slotSuggestionRow($app, [
                        'round_schedule_id' => null,
                        'round_sequence' => '',
                        'round_label' => '',
                        'schedule_sequence' => '',
                        'room' => '',
                        'starts_at' => '',
                        'ends_at' => '',
                        'capacity' => '',
                        'assigned_count' => '',
                        'reason' => 'No ordered rounds configured for this company.',
                    ]);
                    $doneApps[$applicationId] = true;
                    continue;
                }

                $nextRound = null;
                foreach ($rounds as $round) {
                    $roundId = (int) $round['id'];
                    if (isset($assignedRounds[$applicationId][$roundId]) || isset($processedRounds[$applicationId][$roundId])) {
                        continue;
                    }
                    $nextRound = $round;
                    break;
                }
                if ($nextRound === null) {
                    $doneApps[$applicationId] = true;
                    continue;
                }

                $roundId = (int) $nextRound['id'];
                $lastEnd = $latestAssignedEnd[$applicationId] ?? '';
                if (!$this->roundHasActivePanelist($roundId, $panelistsByRound)) {
                    $blocked[] = [
                        'app' => $app,
                        'round' => $nextRound,
                        'reason' => $this->roundPanelistBlockReason(),
                    ];
                    continue;
                }
                $roundSchedules = $schedulesByCompany[$companyId][$roundId] ?? [];
                $safeIndexes = $this->safeScheduleIndexes($roundSchedules, $lastEnd, $candidateSlotWindows[$candidateId] ?? [], $scheduleBufferMinutes);
                if ($safeIndexes === []) {
                    $blocked[] = [
                        'app' => $app,
                        'round' => $nextRound,
                        'reason' => $this->roundSchedulingBlockReason($roundSchedules, $lastEnd, $candidateSlotWindows[$candidateId] ?? [], $scheduleBufferMinutes),
                    ];
                    continue;
                }

                $items[] = [
                    'app' => $app,
                    'round' => $nextRound,
                    'safe_indexes' => $safeIndexes,
                    'candidate_app_count' => $candidateApplicationCounts[$candidateId] ?? 1,
                    'chosen_index' => $this->chooseScheduleIndex($roundSchedules, $lastEnd, $candidateSlotWindows[$candidateId] ?? [], $scheduleBufferMinutes, $slotPlannerStrategy),
                ];
            }

            if ($items === []) {
                foreach ($blocked as $row) {
                    $app = $row['app'];
                    $round = $row['round'];
                    $processedRounds[(int) $app['application_id']][(int) $round['id']] = true;
                    $suggestions[] = $this->slotSuggestionRow($app, [
                        'round_schedule_id' => null,
                        'round_sequence' => (int) $round['sequence'],
                        'round_label' => (string) $round['label'],
                        'schedule_sequence' => '',
                        'room' => '',
                        'starts_at' => '',
                        'ends_at' => '',
                        'capacity' => '',
                        'assigned_count' => '',
                        'reason' => $row['reason'],
                    ]);
                }
                break;
            }

            usort($items, fn (array $left, array $right): int => $this->compareGlobalSlotItem($left, $right));
            $limit = $this->slotOptimizerExactLimit();
            if ($limit > 0 && count($items) <= $limit) {
                $choices = $this->exactSlotPlan($items, $schedulesByCompany, $candidateSlotWindows, $scheduleBufferMinutes, $slotPlannerStrategy);
                foreach ($items as $position => $item) {
                    $app = $item['app'];
                    $round = $item['round'];
                    if (!array_key_exists($position, $choices)) {
                        $processedRounds[(int) $app['application_id']][(int) $round['id']] = true;
                        $suggestions[] = $this->slotSuggestionRow($app, [
                            'round_schedule_id' => null,
                            'round_sequence' => (int) $round['sequence'],
                            'round_label' => (string) $round['label'],
                            'schedule_sequence' => '',
                            'room' => '',
                            'starts_at' => '',
                            'ends_at' => '',
                            'capacity' => '',
                            'assigned_count' => '',
                            'reason' => 'No globally safe schedule row could be selected by the bounded exact frontier optimizer.',
                        ]);
                        continue;
                    }
                    $this->appendOptimizedSlotSuggestion(
                        $suggestions,
                        $item,
                        (int) $choices[$position],
                        $schedulesByCompany,
                        $latestAssignedEnd,
                        $assignedRounds,
                        $processedRounds,
                        $candidateSlotWindows,
                        'Bounded exact frontier optimized suggestion for constrained active applications.'
                    );
                }
                continue;
            }

            $this->appendOptimizedSlotSuggestion(
                $suggestions,
                $items[0],
                (int) $items[0]['chosen_index'],
                $schedulesByCompany,
                $latestAssignedEnd,
                $assignedRounds,
                $processedRounds,
                $candidateSlotWindows,
                'Optimized global suggestion for constrained active applications.'
            );
        }

        return $suggestions;
    }

    private function appendOptimizedSlotSuggestion(
        array &$suggestions,
        array $item,
        int $chosenIndex,
        array &$schedulesByCompany,
        array &$latestAssignedEnd,
        array &$assignedRounds,
        array &$processedRounds,
        array &$candidateSlotWindows,
        string $reason
    ): void {
        $app = $item['app'];
        $round = $item['round'];
        $companyId = (int) $app['company_id'];
        $candidateId = (int) $app['candidate_id'];
        $applicationId = (int) $app['application_id'];
        $roundId = (int) $round['id'];
        $schedule = $schedulesByCompany[$companyId][$roundId][$chosenIndex];
        $schedulesByCompany[$companyId][$roundId][$chosenIndex]['assigned_count']++;
        $latestAssignedEnd[$applicationId] = $this->schedulePointKey($schedule, 'end');
        $assignedRounds[$applicationId][$roundId] = true;
        $processedRounds[$applicationId][$roundId] = true;
        $this->addCandidateSlotWindow($candidateSlotWindows, $candidateId, $schedule);
        $suggestions[] = $this->slotSuggestionRow($app, [
            'round_schedule_id' => (int) $schedule['id'],
            'round_sequence' => (int) $schedule['round_sequence'],
            'round_label' => (string) $schedule['round_label'],
            'schedule_sequence' => (int) $schedule['schedule_sequence'],
            'room' => (string) $schedule['room'],
            'schedule_day' => (string) ($schedule['schedule_day'] ?? ''),
            'starts_at' => (string) $schedule['starts_at'],
            'ends_at' => (string) $schedule['ends_at'],
            'capacity' => (int) $schedule['capacity'],
            'assigned_count' => (int) $schedule['assigned_count'],
            'reason' => $reason,
        ]);
    }

    private function exactMultiRoundOptimizedSlotAssignmentSuggestions(
        array $roundsByCompany,
        array $schedulesByCompany,
        array $panelistsByRound,
        array $apps,
        array $assignedRounds,
        array $latestAssignedEnd,
        array $candidateSlotWindows,
        int $scheduleBufferMinutes,
        string $slotPlannerStrategy,
        array $candidateApplicationCounts
    ): ?array {
        $limit = $this->slotOptimizerExactLimit();
        if ($limit <= 0) {
            return null;
        }

        $plans = [];
        $blockedRows = [];
        $decisionCount = 0;
        $hasMultiRoundPlan = false;
        foreach ($apps as $app) {
            $companyId = (int) $app['company_id'];
            $applicationId = (int) $app['application_id'];
            $rounds = $roundsByCompany[$companyId] ?? [];
            if ($rounds === []) {
                $blockedRows[] = $this->slotSuggestionRow($app, [
                    'round_schedule_id' => null,
                    'round_sequence' => '',
                    'round_label' => '',
                    'schedule_sequence' => '',
                    'room' => '',
                    'starts_at' => '',
                    'ends_at' => '',
                    'capacity' => '',
                    'assigned_count' => '',
                    'reason' => 'No ordered rounds configured for this company.',
                ]);
                continue;
            }

            $missingRounds = [];
            foreach ($rounds as $round) {
                $roundId = (int) $round['id'];
                if (!isset($assignedRounds[$applicationId][$roundId])) {
                    $missingRounds[] = $round;
                }
            }
            if ($missingRounds === []) {
                continue;
            }
            if (count($missingRounds) > 1) {
                $hasMultiRoundPlan = true;
            }
            $decisionCount += count($missingRounds);
            $plans[] = ['app' => $app, 'rounds' => $missingRounds];
        }

        if (!$hasMultiRoundPlan || $decisionCount > $limit) {
            return null;
        }

        usort($plans, function (array $left, array $right) use ($candidateApplicationCounts): int {
            $leftApp = $left['app'];
            $rightApp = $right['app'];
            foreach ([
                $this->compareNumbers($candidateApplicationCounts[(int) $leftApp['candidate_id']] ?? 1, $candidateApplicationCounts[(int) $rightApp['candidate_id']] ?? 1),
                $this->compareNumbers(count($left['rounds']), count($right['rounds'])),
                $this->compareNumbers($this->waitlistScore($leftApp), $this->waitlistScore($rightApp)),
                strcmp((string) $leftApp['updated_at'], (string) $rightApp['updated_at']),
                strcmp((string) $leftApp['candidate_external_id'], (string) $rightApp['candidate_external_id']),
                strcmp((string) $leftApp['company_code'], (string) $rightApp['company_code']),
            ] as $comparison) {
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return 0;
        });

        $bestRows = [];
        $bestCount = -1;
        $planCount = count($plans);
        $initialPositions = array_fill(0, $planCount, 0);

        $remainingDecisions = function (array $positions) use ($plans, $planCount): int {
            $remaining = 0;
            for ($i = 0; $i < $planCount; $i++) {
                $remaining += max(0, count($plans[$i]['rounds']) - (int) ($positions[$i] ?? 0));
            }
            return $remaining;
        };

        $search = function (
            array $positions,
            array $lastEnds,
            array $scheduleUse,
            array $windows,
            array $rows,
            int $assignedCount
        ) use (
            &$search,
            &$bestRows,
            &$bestCount,
            $remainingDecisions,
            $plans,
            $planCount,
            $schedulesByCompany,
            $panelistsByRound,
            $candidateApplicationCounts,
            $scheduleBufferMinutes,
            $slotPlannerStrategy
        ): void {
            $remaining = $remainingDecisions($positions);
            if ($assignedCount + $remaining < $bestCount) {
                return;
            }
            if ($remaining === 0) {
                if ($assignedCount > $bestCount) {
                    $bestCount = $assignedCount;
                    $bestRows = $rows;
                }
                return;
            }

            $items = [];
            for ($planIndex = 0; $planIndex < $planCount; $planIndex++) {
                $position = (int) ($positions[$planIndex] ?? 0);
                if (!isset($plans[$planIndex]['rounds'][$position])) {
                    continue;
                }
                $plan = $plans[$planIndex];
                $app = $plan['app'];
                $round = $plan['rounds'][$position];
                $roundId = (int) $round['id'];
                $applicationId = (int) $app['application_id'];
                $candidateId = (int) $app['candidate_id'];
                $companyId = (int) $app['company_id'];
                $lastEnd = (string) ($lastEnds[$applicationId] ?? '');
                $roundSchedules = $schedulesByCompany[$companyId][$roundId] ?? [];
                if (!$this->roundHasActivePanelist($roundId, $panelistsByRound)) {
                    $safeIndexes = [];
                    $reason = $this->roundPanelistBlockReason();
                } else {
                    $safeIndexes = $this->safeScheduleIndexes($roundSchedules, $lastEnd, $windows[$candidateId] ?? [], $scheduleBufferMinutes);
                    $reason = $safeIndexes === []
                        ? $this->roundSchedulingBlockReason($roundSchedules, $lastEnd, $windows[$candidateId] ?? [], $scheduleBufferMinutes)
                        : '';
                }
                $items[] = [
                    'plan_index' => $planIndex,
                    'app' => $app,
                    'round' => $round,
                    'safe_indexes' => $safeIndexes,
                    'candidate_app_count' => $candidateApplicationCounts[$candidateId] ?? 1,
                    'reason' => $reason,
                ];
            }

            usort($items, fn (array $left, array $right): int => $this->compareGlobalSlotItem($left, $right));
            $item = $items[0];
            $planIndex = (int) $item['plan_index'];
            $app = $item['app'];
            $round = $item['round'];
            $companyId = (int) $app['company_id'];
            $candidateId = (int) $app['candidate_id'];
            $applicationId = (int) $app['application_id'];
            $roundId = (int) $round['id'];
            $roundSchedules = $schedulesByCompany[$companyId][$roundId] ?? [];
            $options = array_map(
                fn (int|string $index): array => ['index' => (int) $index, 'schedule' => $roundSchedules[(int) $index]],
                $item['safe_indexes']
            );
            if ($slotPlannerStrategy !== 'sequence') {
                usort($options, fn (array $left, array $right): int => $this->compareScheduleChoice($left, $right, $slotPlannerStrategy));
            }

            foreach ($options as $option) {
                $schedule = $option['schedule'];
                $scheduleId = (int) $schedule['id'];
                $capacity = (int) $schedule['capacity'];
                $used = (int) ($scheduleUse[$scheduleId] ?? 0);
                if ($capacity > 0 && (int) $schedule['assigned_count'] + $used >= $capacity) {
                    continue;
                }
                if ($this->candidateSlotConflict($windows[$candidateId] ?? [], $schedule, $scheduleBufferMinutes) !== '') {
                    continue;
                }

                $nextPositions = $positions;
                $nextPositions[$planIndex] = (int) $nextPositions[$planIndex] + 1;
                $nextLastEnds = $lastEnds;
                $nextLastEnds[$applicationId] = $this->schedulePointKey($schedule, 'end');
                $nextUse = $scheduleUse;
                $nextUse[$scheduleId] = $used + 1;
                $nextWindows = $windows;
                $this->addCandidateSlotWindow($nextWindows, $candidateId, $schedule);
                $nextRows = $rows;
                $nextRows[] = $this->slotSuggestionRow($app, [
                    'round_schedule_id' => (int) $schedule['id'],
                    'round_sequence' => (int) $schedule['round_sequence'],
                    'round_label' => (string) $schedule['round_label'],
                    'schedule_sequence' => (int) $schedule['schedule_sequence'],
                    'room' => (string) $schedule['room'],
                    'schedule_day' => (string) ($schedule['schedule_day'] ?? ''),
                    'starts_at' => (string) $schedule['starts_at'],
                    'ends_at' => (string) $schedule['ends_at'],
                    'capacity' => (int) $schedule['capacity'],
                    'assigned_count' => (int) $schedule['assigned_count'],
                    'reason' => 'Bounded exact multi-round optimized suggestion for constrained active applications.',
                ]);
                $search($nextPositions, $nextLastEnds, $nextUse, $nextWindows, $nextRows, $assignedCount + 1);
            }

            $nextPositions = $positions;
            $nextPositions[$planIndex] = count($plans[$planIndex]['rounds']);
            $nextRows = $rows;
            $nextRows[] = $this->slotSuggestionRow($app, [
                'round_schedule_id' => null,
                'round_sequence' => (int) $round['sequence'],
                'round_label' => (string) $round['label'],
                'schedule_sequence' => '',
                'room' => '',
                'starts_at' => '',
                'ends_at' => '',
                'capacity' => '',
                'assigned_count' => '',
                'reason' => (string) ($item['reason'] ?: 'No globally safe schedule row could be selected by the bounded exact multi-round optimizer.'),
            ]);
            $search($nextPositions, $lastEnds, $scheduleUse, $windows, $nextRows, $assignedCount);
        };

        $search($initialPositions, $latestAssignedEnd, [], $candidateSlotWindows, [], 0);
        return [...$bestRows, ...$blockedRows];
    }

    private function exactOptimizedSlotAssignmentSuggestions(
        array $roundsByCompany,
        array $schedulesByCompany,
        array $panelistsByRound,
        array $apps,
        array $assignedRounds,
        array $latestAssignedEnd,
        array $candidateSlotWindows,
        int $scheduleBufferMinutes,
        string $slotPlannerStrategy,
        array $candidateApplicationCounts
    ): ?array {
        $limit = $this->slotOptimizerExactLimit();
        if ($limit <= 0) {
            return null;
        }

        $items = [];
        $blockedRows = [];
        foreach ($apps as $app) {
            $companyId = (int) $app['company_id'];
            $applicationId = (int) $app['application_id'];
            $candidateId = (int) $app['candidate_id'];
            $rounds = $roundsByCompany[$companyId] ?? [];
            if ($rounds === []) {
                $blockedRows[] = $this->slotSuggestionRow($app, [
                    'round_schedule_id' => null,
                    'round_sequence' => '',
                    'round_label' => '',
                    'schedule_sequence' => '',
                    'room' => '',
                    'starts_at' => '',
                    'ends_at' => '',
                    'capacity' => '',
                    'assigned_count' => '',
                    'reason' => 'No ordered rounds configured for this company.',
                ]);
                continue;
            }

            $missingRounds = [];
            foreach ($rounds as $round) {
                $roundId = (int) $round['id'];
                if (!isset($assignedRounds[$applicationId][$roundId])) {
                    $missingRounds[] = $round;
                }
            }
            if ($missingRounds === []) {
                continue;
            }
            if (count($missingRounds) > 1) {
                return null;
            }

            $round = $missingRounds[0];
            $roundId = (int) $round['id'];
            $lastEnd = $latestAssignedEnd[$applicationId] ?? '';
            if (!$this->roundHasActivePanelist($roundId, $panelistsByRound)) {
                $blockedRows[] = $this->slotSuggestionRow($app, [
                    'round_schedule_id' => null,
                    'round_sequence' => (int) $round['sequence'],
                    'round_label' => (string) $round['label'],
                    'schedule_sequence' => '',
                    'room' => '',
                    'starts_at' => '',
                    'ends_at' => '',
                    'capacity' => '',
                    'assigned_count' => '',
                    'reason' => $this->roundPanelistBlockReason(),
                ]);
                continue;
            }
            $roundSchedules = $schedulesByCompany[$companyId][$roundId] ?? [];
            $safeIndexes = $this->safeScheduleIndexes($roundSchedules, $lastEnd, $candidateSlotWindows[$candidateId] ?? [], $scheduleBufferMinutes);
            if ($safeIndexes === []) {
                $blockedRows[] = $this->slotSuggestionRow($app, [
                    'round_schedule_id' => null,
                    'round_sequence' => (int) $round['sequence'],
                    'round_label' => (string) $round['label'],
                    'schedule_sequence' => '',
                    'room' => '',
                    'starts_at' => '',
                    'ends_at' => '',
                    'capacity' => '',
                    'assigned_count' => '',
                    'reason' => $this->roundSchedulingBlockReason($roundSchedules, $lastEnd, $candidateSlotWindows[$candidateId] ?? [], $scheduleBufferMinutes),
                ]);
                continue;
            }

            $items[] = [
                'app' => $app,
                'round' => $round,
                'safe_indexes' => $safeIndexes,
                'candidate_app_count' => $candidateApplicationCounts[$candidateId] ?? 1,
                'chosen_index' => $this->chooseScheduleIndex($roundSchedules, $lastEnd, $candidateSlotWindows[$candidateId] ?? [], $scheduleBufferMinutes, $slotPlannerStrategy),
            ];
        }

        if (count($items) > $limit) {
            return null;
        }

        usort($items, fn (array $left, array $right): int => $this->compareGlobalSlotItem($left, $right));
        $choices = $this->exactSlotPlan($items, $schedulesByCompany, $candidateSlotWindows, $scheduleBufferMinutes, $slotPlannerStrategy);
        $selectedRows = [];
        $skippedRows = $blockedRows;

        foreach ($items as $position => $item) {
            $app = $item['app'];
            $round = $item['round'];
            $companyId = (int) $app['company_id'];
            $roundId = (int) $round['id'];
            if (!array_key_exists($position, $choices)) {
                $skippedRows[] = $this->slotSuggestionRow($app, [
                    'round_schedule_id' => null,
                    'round_sequence' => (int) $round['sequence'],
                    'round_label' => (string) $round['label'],
                    'schedule_sequence' => '',
                    'room' => '',
                    'starts_at' => '',
                    'ends_at' => '',
                    'capacity' => '',
                    'assigned_count' => '',
                    'reason' => 'No globally safe schedule row could be selected by the bounded exact optimizer.',
                ]);
                continue;
            }

            $schedule = $schedulesByCompany[$companyId][$roundId][(int) $choices[$position]];
            $selectedRows[] = $this->slotSuggestionRow($app, [
                'round_schedule_id' => (int) $schedule['id'],
                'round_sequence' => (int) $schedule['round_sequence'],
                'round_label' => (string) $schedule['round_label'],
                'schedule_sequence' => (int) $schedule['schedule_sequence'],
                'room' => (string) $schedule['room'],
                'schedule_day' => (string) ($schedule['schedule_day'] ?? ''),
                'starts_at' => (string) $schedule['starts_at'],
                'ends_at' => (string) $schedule['ends_at'],
                'capacity' => (int) $schedule['capacity'],
                'assigned_count' => (int) $schedule['assigned_count'],
                'reason' => 'Bounded exact optimized suggestion for constrained active applications.',
            ]);
        }

        return [...$selectedRows, ...$skippedRows];
    }

    private function exactSlotPlan(
        array $items,
        array $schedulesByCompany,
        array $candidateSlotWindows,
        int $scheduleBufferMinutes,
        string $slotPlannerStrategy
    ): array {
        $bestChoices = [];
        $bestCount = -1;
        $itemCount = count($items);

        $search = function (
            int $position,
            array $choices,
            array $scheduleUse,
            array $windows,
            int $assignedCount
        ) use (&$search, &$bestChoices, &$bestCount, $items, $schedulesByCompany, $scheduleBufferMinutes, $slotPlannerStrategy, $itemCount): void {
            if ($assignedCount + ($itemCount - $position) < $bestCount) {
                return;
            }
            if ($position >= $itemCount) {
                if ($assignedCount > $bestCount) {
                    $bestCount = $assignedCount;
                    $bestChoices = $choices;
                }
                return;
            }

            $item = $items[$position];
            $app = $item['app'];
            $companyId = (int) $app['company_id'];
            $roundId = (int) $item['round']['id'];
            $candidateId = (int) $app['candidate_id'];
            $roundSchedules = $schedulesByCompany[$companyId][$roundId] ?? [];
            $options = array_map(
                fn (int|string $index): array => ['index' => (int) $index, 'schedule' => $roundSchedules[(int) $index]],
                $item['safe_indexes']
            );
            if ($slotPlannerStrategy !== 'sequence') {
                usort($options, fn (array $left, array $right): int => $this->compareScheduleChoice($left, $right, $slotPlannerStrategy));
            }

            foreach ($options as $option) {
                $schedule = $option['schedule'];
                $scheduleId = (int) $schedule['id'];
                $capacity = (int) $schedule['capacity'];
                $used = (int) ($scheduleUse[$scheduleId] ?? 0);
                if ($capacity > 0 && (int) $schedule['assigned_count'] + $used >= $capacity) {
                    continue;
                }
                if ($this->candidateSlotConflict($windows[$candidateId] ?? [], $schedule, $scheduleBufferMinutes) !== '') {
                    continue;
                }

                $nextChoices = $choices;
                $nextChoices[$position] = (int) $option['index'];
                $nextUse = $scheduleUse;
                $nextUse[$scheduleId] = $used + 1;
                $nextWindows = $windows;
                $this->addCandidateSlotWindow($nextWindows, $candidateId, $schedule);
                $search($position + 1, $nextChoices, $nextUse, $nextWindows, $assignedCount + 1);
            }

            $search($position + 1, $choices, $scheduleUse, $windows, $assignedCount);
        };

        $search(0, [], [], $candidateSlotWindows, 0);
        return $bestChoices;
    }

    public function applySlotAssignmentSuggestions(string $companyCode = '', ?int $actorId = null): array
    {
        return $this->applySlotSuggestions($this->slotAssignmentSuggestions($companyCode), $actorId);
    }

    public function applyOptimizedSlotAssignmentSuggestions(string $companyCode = '', ?int $actorId = null): array
    {
        return $this->applySlotSuggestions($this->optimizedSlotAssignmentSuggestions($companyCode), $actorId);
    }

    private function applySlotSuggestions(array $suggestions, ?int $actorId = null): array
    {
        $result = ['assigned' => [], 'skipped' => []];
        if ($suggestions === []) {
            return $result;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($suggestions as $suggestion) {
                $scheduleId = (int) ($suggestion['round_schedule_id'] ?? 0);
                if ($scheduleId <= 0) {
                    $result['skipped'][] = [
                        ...$suggestion,
                        'result' => 'skipped',
                    ];
                    continue;
                }

                $assignmentId = $this->saveApplicationSlotAssignment([
                    'application_id' => (int) $suggestion['application_id'],
                    'round_schedule_id' => $scheduleId,
                    'sequence' => (string) max(1, (int) ($suggestion['round_sequence'] ?? 1)),
                    'assignment_status' => 'assigned',
                    'notes' => 'Auto-assigned from slot suggestion.',
                ], $actorId);
                $result['assigned'][] = [
                    ...$suggestion,
                    'assignment_id' => $assignmentId,
                    'result' => 'assigned',
                    'reason' => 'Assigned first available schedule row for the company.',
                ];
            }
            Auth::audit($actorId, 'slot_assignment.apply_suggestions', 'slot_assignment', null, 'Assigned ' . count($result['assigned']) . ' suggested slot(s); skipped ' . count($result['skipped']) . '.');
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function slotPlanningContext(string $companyCode): array
    {
        $companyCode = strtoupper(trim($companyCode));
        $roundSql = 'SELECT cr.id, cr.company_id, co.code AS company_code, cr.sequence, cr.label
                     FROM company_rounds cr
                     JOIN companies co ON co.id = cr.company_id';
        $roundParams = [];
        if ($companyCode !== '') {
            $roundSql .= ' WHERE co.code = ?';
            $roundParams[] = $companyCode;
        }
        $roundSql .= ' ORDER BY co.code, cr.sequence, cr.id';
        $roundStmt = $this->pdo->prepare($roundSql);
        $roundStmt->execute($roundParams);
        $roundsByCompany = [];
        foreach ($roundStmt->fetchAll() as $round) {
            $companyId = (int) $round['company_id'];
            $roundsByCompany[$companyId] ??= [];
            $roundsByCompany[$companyId][] = $round;
        }

        $scheduleSql = 'SELECT rs.id, rs.round_id, cr.company_id, co.code AS company_code, cr.sequence AS round_sequence,
                               cr.label AS round_label, rs.sequence AS schedule_sequence, rs.room,
                               rs.schedule_day, rs.starts_at, rs.ends_at, rs.capacity, rs.schedule_status,
                               co.deadline_day, co.deadline_at,
                               (
                                   SELECT COUNT(*)
                                   FROM application_slot_assignments asa
                                   WHERE asa.round_schedule_id = rs.id
                                     AND asa.assignment_status != \'cancelled\'
                               ) AS assigned_count
                        FROM round_schedules rs
                        JOIN company_rounds cr ON cr.id = rs.round_id
                        JOIN companies co ON co.id = cr.company_id';
        $scheduleParams = [];
        if ($companyCode !== '') {
            $scheduleSql .= ' WHERE co.code = ?';
            $scheduleParams[] = $companyCode;
        }
        $scheduleSql .= ' ORDER BY co.code, cr.sequence, rs.schedule_day, rs.sequence, rs.starts_at, rs.id';
        $scheduleStmt = $this->pdo->prepare($scheduleSql);
        $scheduleStmt->execute($scheduleParams);

        $schedulesByCompany = [];
        foreach ($scheduleStmt->fetchAll() as $schedule) {
            $companyId = (int) $schedule['company_id'];
            $roundId = (int) $schedule['round_id'];
            $schedule['assigned_count'] = (int) $schedule['assigned_count'];
            $schedule['capacity'] = (int) $schedule['capacity'];
            $schedulesByCompany[$companyId] ??= [];
            $schedulesByCompany[$companyId][$roundId] ??= [];
            $schedulesByCompany[$companyId][$roundId][] = $schedule;
        }

        $panelistSql = 'SELECT rp.round_id, rp.availability_status
                        FROM round_panelists rp
                        JOIN company_rounds cr ON cr.id = rp.round_id
                        JOIN companies co ON co.id = cr.company_id';
        $panelistParams = [];
        if ($companyCode !== '') {
            $panelistSql .= ' WHERE co.code = ?';
            $panelistParams[] = $companyCode;
        }
        $panelistStmt = $this->pdo->prepare($panelistSql);
        $panelistStmt->execute($panelistParams);
        $panelistsByRound = [];
        foreach ($panelistStmt->fetchAll() as $panelist) {
            $roundId = (int) $panelist['round_id'];
            $panelistsByRound[$roundId] ??= [];
            $panelistsByRound[$roundId][] = $panelist;
        }

        $appSql = 'SELECT a.id AS application_id, a.current_status, a.waitlist_rank, a.updated_at,
                          c.external_id AS candidate_external_id, c.name AS candidate_name, c.id AS candidate_id,
                          co.id AS company_id, co.code AS company_code, co.name AS company_name
                   FROM applications a
                   JOIN candidates c ON c.id = a.candidate_id
                   JOIN companies co ON co.id = a.company_id
                   WHERE a.current_status NOT IN (\'idle\', \'placed\')';
        $appParams = [];
        if ($companyCode !== '') {
            $appSql .= ' AND co.code = ?';
            $appParams[] = $companyCode;
        }
        $appSql .= ' ORDER BY co.code, a.waitlist_rank IS NULL, a.waitlist_rank, a.updated_at, c.external_id';
        $appStmt = $this->pdo->prepare($appSql);
        $appStmt->execute($appParams);

        return [
            'rounds_by_company' => $roundsByCompany,
            'schedules_by_company' => $schedulesByCompany,
            'panelists_by_round' => $panelistsByRound,
            'applications' => $appStmt->fetchAll(),
        ];
    }

    private function activeAssignedRoundsByApplication(): array
    {
        $rows = $this->pdo->query(
            "SELECT asa.application_id, rs.round_id
             FROM application_slot_assignments asa
             JOIN round_schedules rs ON rs.id = asa.round_schedule_id
             WHERE asa.assignment_status != 'cancelled'"
        )->fetchAll();
        $rounds = [];
        foreach ($rows as $row) {
            $applicationId = (int) $row['application_id'];
            $roundId = (int) $row['round_id'];
            $rounds[$applicationId] ??= [];
            $rounds[$applicationId][$roundId] = true;
        }
        return $rounds;
    }

    private function latestAssignedSlotEndByApplication(): array
    {
        $rows = $this->pdo->query(
            "SELECT asa.application_id,
                    rs.schedule_day, rs.starts_at, rs.ends_at
             FROM application_slot_assignments asa
             JOIN round_schedules rs ON rs.id = asa.round_schedule_id
             WHERE asa.assignment_status != 'cancelled'
             ORDER BY asa.application_id, rs.schedule_day, COALESCE(NULLIF(rs.ends_at, ''), rs.starts_at)"
        )->fetchAll();
        $latest = [];
        $latestValues = [];
        foreach ($rows as $row) {
            $applicationId = (int) $row['application_id'];
            $value = $this->schedulePointValue($row, 'end');
            if ($value !== null && $value >= ($latestValues[$applicationId] ?? PHP_INT_MIN)) {
                $latestValues[$applicationId] = $value;
                $latest[$applicationId] = $this->schedulePointKey($row, 'end');
            }
        }
        return $latest;
    }

    private function candidateSchedulingWindows(): array
    {
        $windows = $this->candidateAssignedSlotWindows();
        foreach ($this->candidateUnavailableWindows() as $candidateId => $candidateWindows) {
            $windows[$candidateId] ??= [];
            foreach ($candidateWindows as $window) {
                $windows[$candidateId][] = $window;
            }
        }
        return $windows;
    }

    private function candidateAssignedSlotWindows(): array
    {
        $rows = $this->pdo->query(
            "SELECT a.candidate_id, co.code AS company_code, cr.sequence AS round_sequence,
                    cr.label AS round_label, rs.room, rs.schedule_day, rs.starts_at, rs.ends_at
             FROM application_slot_assignments asa
             JOIN applications a ON a.id = asa.application_id
             JOIN round_schedules rs ON rs.id = asa.round_schedule_id
             JOIN company_rounds cr ON cr.id = rs.round_id
             JOIN companies co ON co.id = cr.company_id
             WHERE asa.assignment_status != 'cancelled'"
        )->fetchAll();
        $windows = [];
        foreach ($rows as $row) {
            $candidateId = (int) $row['candidate_id'];
            $this->addCandidateSlotWindow($windows, $candidateId, $row);
        }
        return $windows;
    }

    private function candidateUnavailableWindows(): array
    {
        $rows = $this->pdo->query(
            "SELECT candidate_id, label, schedule_day, starts_at, ends_at, notes
             FROM candidate_unavailability_windows
             ORDER BY candidate_id, schedule_day, starts_at, ends_at"
        )->fetchAll();
        $windows = [];
        foreach ($rows as $row) {
            $candidateId = (int) $row['candidate_id'];
            $this->addCandidateSlotWindow($windows, $candidateId, [
                'block_label' => trim((string) ($row['label'] ?? 'Unavailable')),
                'schedule_day' => (string) ($row['schedule_day'] ?? ''),
                'starts_at' => (string) ($row['starts_at'] ?? ''),
                'ends_at' => (string) ($row['ends_at'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
            ]);
        }
        return $windows;
    }

    private function addCandidateSlotWindow(array &$windows, int $candidateId, array $schedule): void
    {
        $range = $this->scheduleAbsoluteMinuteRange($schedule);
        if ($range === null) {
            return;
        }
        $windows[$candidateId] ??= [];
        $windows[$candidateId][] = [
            'start' => $range[0],
            'end' => $range[1],
            'label' => $this->scheduleConflictLabel($schedule),
        ];
    }

    private function candidateSlotConflict(array $windows, array $schedule, int $bufferMinutes): string
    {
        $range = $this->scheduleAbsoluteMinuteRange($schedule);
        if ($range === null) {
            return '';
        }
        [$start, $end] = $range;
        foreach ($windows as $window) {
            $windowStart = (int) $window['start'];
            $windowEnd = (int) $window['end'];
            if ($start < $windowEnd + $bufferMinutes && $end + $bufferMinutes > $windowStart) {
                $reason = 'Candidate already has a conflicting slot at ' . (string) $window['label'] . '.';
                if ($bufferMinutes > 0) {
                    $reason .= " Requires {$bufferMinutes} minute buffer.";
                }
                return $reason;
            }
        }
        return '';
    }

    private function safeScheduleIndexes(array $roundSchedules, string $lastEnd, array $candidateWindows, int $bufferMinutes): array
    {
        $indexes = [];
        foreach ($roundSchedules as $index => $schedule) {
            if (!$this->scheduleIsActive($schedule)) {
                continue;
            }
            $hasCapacity = (int) $schedule['capacity'] === 0 || (int) $schedule['assigned_count'] < (int) $schedule['capacity'];
            $afterPrevious = $this->scheduleStartsAfter($schedule, $lastEnd);
            $beforeDeadline = $this->scheduleEndsBeforeCompanyDeadline($schedule);
            $hasCandidateConflict = $this->candidateSlotConflict($candidateWindows, $schedule, $bufferMinutes) !== '';
            if ($hasCapacity && $afterPrevious && $beforeDeadline && !$hasCandidateConflict) {
                $indexes[] = $index;
            }
        }
        return $indexes;
    }

    private function chooseScheduleIndex(array $roundSchedules, string $lastEnd, array $candidateWindows, int $bufferMinutes, string $strategy): ?int
    {
        $options = array_map(
            fn (int|string $index): array => ['index' => (int) $index, 'schedule' => $roundSchedules[(int) $index]],
            $this->safeScheduleIndexes($roundSchedules, $lastEnd, $candidateWindows, $bufferMinutes)
        );
        if ($options === []) {
            return null;
        }
        if ($strategy === 'sequence') {
            return (int) $options[0]['index'];
        }
        usort($options, function (array $left, array $right) use ($strategy): int {
            return $this->compareScheduleChoice($left, $right, $strategy);
        });
        return (int) $options[0]['index'];
    }

    private function compareScheduleChoice(array $left, array $right, string $strategy): int
    {
        $leftSchedule = $left['schedule'];
        $rightSchedule = $right['schedule'];
        if ($strategy === 'balanced') {
            $load = $this->compareNumbers(
                $this->scheduleLoadScore($leftSchedule),
                $this->scheduleLoadScore($rightSchedule)
            );
            if ($load !== 0) {
                return $load;
            }
        }
        $time = $this->compareNumbers(
            $this->schedulePointValue($leftSchedule, 'start') ?? PHP_INT_MAX,
            $this->schedulePointValue($rightSchedule, 'start') ?? PHP_INT_MAX
        );
        if ($time !== 0) {
            return $time;
        }
        $sequence = $this->compareNumbers((int) ($leftSchedule['schedule_sequence'] ?? 0), (int) ($rightSchedule['schedule_sequence'] ?? 0));
        if ($sequence !== 0) {
            return $sequence;
        }
        return $this->compareNumbers((int) $left['index'], (int) $right['index']);
    }

    private function compareGlobalSlotItem(array $left, array $right): int
    {
        foreach ([
            $this->compareNumbers((int) $left['candidate_app_count'], (int) $right['candidate_app_count']),
            $this->compareNumbers(count($left['safe_indexes']), count($right['safe_indexes'])),
            $this->compareNumbers($this->waitlistScore($left['app']), $this->waitlistScore($right['app'])),
            strcmp((string) $left['app']['updated_at'], (string) $right['app']['updated_at']),
            strcmp((string) $left['app']['candidate_external_id'], (string) $right['app']['candidate_external_id']),
            strcmp((string) $left['app']['company_code'], (string) $right['app']['company_code']),
        ] as $comparison) {
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return 0;
    }

    private function waitlistScore(array $app): int
    {
        return $app['waitlist_rank'] === null || $app['waitlist_rank'] === '' ? PHP_INT_MAX : (int) $app['waitlist_rank'];
    }

    private function candidateApplicationCounts(array $apps): array
    {
        $counts = [];
        foreach ($apps as $app) {
            $candidateId = (int) $app['candidate_id'];
            $counts[$candidateId] = ($counts[$candidateId] ?? 0) + 1;
        }
        return $counts;
    }

    private function slotSuggestionRow(array $app, array $fields): array
    {
        return [...$app, 'schedule_day' => '', ...$fields];
    }

    private function roundHasActivePanelist(int $roundId, array $panelistsByRound): bool
    {
        $panelists = $panelistsByRound[$roundId] ?? [];
        if ($panelists === []) {
            return true;
        }
        foreach ($panelists as $panelist) {
            if ($this->normalizePanelistAvailabilityStatus((string) ($panelist['availability_status'] ?? 'active')) === 'active') {
                return true;
            }
        }
        return false;
    }

    private function roundPanelistBlockReason(): string
    {
        return 'All configured panelists for this round are on break or unavailable.';
    }

    private function scheduleLoadScore(array $schedule): float
    {
        $assigned = max(0, (int) ($schedule['assigned_count'] ?? 0));
        $capacity = max(0, (int) ($schedule['capacity'] ?? 0));
        if ($capacity === 0) {
            return (float) $assigned;
        }
        return $assigned / $capacity;
    }

    private function compareNumbers(int|float $left, int|float $right): int
    {
        if ($left === $right) {
            return 0;
        }
        return $left < $right ? -1 : 1;
    }

    private function scheduleAbsoluteMinuteRange(array $schedule): ?array
    {
        $startsAt = (string) ($schedule['starts_at'] ?? '');
        $endsAt = (string) ($schedule['ends_at'] ?? '');
        $start = $this->timeToMinutes($startsAt);
        if ($start === null) {
            return null;
        }
        $end = $this->timeToMinutes($endsAt);
        if ($end === null) {
            $end = $start;
        }
        if ($end < $start) {
            $end += 1440;
        }
        $dayOffset = $this->scheduleDayOffset((string) ($schedule['schedule_day'] ?? '')) * 1440;
        return [$dayOffset + $start, $dayOffset + $end];
    }

    private function schedulePointValue(array $schedule, string $edge): ?int
    {
        $range = $this->scheduleAbsoluteMinuteRange($schedule);
        if ($range === null) {
            return null;
        }
        return $edge === 'end' ? $range[1] : $range[0];
    }

    private function schedulePointKey(array $schedule, string $edge): string
    {
        $value = $this->schedulePointValue($schedule, $edge);
        return $value === null ? '' : (string) $value;
    }

    private function schedulePointValueFromKey(string $key): ?int
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $key)) {
            return (int) $key;
        }
        return $this->timeToMinutes($key);
    }

    private function scheduleDayOffset(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
            if ($date instanceof \DateTimeImmutable) {
                return intdiv((int) $date->format('U'), 86400);
            }
        }
        return 0;
    }

    private function timeToMinutes(string $value): ?int
    {
        $value = trim($value);
        if (!preg_match('/^(\d{2}):(\d{2})$/', $value, $matches)) {
            return null;
        }
        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }
        return ($hour * 60) + $minute;
    }

    private function scheduleConflictLabel(array $schedule): string
    {
        if (isset($schedule['block_label'])) {
            $parts = array_values(array_filter([
                (string) $schedule['block_label'],
                (string) ($schedule['schedule_day'] ?? ''),
                trim((string) ($schedule['starts_at'] ?? '') . '-' . (string) ($schedule['ends_at'] ?? ''), '-'),
                (string) ($schedule['notes'] ?? ''),
            ]));
            return $parts === [] ? 'an unavailable window' : implode(' / ', $parts);
        }
        $parts = array_values(array_filter([
            (string) ($schedule['company_code'] ?? ''),
            trim((string) ($schedule['round_sequence'] ?? '') . '. ' . (string) ($schedule['round_label'] ?? '')),
            (string) ($schedule['schedule_day'] ?? ''),
            (string) ($schedule['room'] ?? ''),
            trim((string) ($schedule['starts_at'] ?? '') . '-' . (string) ($schedule['ends_at'] ?? ''), '-'),
        ]));
        return $parts === [] ? 'an assigned schedule row' : implode(' / ', $parts);
    }

    private function scheduleEndsBeforeCompanyDeadline(array $schedule): bool
    {
        if (trim((string) ($schedule['deadline_at'] ?? '')) === '') {
            return true;
        }
        $scheduleEnd = $this->schedulePointValue($schedule, 'end');
        $deadline = $this->companyDeadlinePointValue($schedule);
        return $scheduleEnd !== null && $deadline !== null && $scheduleEnd <= $deadline;
    }

    private function companyDeadlinePointValue(array $schedule): ?int
    {
        $deadlineAt = trim((string) ($schedule['deadline_at'] ?? ''));
        $minute = $this->timeToMinutes($deadlineAt);
        if ($minute === null) {
            return null;
        }
        $deadlineDay = trim((string) ($schedule['deadline_day'] ?? ''));
        if ($deadlineDay === '') {
            $deadlineDay = (string) ($schedule['schedule_day'] ?? '');
        }
        return ($this->scheduleDayOffset($deadlineDay) * 1440) + $minute;
    }

    private function companyDeadlineLabel(array $schedule): string
    {
        $deadlineAt = trim((string) ($schedule['deadline_at'] ?? ''));
        $deadlineDay = trim((string) ($schedule['deadline_day'] ?? ''));
        return $deadlineDay === '' ? $deadlineAt : "{$deadlineDay} {$deadlineAt}";
    }

    private function schedulingBufferMinutes(): int
    {
        $value = (int) $this->setting('scheduling_buffer_minutes', '0');
        return max(0, min(240, $value));
    }

    private function slotPlannerStrategy(): string
    {
        $strategy = strtolower(trim($this->setting('slot_planner_strategy', 'sequence')));
        return in_array($strategy, ['sequence', 'earliest', 'balanced'], true) ? $strategy : 'sequence';
    }

    private function slotOptimizerExactLimit(): int
    {
        $value = (int) $this->setting('slot_optimizer_exact_limit', (string) self::DEFAULT_EXACT_SLOT_OPTIMIZER_LIMIT);
        return max(0, min(12, $value));
    }

    private function scheduleIsActive(array $schedule): bool
    {
        return $this->normalizeRoundScheduleStatus((string) ($schedule['schedule_status'] ?? 'active')) === 'active';
    }

    private function scheduleStartsAfter(array|string $schedule, string $lastEnd): bool
    {
        $lastEnd = trim($lastEnd);
        if ($lastEnd === '') {
            return true;
        }
        if (is_array($schedule)) {
            $start = $this->schedulePointValue($schedule, 'start');
            $end = $this->schedulePointValueFromKey($lastEnd);
            return $start === null || $end === null || $start >= $end;
        }
        $startsAt = trim($schedule);
        if ($startsAt === '') {
            return true;
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $startsAt) || !preg_match('/^\d{2}:\d{2}$/', $lastEnd)) {
            return true;
        }
        return $startsAt >= $lastEnd;
    }

    private function roundSchedulingBlockReason(array $roundSchedules, string $lastEnd, array $candidateWindows = [], int $bufferMinutes = 0): string
    {
        if ($roundSchedules === []) {
            return 'No schedule rows configured for this round.';
        }
        $activeSchedules = array_values(array_filter($roundSchedules, fn (array $schedule): bool => $this->scheduleIsActive($schedule)));
        if ($activeSchedules === []) {
            return 'All schedule rows for this round are paused, on break, or cancelled.';
        }
        $hasCapacityAfterPrevious = false;
        $hasCandidateConflict = false;
        $candidateConflictReason = '';
        $hasDeadlineBlock = false;
        foreach ($activeSchedules as $schedule) {
            if (!$this->scheduleStartsAfter($schedule, $lastEnd)) {
                continue;
            }
            if ((int) $schedule['capacity'] === 0 || (int) $schedule['assigned_count'] < (int) $schedule['capacity']) {
                if (!$this->scheduleEndsBeforeCompanyDeadline($schedule)) {
                    $hasDeadlineBlock = true;
                    continue;
                }
                $hasCapacityAfterPrevious = true;
                $conflictReason = $this->candidateSlotConflict($candidateWindows, $schedule, $bufferMinutes);
                if ($conflictReason !== '') {
                    $hasCandidateConflict = true;
                    $candidateConflictReason = $candidateConflictReason !== '' ? $candidateConflictReason : $conflictReason;
                    continue;
                }
                return 'No schedule row could be selected for this round.';
            }
        }
        if ($hasCapacityAfterPrevious && $hasCandidateConflict) {
            $reason = $bufferMinutes > 0
                ? "All capacity-safe schedule rows conflict with the candidate's existing or suggested slots under the {$bufferMinutes} minute buffer."
                : "All capacity-safe schedule rows conflict with the candidate's existing or suggested slots.";
            return $candidateConflictReason === '' ? $reason : $reason . ' ' . $candidateConflictReason;
        }
        if ($hasDeadlineBlock) {
            foreach ($activeSchedules as $schedule) {
                if (trim((string) ($schedule['deadline_at'] ?? '')) !== '') {
                    return 'All capacity-safe schedule rows end after the company deadline of ' . $this->companyDeadlineLabel($schedule) . '.';
                }
            }
        }
        foreach ($activeSchedules as $schedule) {
            if ($this->scheduleStartsAfter($schedule, $lastEnd)) {
                return 'All schedule rows for this round are at capacity.';
            }
        }
        return 'No schedule row starts after the previous assigned round.';
    }

    public function saveCandidate(array $input, ?int $actorId): int
    {
        $id = (int) ($input['id'] ?? 0);
        $externalId = trim((string) ($input['external_id'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if ($externalId === '' || $name === '') {
            throw new UserVisibleException('PLACEMENT_CANDIDATE_INVALID', 'Candidate ID and name are required.');
        }
        $now = cpe_now();
        $values = [
            $externalId,
            $name,
            trim((string) ($input['program'] ?? '')),
            trim((string) ($input['tags'] ?? '')),
            trim((string) ($input['current_location'] ?? 'CP')) ?: 'CP',
            trim((string) ($input['accommodation_notes'] ?? '')),
            $this->normalizeCustomFieldsJson((string) ($input['custom_fields_json'] ?? '{}'), 'Candidate custom fields'),
            !empty($input['opted_out']) ? 1 : 0,
            $now,
        ];
        if ($id > 0) {
            $stmt = $this->pdo->prepare('UPDATE candidates SET external_id = ?, name = ?, program = ?, tags = ?, current_location = ?, accommodation_notes = ?, custom_fields_json = ?, opted_out = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([...$values, $id]);
            Auth::audit($actorId, 'candidate.update', 'candidate', $id, $externalId);
            $this->synchronizeDurableDomain();
            return $id;
        }
        $stmt = $this->pdo->prepare('INSERT INTO candidates (external_id, name, program, tags, current_location, accommodation_notes, custom_fields_json, opted_out, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$externalId, $name, trim((string) ($input['program'] ?? '')), trim((string) ($input['tags'] ?? '')), trim((string) ($input['current_location'] ?? 'CP')) ?: 'CP', trim((string) ($input['accommodation_notes'] ?? '')), $this->normalizeCustomFieldsJson((string) ($input['custom_fields_json'] ?? '{}'), 'Candidate custom fields'), !empty($input['opted_out']) ? 1 : 0, $now, $now]);
        $id = Database::lastInsertId($this->pdo);
        Auth::audit($actorId, 'candidate.create', 'candidate', $id, $externalId);
        $this->synchronizeDurableDomain();
        return $id;
    }

    public function saveCompany(array $input, ?int $actorId): int
    {
        $id = (int) ($input['id'] ?? 0);
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw new UserVisibleException('PLACEMENT_COMPANY_INVALID', 'Company code and name are required.');
        }
        $now = cpe_now();
        $slot = trim((string) ($input['slot'] ?? ''));
        $offerTier = trim((string) ($input['offer_tier'] ?? ''));
        $processType = trim((string) ($input['process_type'] ?? ''));
        $room = trim((string) ($input['room'] ?? ''));
        $trackerName = trim((string) ($input['tracker_name'] ?? ''));
        $maxActive = max(0, (int) ($input['max_active'] ?? 0));
        $deadlineDay = trim((string) ($input['deadline_day'] ?? ''));
        $deadlineAt = trim((string) ($input['deadline_at'] ?? ''));
        $processNotes = trim((string) ($input['process_notes'] ?? ''));
        $tags = trim((string) ($input['tags'] ?? ''));
        $customFieldsJson = $this->normalizeCustomFieldsJson((string) ($input['custom_fields_json'] ?? '{}'), 'Company custom fields');
        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE companies
                 SET code = ?, name = ?, slot = ?, offer_tier = ?, process_type = ?, room = ?, tracker_name = ?, max_active = ?, deadline_day = ?, deadline_at = ?, process_notes = ?, tags = ?, custom_fields_json = ?, updated_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([$code, $name, $slot, $offerTier, $processType, $room, $trackerName, $maxActive, $deadlineDay, $deadlineAt, $processNotes, $tags, $customFieldsJson, $now, $id]);
            Auth::audit($actorId, 'company.update', 'company', $id, $code);
            $this->synchronizeDurableDomain();
            return $id;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO companies (code, name, slot, offer_tier, process_type, room, tracker_name, max_active, deadline_day, deadline_at, process_notes, tags, custom_fields_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$code, $name, $slot, $offerTier, $processType, $room, $trackerName, $maxActive, $deadlineDay, $deadlineAt, $processNotes, $tags, $customFieldsJson, $now, $now]);
        $id = Database::lastInsertId($this->pdo);
        Auth::audit($actorId, 'company.create', 'company', $id, $code);
        $this->synchronizeDurableDomain();
        return $id;
    }

    public function saveCompanyRound(array $input, ?int $actorId): int
    {
        $id = (int) ($input['id'] ?? 0);
        $companyId = (int) ($input['company_id'] ?? 0);
        $label = trim((string) ($input['label'] ?? ''));
        if ($companyId <= 0 || $label === '') {
            throw new UserVisibleException('PLACEMENT_ROUND_INVALID', 'Company and round label are required.');
        }
        if (!$this->recordExists('companies', $companyId)) {
            throw new UserVisibleException('PLACEMENT_COMPANY_NOT_FOUND', 'Company not found.');
        }

        $sequence = max(1, (int) ($input['sequence'] ?? 1));
        $roundType = trim((string) ($input['round_type'] ?? ''));
        $room = trim((string) ($input['room'] ?? ''));
        $duration = max(0, (int) ($input['duration_minutes'] ?? 0));
        $instructions = trim((string) ($input['instructions'] ?? ''));
        $now = cpe_now();

        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE company_rounds
                 SET company_id = ?, sequence = ?, label = ?, round_type = ?, room = ?, duration_minutes = ?, instructions = ?, updated_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([$companyId, $sequence, $label, $roundType, $room, $duration, $instructions, $now, $id]);
            Auth::audit($actorId, 'company_round.update', 'company_round', $id, $label);
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO company_rounds (company_id, sequence, label, round_type, room, duration_minutes, instructions, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, $sequence, $label, $roundType, $room, $duration, $instructions, $now, $now]);
        $id = Database::lastInsertId($this->pdo);
        Auth::audit($actorId, 'company_round.create', 'company_round', $id, $label);
        return $id;
    }

    public function saveRoundPanelist(array $input, ?int $actorId): int
    {
        $id = (int) ($input['id'] ?? 0);
        $roundId = (int) ($input['round_id'] ?? 0);
        $name = trim((string) ($input['name'] ?? ''));
        if ($roundId <= 0 || $name === '') {
            throw new UserVisibleException('PLACEMENT_PANELIST_INVALID', 'Round and panelist name are required.');
        }
        if (!$this->recordExists('company_rounds', $roundId)) {
            throw new UserVisibleException('PLACEMENT_ROUND_NOT_FOUND', 'Round not found.');
        }
        $sequence = max(1, (int) ($input['sequence'] ?? 1));
        $role = trim((string) ($input['role'] ?? ''));
        $affiliation = trim((string) ($input['affiliation'] ?? ''));
        $contact = trim((string) ($input['contact'] ?? ''));
        $availabilityStatus = $this->normalizePanelistAvailabilityStatus((string) ($input['availability_status'] ?? 'active'));
        $notes = trim((string) ($input['notes'] ?? ''));
        $now = cpe_now();

        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE round_panelists
                 SET round_id = ?, sequence = ?, name = ?, role = ?, affiliation = ?, contact = ?, availability_status = ?, notes = ?, updated_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([$roundId, $sequence, $name, $role, $affiliation, $contact, $availabilityStatus, $notes, $now, $id]);
            Auth::audit($actorId, 'round_panelist.update', 'round_panelist', $id, $name);
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO round_panelists (round_id, sequence, name, role, affiliation, contact, availability_status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$roundId, $sequence, $name, $role, $affiliation, $contact, $availabilityStatus, $notes, $now, $now]);
        $id = Database::lastInsertId($this->pdo);
        Auth::audit($actorId, 'round_panelist.create', 'round_panelist', $id, $name);
        return $id;
    }

    public function saveRoundSchedule(array $input, ?int $actorId): int
    {
        $id = (int) ($input['id'] ?? 0);
        $roundId = (int) ($input['round_id'] ?? 0);
        $room = trim((string) ($input['room'] ?? ''));
        if ($roundId <= 0 || $room === '') {
            throw new UserVisibleException('PLACEMENT_SCHEDULE_INVALID', 'Round and room are required.');
        }
        if (!$this->recordExists('company_rounds', $roundId)) {
            throw new UserVisibleException('PLACEMENT_ROUND_NOT_FOUND', 'Round not found.');
        }
        $sequence = max(1, (int) ($input['sequence'] ?? 1));
        $scheduleDay = trim((string) ($input['schedule_day'] ?? ''));
        $startsAt = trim((string) ($input['starts_at'] ?? ''));
        $endsAt = trim((string) ($input['ends_at'] ?? ''));
        $capacity = max(0, (int) ($input['capacity'] ?? 0));
        $scheduleStatus = $this->normalizeRoundScheduleStatus((string) ($input['schedule_status'] ?? 'active'));
        $notes = trim((string) ($input['notes'] ?? ''));
        $now = cpe_now();

        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE round_schedules
                 SET round_id = ?, sequence = ?, room = ?, schedule_day = ?, starts_at = ?, ends_at = ?, capacity = ?, schedule_status = ?, notes = ?, updated_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([$roundId, $sequence, $room, $scheduleDay, $startsAt, $endsAt, $capacity, $scheduleStatus, $notes, $now, $id]);
            Auth::audit($actorId, 'round_schedule.update', 'round_schedule', $id, $room);
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO round_schedules (round_id, sequence, room, schedule_day, starts_at, ends_at, capacity, schedule_status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$roundId, $sequence, $room, $scheduleDay, $startsAt, $endsAt, $capacity, $scheduleStatus, $notes, $now, $now]);
        $id = Database::lastInsertId($this->pdo);
        Auth::audit($actorId, 'round_schedule.create', 'round_schedule', $id, $room);
        return $id;
    }

    public function saveApplicationSlotAssignment(array $input, ?int $actorId): int
    {
        $id = (int) ($input['id'] ?? 0);
        $applicationId = (int) ($input['application_id'] ?? 0);
        $roundScheduleId = (int) ($input['round_schedule_id'] ?? 0);
        if ($applicationId <= 0 || $roundScheduleId <= 0) {
            throw new UserVisibleException('PLACEMENT_ASSIGNMENT_INVALID', 'Application and schedule are required.');
        }
        if (!$this->recordExists('applications', $applicationId)) {
            throw new UserVisibleException('PLACEMENT_APPLICATION_NOT_FOUND', 'Application not found.');
        }
        if (!$this->recordExists('round_schedules', $roundScheduleId)) {
            throw new UserVisibleException('PLACEMENT_SCHEDULE_NOT_FOUND', 'Round schedule not found.');
        }
        $this->assertScheduleBelongsToApplicationCompany($applicationId, $roundScheduleId);
        $sequence = max(1, (int) ($input['sequence'] ?? 1));
        $assignmentStatus = trim((string) ($input['assignment_status'] ?? 'assigned')) ?: 'assigned';
        $notes = trim((string) ($input['notes'] ?? ''));
        $now = cpe_now();

        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE application_slot_assignments
                 SET application_id = ?, round_schedule_id = ?, sequence = ?, assignment_status = ?, notes = ?, updated_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([$applicationId, $roundScheduleId, $sequence, $assignmentStatus, $notes, $now, $id]);
            Auth::audit($actorId, 'slot_assignment.update', 'slot_assignment', $id, $assignmentStatus);
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO application_slot_assignments (application_id, round_schedule_id, sequence, assignment_status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$applicationId, $roundScheduleId, $sequence, $assignmentStatus, $notes, $now, $now]);
        $id = Database::lastInsertId($this->pdo);
        Auth::audit($actorId, 'slot_assignment.create', 'slot_assignment', $id, $assignmentStatus);
        return $id;
    }

    public function applicationExists(int $candidateId, int $companyId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM applications WHERE candidate_id = ? AND company_id = ?');
        $stmt->execute([$candidateId, $companyId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function saveApplication(int $candidateId, int $companyId, string $status, ?int $waitlistRank, ?int $actorId): void
    {
        if ($candidateId <= 0 || $companyId <= 0) {
            throw new UserVisibleException('PLACEMENT_APPLICATION_INVALID', 'Candidate and company are required.');
        }
        if (!isset($this->workflow->statuses()[$status])) {
            throw new UserVisibleException('PLACEMENT_STATUS_INVALID', 'Unknown workflow status.');
        }
        $this->transactional(function () use ($candidateId, $companyId, $status, $waitlistRank, $actorId): void {
            $now = cpe_now();
            $result = $this->statusWriter->saveStatus(
                $candidateId,
                $companyId,
                $status,
                $waitlistRank,
                $actorId,
                'operator',
                'Application status changed through record maintenance.',
                $now,
            );
            Auth::audit($actorId, 'application.save', 'application', (int) $result['id'], "candidate {$candidateId} company {$companyId} status {$status}");
            $this->synchronizeDurableDomain();
            if ($this->workflowEngine !== null) {
                $this->workflowEngine->ensureApplication((int) $result['id']);
            }
        });
    }

    public function publicPlacements(): array
    {
        return $this->pdo->query(
            "SELECT c.program, co.code AS company_code, co.name AS company_name, COUNT(*) AS placed_count
             FROM candidates c
             JOIN companies co ON co.id = c.placed_company_id
             GROUP BY c.program, co.id, co.code, co.name
             ORDER BY co.code, c.program"
        )->fetchAll();
    }

    public function placementSummary(): array
    {
        $totalCandidates = $this->countBySql('SELECT COUNT(*) FROM candidates');
        $placedCandidates = $this->countBySql('SELECT COUNT(*) FROM candidates WHERE placed_company_id IS NOT NULL');
        $totalApplications = $this->countBySql('SELECT COUNT(*) FROM applications');
        $activeApplications = $this->countBySql('SELECT COUNT(*) FROM applications a WHERE ' . $this->activeApplicationSql('a'));

        $statusCounts = [];
        foreach ($this->workflow->statuses() as $key => $status) {
            $statusCounts[$key] = [
                'status' => $key,
                'label' => (string) ($status['label'] ?? $key),
                'count' => 0,
            ];
        }
        $rows = $this->pdo->query('SELECT current_status, COUNT(*) AS count FROM applications GROUP BY current_status ORDER BY current_status')->fetchAll();
        foreach ($rows as $row) {
            $status = (string) $row['current_status'];
            if (!isset($statusCounts[$status])) {
                $statusCounts[$status] = [
                    'status' => $status,
                    'label' => $status,
                    'count' => 0,
                ];
            }
            $statusCounts[$status]['count'] = (int) $row['count'];
        }

        $placementsByCompany = $this->pdo->query(
            'SELECT co.code, co.name, COUNT(c.id) AS placed_count
             FROM companies co
             JOIN candidates c ON c.placed_company_id = co.id
             GROUP BY co.id, co.code, co.name
             ORDER BY placed_count DESC, co.code'
        )->fetchAll();

        $candidatesByProgram = $this->pdo->query(
            "SELECT COALESCE(NULLIF(program, ''), 'Unspecified') AS program,
                    COUNT(*) AS candidate_count,
                    SUM(CASE WHEN placed_company_id IS NOT NULL THEN 1 ELSE 0 END) AS placed_count
             FROM candidates
             GROUP BY COALESCE(NULLIF(program, ''), 'Unspecified')
             ORDER BY candidate_count DESC, program"
        )->fetchAll();

        $candidatesByLocation = $this->pdo->query(
            "SELECT COALESCE(NULLIF(current_location, ''), 'Unknown') AS current_location,
                    COUNT(*) AS candidate_count
             FROM candidates
             GROUP BY COALESCE(NULLIF(current_location, ''), 'Unknown')
             ORDER BY candidate_count DESC, current_location"
        )->fetchAll();

        return [
            'totals' => [
                'candidates' => $totalCandidates,
                'placed_candidates' => $placedCandidates,
                'unplaced_candidates' => max(0, $totalCandidates - $placedCandidates),
                'applications' => $totalApplications,
                'active_applications' => $activeApplications,
            ],
            'applicationStatusCounts' => array_values($statusCounts),
            'placementsByCompany' => array_map(fn (array $row): array => [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'placed_count' => (int) $row['placed_count'],
            ], $placementsByCompany),
            'candidatesByProgram' => array_map(fn (array $row): array => [
                'program' => (string) $row['program'],
                'candidate_count' => (int) $row['candidate_count'],
                'placed_count' => (int) $row['placed_count'],
            ], $candidatesByProgram),
            'candidatesByLocation' => array_map(fn (array $row): array => [
                'current_location' => (string) $row['current_location'],
                'candidate_count' => (int) $row['candidate_count'],
            ], $candidatesByLocation),
        ];
    }

    public function studentStatus(string $externalId, array $user): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM candidates WHERE external_id = ?');
        $stmt->execute([$externalId]);
        $candidate = $stmt->fetch();
        if (!$candidate) {
            return null;
        }
        return $this->candidate((int) $candidate['id'], $user);
    }

    public function preferenceRequests(): array
    {
        return $this->pdo->query(
            "SELECT pr.*, c.external_id, c.name AS candidate_name, co.code AS decision_code, co.name AS decision_name
             FROM preference_requests pr
             JOIN candidates c ON c.id = pr.candidate_id
             LEFT JOIN companies co ON co.id = pr.decision_company_id
             ORDER BY pr.status = 'open' DESC, pr.id DESC"
        )->fetchAll();
    }

    public function preferenceOptions(int $requestId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT po.*, co.code, co.name
             FROM preference_options po
             JOIN companies co ON co.id = po.company_id
             WHERE po.request_id = ?
             ORDER BY co.code'
        );
        $stmt->execute([$requestId]);
        return $stmt->fetchAll();
    }

    public function notificationsForUser(array $user): array
    {
        [$whereSql, $params] = $this->notificationVisibilityWhere($user);
        $stmt = $this->pdo->prepare(
            "SELECT n.*, cu.name AS created_by_name, au.name AS acknowledged_by_name
             FROM notifications n
             LEFT JOIN users cu ON cu.id = n.created_by
             LEFT JOIN users au ON au.id = n.acknowledged_by
             WHERE {$whereSql}
             ORDER BY n.status = 'open' DESC, n.id DESC
             LIMIT 100"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function notificationCountForUser(array $user): int
    {
        [$whereSql, $params] = $this->notificationVisibilityWhere($user);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications n WHERE n.status = 'open' AND {$whereSql}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function acknowledgeNotification(int $notificationId, array $user): void
    {
        if ($notificationId <= 0) {
            throw new UserVisibleException('PLACEMENT_NOTIFICATION_NOT_FOUND', 'Notification not found.');
        }
        if (!Auth::hasCapability($user, 'placement.notifications.manage')) {
            throw new UserVisibleException('PLACEMENT_NOTIFICATION_FORBIDDEN', 'This account cannot acknowledge notifications.');
        }
        [$whereSql, $params] = $this->notificationVisibilityWhere($user);
        $stmt = $this->pdo->prepare("SELECT n.id FROM notifications n WHERE n.id = ? AND n.status = 'open' AND {$whereSql}");
        $stmt->execute([$notificationId, ...$params]);
        if (!$stmt->fetchColumn()) {
            throw new UserVisibleException('PLACEMENT_NOTIFICATION_UNAVAILABLE', 'Notification not found or already acknowledged.');
        }
        $now = cpe_now();
        $update = $this->pdo->prepare('UPDATE notifications SET status = ?, acknowledged_by = ?, acknowledged_at = ? WHERE id = ?');
        $update->execute(['acknowledged', (int) ($user['id'] ?? 0) ?: null, $now, $notificationId]);
        Auth::audit((int) ($user['id'] ?? 0) ?: null, 'notification.acknowledge', 'notification', $notificationId, 'Acknowledged notification');
    }

    public function createPreferenceRequest(int $candidateId, array $companyIds, ?int $actorId, string $note = ''): void
    {
        $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds))));
        if ($candidateId <= 0 || count($companyIds) < 2) {
            throw new UserVisibleException('PLACEMENT_PREFERENCE_INVALID', 'Choose a candidate and at least two companies.');
        }
        $this->pdo->beginTransaction();
        try {
            $candidateLabel = $this->candidateLabel($candidateId);
            $companyCodes = $this->companyCodesForIds($companyIds);
            $stmt = $this->pdo->prepare('INSERT INTO preference_requests (candidate_id, note, requested_by, created_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$candidateId, $note, $actorId, cpe_now()]);
            $requestId = Database::lastInsertId($this->pdo);
            $opt = $this->pdo->prepare('INSERT INTO preference_options (request_id, company_id) VALUES (?, ?)');
            foreach ($companyIds as $companyId) {
                $opt->execute([$requestId, $companyId]);
            }
            $this->notifyRoles(
                ['control', 'placement'],
                'preference.requested',
                'Preference needed: ' . $candidateLabel,
                'Options: ' . implode(', ', $companyCodes) . ($note !== '' ? '. ' . $note : ''),
                'preference_request',
                $requestId,
                $actorId
            );
            Auth::audit($actorId, 'preference.create', 'preference_request', $requestId, $note);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function resolvePreferenceRequest(int $requestId, int $companyId, ?int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT pr.id, pr.candidate_id, co.code
             FROM preference_requests pr
             JOIN preference_options po ON po.request_id = pr.id AND po.company_id = ?
             JOIN companies co ON co.id = po.company_id
             WHERE pr.id = ?'
        );
        $stmt->execute([$companyId, $requestId]);
        $request = $stmt->fetch();
        if (!$request) {
            throw new UserVisibleException('PLACEMENT_PREFERENCE_NOT_FOUND', 'Preference request or selected company option not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE preference_requests SET status = ?, decision_company_id = ?, resolved_at = ? WHERE id = ?');
            $update->execute(['resolved', $companyId, cpe_now(), $requestId]);
            $this->closeNotificationsForSource('preference_request', $requestId, $actorId);
            Auth::audit($actorId, 'preference.resolve', 'preference_request', $requestId, 'Decision company ' . (string) $request['code']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function wantedAlerts(): array
    {
        return $this->pdo->query(
            "SELECT wa.*, c.external_id, c.name AS candidate_name
             FROM wanted_alerts wa
             JOIN candidates c ON c.id = wa.candidate_id
             ORDER BY wa.status = 'open' DESC, wa.id DESC"
        )->fetchAll();
    }

    public function openWantedAlertsByCandidate(): array
    {
        $rows = $this->pdo->query("SELECT candidate_id, COUNT(*) AS count FROM wanted_alerts WHERE status = 'open' GROUP BY candidate_id")->fetchAll();
        $alerts = [];
        foreach ($rows as $row) {
            $alerts[(int) $row['candidate_id']] = (int) $row['count'];
        }
        return $alerts;
    }

    public function createWantedAlert(int $candidateId, string $reason, ?int $actorId): void
    {
        if ($candidateId <= 0 || trim($reason) === '') {
            throw new UserVisibleException('PLACEMENT_WANTED_INVALID', 'Candidate and reason are required.');
        }
        $reason = trim($reason);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO wanted_alerts (candidate_id, reason, created_by, created_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$candidateId, $reason, $actorId, cpe_now()]);
            $alertId = Database::lastInsertId($this->pdo);
            $this->notifyRoles(
                ['control', 'floor', 'mobile'],
                'wanted.created',
                'Wanted: ' . $this->candidateLabel($candidateId),
                $reason,
                'wanted_alert',
                $alertId,
                $actorId
            );
            Auth::audit($actorId, 'wanted.create', 'wanted_alert', $alertId, $reason);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function resolveWantedAlert(int $alertId, ?int $actorId): void
    {
        if ($alertId <= 0) {
            throw new UserVisibleException('PLACEMENT_WANTED_NOT_FOUND', 'Wanted alert not found.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE wanted_alerts SET status = ?, resolved_by = ?, resolved_at = ? WHERE id = ?');
            $stmt->execute(['resolved', $actorId, cpe_now(), $alertId]);
            if ($stmt->rowCount() === 0) {
                throw new UserVisibleException('PLACEMENT_WANTED_NOT_FOUND', 'Wanted alert not found.');
            }
            $this->closeNotificationsForSource('wanted_alert', $alertId, $actorId);
            Auth::audit($actorId, 'wanted.resolve', 'wanted_alert', $alertId, 'Resolved wanted alert');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function seedLargeDemo(int $candidateCount = 60, int $companyCount = 8): array
    {
        $candidateCount = max(10, min(500, $candidateCount));
        $companyCount = max(3, min(50, $companyCount));
        $statuses = array_keys($this->workflow->statuses());
        $activeStatuses = $this->activeStatuses();
        if ($activeStatuses === []) {
            $activeStatuses = array_values(array_filter($statuses, fn (string $status): bool => $status !== 'idle'));
        }
        $statusCycle = $activeStatuses !== [] ? $activeStatuses : ['idle'];
        $programs = ['MBA', 'PGP', 'PGP-BA', 'EMBA'];
        $processTypes = ['Interview', 'Case + PI', 'Technical', 'Finance Case', 'GD + PI'];
        $candidatePattern = Database::exactNumericPattern('external_id', 'QAC', 3);
        $companyPattern = Database::exactNumericPattern('code', 'QA', 2);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM candidates WHERE ' . $candidatePattern);
            $this->pdo->exec('DELETE FROM companies WHERE ' . $companyPattern);

            $now = cpe_now();
            $companyStmt = $this->pdo->prepare(
                'INSERT INTO companies (code, name, slot, offer_tier, process_type, room, tracker_name, max_active, process_notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $candidateStmt = $this->pdo->prepare(
                'INSERT INTO candidates (external_id, name, program, current_location, opted_out, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $roundStmt = $this->pdo->prepare(
                'INSERT INTO company_rounds (company_id, sequence, label, round_type, room, duration_minutes, instructions, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $scheduleStmt = $this->pdo->prepare(
                'INSERT INTO round_schedules (round_id, sequence, room, starts_at, ends_at, capacity, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $panelistStmt = $this->pdo->prepare(
                'INSERT INTO round_panelists (round_id, sequence, name, role, affiliation, contact, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $applicationStmt = $this->pdo->prepare(
                'INSERT INTO applications (candidate_id, company_id, current_status, waitlist_rank, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $assignmentStmt = $this->pdo->prepare(
                'INSERT INTO application_slot_assignments (application_id, round_schedule_id, sequence, assignment_status, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING'
            );

            $companyIds = [];
            $firstScheduleIds = [];
            $slotCapacity = max(8, (int) ceil($candidateCount / max(1, $companyCount)) + 4);
            for ($i = 1; $i <= $companyCount; $i++) {
                $code = sprintf('QA%02d', $i);
                $companyStmt->execute([
                    $code,
                    sprintf('QA Company %02d', $i),
                    'QA Slot ' . (int) ceil($i / 4),
                    'Synthetic',
                    $processTypes[($i - 1) % count($processTypes)],
                    sprintf('QA Room %02d', $i),
                    sprintf('QA Tracker %02d', $i),
                    $slotCapacity * 2,
                    'Synthetic large-demo company. Safe to reset with seed-large-demo.',
                    $now,
                    $now,
                ]);
                $companyId = Database::lastInsertId($this->pdo);
                $companyIds[$code] = $companyId;

                $roundDefinitions = [
                    [1, 'Screening', 'screen', sprintf('QA%02d-R1', $i), 25, 'Synthetic screening round.'],
                    [2, 'Interview', 'interview', sprintf('QA%02d-R2', $i), 35, 'Synthetic interview round.'],
                ];
                foreach ($roundDefinitions as $round) {
                    $roundStmt->execute([$companyId, $round[0], $round[1], $round[2], $round[3], $round[4], $round[5], $now, $now]);
                    $roundId = Database::lastInsertId($this->pdo);
                    $starts = (int) $round[0] === 1 ? [['09:00', '09:25'], ['09:30', '09:55']] : [['10:15', '10:50'], ['10:55', '11:30']];
                    foreach ($starts as $scheduleIndex => $times) {
                        $room = sprintf('QA%02d-R%d%s', $i, $round[0], $scheduleIndex === 0 ? 'A' : 'B');
                        $scheduleStmt->execute([$roundId, $scheduleIndex + 1, $room, $times[0], $times[1], $slotCapacity, 'Synthetic schedule capacity.', $now, $now]);
                        if (!isset($firstScheduleIds[$code])) {
                            $firstScheduleIds[$code] = Database::lastInsertId($this->pdo);
                        }
                    }
                    for ($panelist = 1; $panelist <= 2; $panelist++) {
                        $panelistStmt->execute([
                            $roundId,
                            $panelist,
                            sprintf('QA Panelist %02d-%d-%d', $i, $round[0], $panelist),
                            $panelist === 1 ? 'Lead' : 'Observer',
                            sprintf('QA Company %02d', $i),
                            '',
                            'Synthetic panel roster entry.',
                            $now,
                            $now,
                        ]);
                    }
                }
            }

            $candidateIds = [];
            for ($i = 1; $i <= $candidateCount; $i++) {
                $externalId = sprintf('QAC%03d', $i);
                $candidateStmt->execute([
                    $externalId,
                    sprintf('QA Candidate %03d', $i),
                    $programs[($i - 1) % count($programs)],
                    'CP',
                    0,
                    $now,
                    $now,
                ]);
                $candidateIds[$externalId] = Database::lastInsertId($this->pdo);
            }

            for ($i = 1; $i <= $candidateCount; $i++) {
                $externalId = sprintf('QAC%03d', $i);
                $candidateId = $candidateIds[$externalId];
                $primaryCode = sprintf('QA%02d', (($i - 1) % $companyCount) + 1);
                $primaryStatus = in_array('idle', $statuses, true) && $i % 12 === 0
                    ? 'idle'
                    : $statusCycle[($i - 1) % count($statusCycle)];
                $applicationStmt->execute([
                    $candidateId,
                    $companyIds[$primaryCode],
                    $primaryStatus,
                    $i % 7 === 0 ? (int) ceil($i / 7) : null,
                    $now,
                    $now,
                ]);
                $primaryApplicationId = Database::lastInsertId($this->pdo);

                if ($primaryStatus !== 'idle' && $i % 4 === 0 && isset($firstScheduleIds[$primaryCode])) {
                    $assignmentStmt->execute([
                        $primaryApplicationId,
                        $firstScheduleIds[$primaryCode],
                        1,
                        'assigned',
                        'Synthetic assignment for large-demo QA.',
                        $now,
                        $now,
                    ]);
                }

                if ($i % 5 === 0 && $companyCount > 1) {
                    $secondaryCode = sprintf('QA%02d', (($i + 1) % $companyCount) + 1);
                    if ($secondaryCode !== $primaryCode) {
                        $applicationStmt->execute([
                            $candidateId,
                            $companyIds[$secondaryCode],
                            $statusCycle[$i % count($statusCycle)],
                            null,
                            $now,
                            $now,
                        ]);
                    }
                }

                if ($i % 13 === 0 && $companyCount > 2 && in_array('idle', $statuses, true)) {
                    $thirdCode = sprintf('QA%02d', (($i + 2) % $companyCount) + 1);
                    if (!in_array($thirdCode, [$primaryCode, $secondaryCode ?? ''], true)) {
                        $applicationStmt->execute([$candidateId, $companyIds[$thirdCode], 'idle', null, $now, $now]);
                    }
                }
                unset($secondaryCode);
            }

            (new DemoDataService($this->pdo))->markLoaded();
            $this->synchronizeDurableDomain();
            (new WorkflowRepository($this->pdo))->synchronizeApplicationInstances();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'candidates' => $this->countBySql('SELECT COUNT(*) FROM candidates WHERE ' . Database::exactNumericPattern('external_id', 'QAC', 3)),
            'companies' => $this->countBySql('SELECT COUNT(*) FROM companies WHERE ' . Database::exactNumericPattern('code', 'QA', 2)),
            'applications' => $this->countBySql(
                "SELECT COUNT(*)
                 FROM applications a
                 JOIN candidates c ON c.id = a.candidate_id
                 WHERE " . Database::exactNumericPattern('c.external_id', 'QAC', 3)
            ),
            'rounds' => $this->countBySql(
                "SELECT COUNT(*)
                 FROM company_rounds cr
                 JOIN companies co ON co.id = cr.company_id
                 WHERE " . Database::exactNumericPattern('co.code', 'QA', 2)
            ),
            'schedules' => $this->countBySql(
                "SELECT COUNT(*)
                 FROM round_schedules rs
                 JOIN company_rounds cr ON cr.id = rs.round_id
                 JOIN companies co ON co.id = cr.company_id
                 WHERE " . Database::exactNumericPattern('co.code', 'QA', 2)
            ),
            'panelists' => $this->countBySql(
                "SELECT COUNT(*)
                 FROM round_panelists rp
                 JOIN company_rounds cr ON cr.id = rp.round_id
                 JOIN companies co ON co.id = cr.company_id
                 WHERE " . Database::exactNumericPattern('co.code', 'QA', 2)
            ),
            'slot_assignments' => $this->countBySql(
                "SELECT COUNT(*)
                 FROM application_slot_assignments asa
                 JOIN applications a ON a.id = asa.application_id
                 JOIN candidates c ON c.id = a.candidate_id
                 WHERE " . Database::exactNumericPattern('c.external_id', 'QAC', 3)
            ),
        ];
    }

    public function seedDemo(): void
    {
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn() > 0) {
            $this->seedDemoRounds();
            $this->seedDemoSchedules();
            $this->seedDemoSlotAssignments();
            $this->seedDemoPanelists();
            $this->seedDemoUsers();
            (new DemoDataService($this->pdo))->markLoaded();
            $this->synchronizeDurableDomain();
            (new WorkflowRepository($this->pdo))->synchronizeApplicationInstances();
            return;
        }

        $now = cpe_now();
        $companies = [
            ['ATLAS', 'Atlas Consulting', 'Day 0 / Slot 1', 'Interview', 'Room A1', 'Atlas Tracker', 2],
            ['NOVA', 'Nova Analytics', 'Day 0 / Slot 1', 'Case + PI', 'Room B2', 'Control Lead', 2],
            ['RIVER', 'Riverbank Capital', 'Day 0 / Slot 2', 'Finance Interview', 'Room C3', 'Floor Coordinator', 2],
        ];
        $candidates = [
            ['C001', 'Asha Mehta', 'MBA'],
            ['C002', 'Kabir Rao', 'MBA'],
            ['C003', 'Naina Singh', 'MBA'],
            ['C004', 'Rohan Das', 'MBA'],
            ['C005', 'Tara Iyer', 'MBA'],
        ];

        foreach ($companies as $company) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO companies (code, name, slot, process_type, room, tracker_name, max_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$company[0], $company[1], $company[2], $company[3], $company[4], $company[5], $company[6], $now, $now]);
        }
        foreach ($candidates as $candidate) {
            $stmt = $this->pdo->prepare('INSERT INTO candidates (external_id, name, program, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$candidate[0], $candidate[1], $candidate[2], $now, $now]);
        }

        $statusKeys = array_keys($this->workflow->statuses());
        $pick = fn (int $index): string => $statusKeys[min($index, count($statusKeys) - 1)] ?? 'idle';
        $pairs = [
            ['C001', 'ATLAS', $pick(1)],
            ['C001', 'NOVA', $pick(0)],
            ['C002', 'ATLAS', $pick(3)],
            ['C003', 'NOVA', $pick(4)],
            ['C004', 'RIVER', $pick(6)],
            ['C005', 'RIVER', $pick(1)],
        ];
        foreach ($pairs as $pair) {
            $candidateId = $this->idFor('candidates', 'external_id', $pair[0]);
            $companyId = $this->idFor('companies', 'code', $pair[1]);
            $stmt = $this->pdo->prepare('INSERT INTO applications (candidate_id, company_id, current_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$candidateId, $companyId, $pair[2], $now, $now]);
        }

        $this->seedDemoRounds();
        $this->seedDemoSchedules();
        $this->seedDemoSlotAssignments();
        $this->seedDemoPanelists();
        $this->seedDemoUsers();
        (new DemoDataService($this->pdo))->markLoaded();
        $this->synchronizeDurableDomain();
        (new WorkflowRepository($this->pdo))->synchronizeApplicationInstances();
    }

    private function seedDemoRounds(): void
    {
        $rounds = [
            'ATLAS' => [
                [1, 'Case Discussion', 'case', 'Room A1', 45, 'Carry case brief and score sheet.'],
                [2, 'Partner Interview', 'interview', 'Room A2', 30, 'Send only after case feedback is logged.'],
            ],
            'NOVA' => [
                [1, 'Analytics Test', 'test', 'Lab B1', 30, 'Laptop lab seating required.'],
                [2, 'Technical Interview', 'interview', 'Room B2', 30, 'Panel asks for analytics test score.'],
            ],
            'RIVER' => [
                [1, 'Finance Case', 'case', 'Room C3', 45, 'Candidates carry calculator and resume.'],
            ],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO company_rounds (company_id, sequence, label, round_type, room, duration_minutes, instructions, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING'
        );
        foreach ($rounds as $companyCode => $companyRounds) {
            $select = $this->pdo->prepare('SELECT id FROM companies WHERE code = ?');
            $select->execute([$companyCode]);
            $companyId = (int) $select->fetchColumn();
            if ($companyId <= 0) {
                continue;
            }
            foreach ($companyRounds as $round) {
                $now = cpe_now();
                $stmt->execute([$companyId, $round[0], $round[1], $round[2], $round[3], $round[4], $round[5], $now, $now]);
            }
        }
    }

    private function seedDemoPanelists(): void
    {
        $panelists = [
            ['ATLAS', 1, 'Case Discussion', 1, 'Arun Iyer', 'Lead panelist', 'Atlas Consulting', '', 'Case scoring owner.'],
            ['ATLAS', 1, 'Case Discussion', 2, 'Neha Shah', 'Observer', 'Atlas Consulting', '', 'Tracks fit notes.'],
            ['ATLAS', 2, 'Partner Interview', 1, 'Vikram Menon', 'Partner', 'Atlas Consulting', '', 'Final interviewer.'],
            ['NOVA', 1, 'Analytics Test', 1, 'Priya Rao', 'Test coordinator', 'Nova Analytics', '', 'Lab handoff.'],
            ['NOVA', 2, 'Technical Interview', 1, 'Ravi Kumar', 'Technical lead', 'Nova Analytics', '', 'Review test score first.'],
            ['RIVER', 1, 'Finance Case', 1, 'Ananya Sen', 'Finance panelist', 'Riverbank Capital', '', 'Case discussion lead.'],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO round_panelists (round_id, sequence, name, role, affiliation, contact, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING'
        );
        foreach ($panelists as $panelist) {
            $roundId = $this->roundIdFor($panelist[0], $panelist[1], $panelist[2]);
            if ($roundId <= 0) {
                continue;
            }
            $now = cpe_now();
            $stmt->execute([$roundId, $panelist[3], $panelist[4], $panelist[5], $panelist[6], $panelist[7], $panelist[8], $now, $now]);
        }
    }

    private function seedDemoSchedules(): void
    {
        $schedules = [
            ['ATLAS', 1, 'Case Discussion', 1, 'Room A1', '09:00', '09:45', 2, 'Case packets ready before slot opens.'],
            ['ATLAS', 2, 'Partner Interview', 1, 'Room A2', '10:00', '10:30', 1, 'Final interviewer joins after case feedback.'],
            ['NOVA', 1, 'Analytics Test', 1, 'Lab B1', '09:15', '09:45', 8, 'Laptop lab seating.'],
            ['NOVA', 2, 'Technical Interview', 1, 'Room B2', '10:00', '10:30', 2, 'Use analytics score sheet.'],
            ['RIVER', 1, 'Finance Case', 1, 'Room C3', '11:00', '11:45', 2, 'Calculator allowed.'],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO round_schedules (round_id, sequence, room, starts_at, ends_at, capacity, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING'
        );
        foreach ($schedules as $schedule) {
            $roundId = $this->roundIdFor($schedule[0], $schedule[1], $schedule[2]);
            if ($roundId <= 0) {
                continue;
            }
            $now = cpe_now();
            $stmt->execute([$roundId, $schedule[3], $schedule[4], $schedule[5], $schedule[6], $schedule[7], $schedule[8], $now, $now]);
        }
    }

    private function seedDemoSlotAssignments(): void
    {
        $assignments = [
            ['C001', 'ATLAS', 1, 'Case Discussion', 1, 'Room A1', '09:00', 1, 'assigned', 'First case slot.'],
            ['C002', 'ATLAS', 1, 'Case Discussion', 1, 'Room A1', '09:00', 2, 'assigned', 'Second case slot.'],
            ['C003', 'NOVA', 1, 'Analytics Test', 1, 'Lab B1', '09:15', 1, 'assigned', 'Lab seat reserved.'],
            ['C004', 'RIVER', 1, 'Finance Case', 1, 'Room C3', '11:00', 1, 'assigned', 'Finance case slot.'],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO application_slot_assignments (application_id, round_schedule_id, sequence, assignment_status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING'
        );
        foreach ($assignments as $assignment) {
            $applicationId = $this->applicationIdFor($assignment[0], $assignment[1]);
            $scheduleId = $this->scheduleIdFor($assignment[1], $assignment[2], $assignment[3], $assignment[4], $assignment[5], $assignment[6]);
            if ($applicationId <= 0 || $scheduleId <= 0) {
                continue;
            }
            $now = cpe_now();
            $stmt->execute([$applicationId, $scheduleId, $assignment[7], $assignment[8], $assignment[9], $now, $now]);
        }
    }

    private function seedDemoUsers(): void
    {
        $demoUsers = [
            ['Control Lead', 'control@example.test', 'control', '', ''],
            ['Atlas Tracker', 'atlas@example.test', 'company', 'company', 'ATLAS'],
            ['Mobile Tracker', 'mobile@example.test', 'mobile', '', ''],
            ['Floor Coordinator', 'floor@example.test', 'floor', '', ''],
            ['Placement Office', 'placement@example.test', 'placement', '', ''],
            ['Read Only Auditor', 'auditor@example.test', 'auditor', '', ''],
        ];
        foreach ($demoUsers as $demoUser) {
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $exists->execute([$demoUser[1]]);
            if ((int) $exists->fetchColumn() === 0) {
                Auth::createUser($demoUser[0], $demoUser[1], 'password123', $demoUser[2], $demoUser[3], $demoUser[4]);
            }
        }
    }

    private function idFor(string $table, string $column, string $value): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
        $stmt->execute([$value]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException("Missing {$table}.{$column}: {$value}");
        }
        return (int) $id;
    }

    private function recordExists(string $table, int $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function normalizeRoundScheduleStatus(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['active', 'paused', 'break', 'cancelled'], true) ? $value : 'active';
    }

    private function normalizePanelistAvailabilityStatus(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['active', 'break', 'unavailable'], true) ? $value : 'active';
    }

    private function countBySql(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function assertScheduleBelongsToApplicationCompany(int $applicationId, int $roundScheduleId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM applications a
             JOIN round_schedules rs ON rs.id = ?
             JOIN company_rounds cr ON cr.id = rs.round_id
             WHERE a.id = ? AND a.company_id = cr.company_id'
        );
        $stmt->execute([$roundScheduleId, $applicationId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new UserVisibleException('PLACEMENT_ASSIGNMENT_COMPANY_INVALID', 'Schedule does not belong to the application company.');
        }
    }

    private function roundIdFor(string $companyCode, int $sequence, string $label): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.id
             FROM company_rounds cr
             JOIN companies co ON co.id = cr.company_id
             WHERE co.code = ? AND cr.sequence = ? AND cr.label = ?'
        );
        $stmt->execute([$companyCode, $sequence, $label]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function scheduleIdFor(string $companyCode, int $roundSequence, string $roundLabel, int $scheduleSequence, string $room, string $startsAt): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT rs.id
             FROM round_schedules rs
             JOIN company_rounds cr ON cr.id = rs.round_id
             JOIN companies co ON co.id = cr.company_id
             WHERE co.code = ? AND cr.sequence = ? AND cr.label = ?
               AND rs.sequence = ? AND rs.room = ? AND rs.starts_at = ?'
        );
        $stmt->execute([$companyCode, $roundSequence, $roundLabel, $scheduleSequence, $room, $startsAt]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function applicationIdFor(string $candidateExternalId, string $companyCode): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id
             FROM applications a
             JOIN candidates c ON c.id = a.candidate_id
             JOIN companies co ON co.id = a.company_id
             WHERE c.external_id = ? AND co.code = ?'
        );
        $stmt->execute([$candidateExternalId, $companyCode]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * @param array<string, scalar|null> $payload
     * @param array<string, mixed> $actorContext
     * @return array{duplicate: bool, status: string}
     */
    private function executeBoardRequest(
        string $key,
        ?int $actorUserId,
        string $action,
        int $applicationId,
        array $payload,
        array $actorContext,
        callable $operation
    ): array {
        $key = trim($key);
        if ($key !== '' && preg_match('/^[A-Fa-f0-9]{32,64}$/', $key) !== 1) {
            throw new UserVisibleException('FORM_SUBMISSION_KEY_INVALID', 'Invalid form submission key.');
        }
        ksort($payload);
        $requestHash = hash('sha256', "cpe.board-request.v1\0" . json_encode([
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'application_id' => $applicationId,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $this->transactional(function () use ($key, $actorUserId, $action, $applicationId, $requestHash, $actorContext, $operation): array {
            if ($actorContext !== []) {
                $this->assertCanActOnApplicationContext($applicationId, $actorContext, true);
            }
            if ($key !== '') {
                $cutoff = gmdate('Y-m-d H:i:s', time() - 172800);
                $cleanup = $this->pdo->prepare('DELETE FROM idempotency_keys WHERE created_at < ?');
                $cleanup->execute([$cutoff]);

                $insert = $this->pdo->prepare(
                    'INSERT INTO idempotency_keys
                     (key, actor_user_id, action, application_id, created_at, request_hash, result_json)
                     VALUES (?, ?, ?, ?, ?, ?, NULL) ON CONFLICT(key) DO NOTHING'
                );
                $insert->execute([$key, $actorUserId, $action, $applicationId, cpe_now(), $requestHash]);
                if ($insert->rowCount() === 0) {
                    $existing = $this->pdo->prepare(
                        'SELECT actor_user_id, action, application_id, request_hash, result_json
                         FROM idempotency_keys WHERE key = ?'
                    );
                    $existing->execute([$key]);
                    $row = $existing->fetch();
                    $storedActorId = $row && $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null;
                    $storedApplicationId = $row && $row['application_id'] !== null ? (int) $row['application_id'] : null;
                    if (!$row
                        || $storedActorId !== $actorUserId
                        || (string) $row['action'] !== $action
                        || $storedApplicationId !== $applicationId
                        || !is_string($row['request_hash'])
                        || !hash_equals($row['request_hash'], $requestHash)) {
                        throw new UserVisibleException(
                            'FORM_SUBMISSION_KEY_CONFLICT',
                            'This form submission key was already used for a different request. Reload the board and retry.'
                        );
                    }
                    $resultJson = (string) ($row['result_json'] ?? '');
                    if ($resultJson === '') {
                        throw new UserVisibleException(
                            'FORM_SUBMISSION_RESULT_UNAVAILABLE',
                            'The previous form submission result is unavailable. Reload the board before retrying.'
                        );
                    }
                    $result = json_decode($resultJson, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($result) || !is_string($result['status'] ?? null)) {
                        throw new RuntimeException('Stored form submission result is invalid.');
                    }
                    return ['duplicate' => true, 'status' => $result['status']];
                }
            }

            $result = $operation();
            if (!is_array($result) || !is_string($result['status'] ?? null)) {
                throw new RuntimeException('Board mutation returned an invalid result.');
            }
            if ($key !== '') {
                $resultJson = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $complete = $this->pdo->prepare(
                    'UPDATE idempotency_keys SET result_json = ? WHERE key = ? AND request_hash = ?'
                );
                $complete->execute([$resultJson, $key, $requestHash]);
                if ($complete->rowCount() !== 1) {
                    throw new RuntimeException('Could not finalize form submission result.');
                }
            }
            return ['duplicate' => false, 'status' => $result['status']];
        });
    }

    /** @param array<string, scalar|null> $payload @param array<string, mixed> $actorContext */
    private function addActorScopeToPayload(array &$payload, array $actorContext): void
    {
        if ($actorContext === []) {
            return;
        }
        $payload['actor_scope_type'] = trim((string) ($actorContext['scope_type'] ?? ''));
        $payload['actor_scope_value'] = strtoupper(trim((string) ($actorContext['scope_value'] ?? '')));
    }

    private function transactional(callable $operation): mixed
    {
        return WriteTransaction::run($this->pdo, $operation);
    }

    private function setting(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    private function acceptCandidateOffer(array $application, string $now): void
    {
        $expectedPlacedCompanyId = $application['placed_company_id'] !== null
            ? (int) $application['placed_company_id']
            : null;
        $expectedLocation = $application['current_location'] !== null
            ? (string) $application['current_location']
            : null;
        $placed = $this->pdo->prepare(
            'UPDATE candidates
             SET placed_company_id = ?, current_location = ?, updated_at = ?
             WHERE id = ?
               AND ((placed_company_id IS NULL AND CAST(? AS BIGINT) IS NULL) OR placed_company_id = ?)
               AND ((current_location IS NULL AND CAST(? AS TEXT) IS NULL) OR current_location = ?)'
        );
        $placed->execute([
            (int) $application['company_id'],
            (string) $application['company_code'],
            $now,
            (int) $application['candidate_id'],
            $expectedPlacedCompanyId,
            $expectedPlacedCompanyId,
            $expectedLocation,
            $expectedLocation,
        ]);
        if ($placed->rowCount() !== 1) {
            throw new UserVisibleException('PLACEMENT_BOARD_STALE', 'The candidate changed while the offer was being recorded. Reload the board and retry.');
        }
    }

    private function updateCandidateLocation(array $application, string $location, string $now): void
    {
        $expectedLocation = $application['current_location'] !== null
            ? (string) $application['current_location']
            : null;
        $update = $this->pdo->prepare(
            'UPDATE candidates
             SET current_location = ?, updated_at = ?
             WHERE id = ?
               AND ((current_location IS NULL AND CAST(? AS TEXT) IS NULL) OR current_location = ?)'
        );
        $update->execute([
            $location,
            $now,
            (int) $application['candidate_id'],
            $expectedLocation,
            $expectedLocation,
        ]);
        if ($update->rowCount() !== 1) {
            throw new UserVisibleException('PLACEMENT_BOARD_STALE', 'The candidate location changed while the board was being updated. Reload the board and retry.');
        }
    }

    private function clearCompetingActiveApplications(
        array $placedApp,
        ?int $actorId,
        string $actorRole,
        string $now,
        ?int $actorServiceAccountId = null,
    ): void
    {
        $activeStatuses = $this->activeStatuses();
        if ($activeStatuses === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, candidate_id, company_id, current_status, aggregate_version
             FROM applications
             WHERE candidate_id = ?
               AND id != ?
               AND current_status IN ({$placeholders})"
        );
        $stmt->execute([(int) $placedApp['candidate_id'], (int) $placedApp['id'], ...$activeStatuses]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return;
        }

        $note = 'Auto-cleared after placement at ' . (string) $placedApp['company_code'] . '.';
        foreach ($rows as $row) {
            $fromStatus = (string) $row['current_status'];
            $correctionTransition = $this->workflowEngine?->transitionForEffect(
                (int) $row['id'],
                $fromStatus,
                'idle',
                true
            );
            $this->statusWriter->changeStatus(
                (int) $row['id'],
                $fromStatus,
                (int) $row['aggregate_version'],
                'idle',
                $actorId,
                $actorRole,
                $note,
                $now,
                ['source' => 'placement.clear_competing_applications'],
                $actorServiceAccountId,
            );
            if ($correctionTransition !== null) {
                $this->workflowEngine?->recordAppliedTransition(
                    (int) $row['id'],
                    $correctionTransition,
                    $actorId,
                    $actorRole,
                    'placement_cleanup',
                    $note,
                    ['source' => 'placement.clear_competing_applications'],
                    $actorServiceAccountId,
                );
            }
            if ($actorServiceAccountId !== null) {
                Auth::audit(
                    null,
                    'transition',
                    'application',
                    (int) $row['id'],
                    '',
                    $this->pdo,
                    $actorServiceAccountId,
                );
            }
        }
        if ($actorServiceAccountId === null) {
            Auth::audit($actorId, 'placement.cleanup_competing_applications', 'candidate', (int) $placedApp['candidate_id'], 'Cleared ' . count($rows) . ' competing active application(s).');
        }
    }

    private function handoffToNextScheduledApplication(
        array $sentApp,
        int $applicationId,
        ?int $actorId,
        string $actorRole,
        string $now,
        ?int $actorServiceAccountId = null,
    ): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.candidate_id, a.company_id, a.current_status, a.aggregate_version,
                    co.code AS company_code
             FROM applications a
             JOIN companies co ON co.id = a.company_id
             WHERE a.candidate_id = ?
               AND a.id != ?
               AND a.current_status = 'scheduled'
             ORDER BY a.updated_at, a.id
             LIMIT 1"
        );
        $stmt->execute([(int) $sentApp['candidate_id'], $applicationId]);
        $next = $stmt->fetch();
        $nextCompanyId = $next ? (int) $next['company_id'] : null;
        $markNext = $this->pdo->prepare(
            "UPDATE applications SET next_company_id = ?, updated_at = ? WHERE id = ? AND current_status = 'sent'"
        );
        $markNext->execute([$nextCompanyId, $now, $applicationId]);
        if ($markNext->rowCount() !== 1) {
            throw new UserVisibleException('PLACEMENT_BOARD_STALE', 'The application changed while its next handoff was being recorded. Reload the board and retry.');
        }
        if (!$next) {
            return;
        }

        $handoffTransition = $this->workflowEngine?->transitionForEffect(
            (int) $next['id'],
            'scheduled',
            'intransit'
        );
        $handoffNote = 'Auto-started after send-away from ' . (string) $sentApp['company_code'] . '.';
        $changed = $this->statusWriter->changeStatus(
            (int) $next['id'],
            'scheduled',
            (int) $next['aggregate_version'],
            'intransit',
            $actorId,
            $actorRole,
            $handoffNote,
            $now,
            ['source' => 'placement.start_next_scheduled'],
            $actorServiceAccountId,
        );
        $update = $this->pdo->prepare(
            "UPDATE applications SET previous_company_id = ?
             WHERE id = ? AND current_status = 'intransit' AND aggregate_version = ?",
        );
        $update->execute([(int) $sentApp['company_id'], (int) $next['id'], (int) $changed['aggregate_version']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The next application changed while handoff metadata was applied.');
        }

        $candidate = $sentApp;
        $candidate['current_location'] = 'CP';
        $this->updateCandidateLocation($candidate, (string) $next['company_code'], $now);

        if ($handoffTransition !== null) {
            $this->workflowEngine?->recordAppliedTransition(
                (int) $next['id'],
                $handoffTransition,
                $actorId,
                $actorRole,
                '',
                $handoffNote,
                ['source' => 'placement.start_next_scheduled'],
                $actorServiceAccountId,
            );
        }
        if ($actorServiceAccountId !== null) {
            Auth::audit(
                null,
                'transition',
                'application',
                (int) $next['id'],
                '',
                $this->pdo,
                $actorServiceAccountId,
            );
        } else {
            Auth::audit($actorId, 'application.auto_handoff', 'application', (int) $next['id'], 'Sent from ' . (string) $sentApp['company_code'] . ' to ' . (string) $next['company_code']);
        }
    }

    private function activeStatuses(): array
    {
        $statuses = $this->workflow->statuses();
        if (!$statuses) {
            return [];
        }
        $initial = $this->workflow->initialStateKey();
        return array_values(array_filter(
            array_keys($statuses),
            fn (string $key): bool => $key !== $initial && !$this->workflow->isTerminal($key)
        ));
    }

    private function activeApplicationSql(string $alias): string
    {
        $activeStatusSql = $this->statusListSql($this->activeStatuses());
        return "({$alias}.current_status IN ({$activeStatusSql}) AND NOT ({$alias}.current_status = 'sent' AND {$alias}.next_company_id IS NOT NULL))";
    }

    private function statusListSql(array $statuses): string
    {
        if ($statuses === []) {
            return "''";
        }
        return implode(', ', array_map(fn (string $status): string => $this->pdo->quote($status), $statuses));
    }

    private function notificationVisibilityWhere(array $user): array
    {
        $role = (string) ($user['role'] ?? '');
        if (in_array($role, ['admin', 'auditor'], true)) {
            return ['1 = 1', []];
        }

        $where = "(n.recipient_role = '' OR n.recipient_role = 'all' OR n.recipient_role = ?)";
        $params = [$role];
        $scopeType = trim((string) ($user['scope_type'] ?? ''));
        $scopeValue = strtoupper(trim((string) ($user['scope_value'] ?? '')));
        if ($scopeValue !== '') {
            $where .= " AND (n.recipient_scope_value = '' OR (n.recipient_scope_type = ? AND UPPER(n.recipient_scope_value) = ?))";
            array_push($params, $scopeType, $scopeValue);
        } else {
            $where .= " AND n.recipient_scope_value = ''";
        }
        return [$where, $params];
    }

    private function notifyRoles(array $roles, string $templateKey, string $subject, string $body, string $sourceType, int $sourceId, ?int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (recipient_role, channel, template_key, subject, body, source_type, source_id, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $delivery = new NotificationDeliveryService($this->pdo);
        $now = cpe_now();
        foreach (array_values(array_unique($roles)) as $role) {
            $stmt->execute([(string) $role, 'in_app', $templateKey, $subject, $body, $sourceType, $sourceId, $actorId, $now]);
            $delivery->queueForNotification(Database::lastInsertId($this->pdo));
        }
    }

    private function closeNotificationsForSource(string $sourceType, int $sourceId, ?int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE notifications
             SET status = ?, acknowledged_by = ?, acknowledged_at = ?
             WHERE source_type = ? AND source_id = ? AND status = ?'
        );
        $stmt->execute(['acknowledged', $actorId, cpe_now(), $sourceType, $sourceId, 'open']);
    }

    private function candidateLabel(int $candidateId): string
    {
        $stmt = $this->pdo->prepare('SELECT external_id, name FROM candidates WHERE id = ?');
        $stmt->execute([$candidateId]);
        $candidate = $stmt->fetch();
        if (!$candidate) {
            throw new UserVisibleException('PLACEMENT_CANDIDATE_NOT_FOUND', 'Candidate not found.');
        }
        return (string) $candidate['external_id'] . ' - ' . (string) $candidate['name'];
    }

    private function companyCodesForIds(array $companyIds): array
    {
        if (!$companyIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id, code FROM companies WHERE id IN ({$placeholders}) ORDER BY code");
        $stmt->execute($companyIds);
        $rows = $stmt->fetchAll();
        if (count($rows) !== count($companyIds)) {
            throw new UserVisibleException('PLACEMENT_COMPANY_OPTIONS_INVALID', 'One or more company options were not found.');
        }
        return array_map(fn (array $row): string => (string) $row['code'], $rows);
    }

    private function normalizeStaleMinutes(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 90;
        }
        $minutes = filter_var($value, FILTER_VALIDATE_INT);
        if ($minutes === false) {
            return 90;
        }
        return max(15, min(1440, $minutes));
    }

    private function normalizedBoardCardFieldKeys(array|string $value): array
    {
        $items = is_array($value) ? $value : explode(',', $value);
        $fields = array_map(
            fn (mixed $field): string => trim((string) $field),
            $items
        );
        $fields = array_values(array_unique(array_filter($fields)));
        return array_values(array_filter(
            $fields,
            fn (string $field): bool => isset(self::BOARD_CARD_FIELD_OPTIONS[$field])
        ));
    }

    private function staleCutoff(int $minutes = 90): string
    {
        return gmdate('Y-m-d H:i:s', time() - $minutes * 60);
    }

    private function synchronizeDurableDomain(): void
    {
        (new LegacyDomainSynchronizer())->synchronize($this->pdo);
    }

    private function publicIdFor(string $table, int $id): string
    {
        if (!in_array($table, ['candidates', 'companies', 'applications'], true)) {
            throw new RuntimeException('Unsupported public id table.');
        }
        $stmt = $this->pdo->prepare("SELECT public_id FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return (string) ($stmt->fetchColumn() ?: $table . '_' . $id);
    }
}
