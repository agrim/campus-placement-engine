<?php

declare(strict_types=1);

namespace App\Domain;

use App\Modules\Placement\Install\LegacyDomainSynchronizer;
use App\Support\Auth;
use App\Support\Database;
use PDO;

final class DemoDataService
{
    private const SMALL_CANDIDATE_IDS = ['C001', 'C002', 'C003', 'C004', 'C005'];
    private const SMALL_COMPANY_CODES = ['ATLAS', 'NOVA', 'RIVER'];
    private const DEMO_USER_EMAILS = [
        'control@example.test',
        'atlas@example.test',
        'mobile@example.test',
        'floor@example.test',
        'placement@example.test',
        'auditor@example.test',
    ];

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $candidateWhere = $this->candidateWhere();
        $companyWhere = $this->companyWhere();

        return [
            'candidates' => $this->count("SELECT COUNT(*) FROM candidates WHERE {$candidateWhere}"),
            'companies' => $this->count("SELECT COUNT(*) FROM companies WHERE {$companyWhere}"),
            'applications' => $this->count(
                "SELECT COUNT(*)
                 FROM applications a
                 JOIN candidates c ON c.id = a.candidate_id
                 JOIN companies co ON co.id = a.company_id
                 WHERE {$this->candidateWhere('c')} OR {$this->companyWhere('co')}"
            ),
            'rounds' => $this->count(
                "SELECT COUNT(*)
                 FROM company_rounds cr
                 JOIN companies co ON co.id = cr.company_id
                 WHERE {$this->companyWhere('co')}"
            ),
            'schedules' => $this->count(
                "SELECT COUNT(*)
                 FROM round_schedules rs
                 JOIN company_rounds cr ON cr.id = rs.round_id
                 JOIN companies co ON co.id = cr.company_id
                 WHERE {$this->companyWhere('co')}"
            ),
            'panelists' => $this->count(
                "SELECT COUNT(*)
                 FROM round_panelists rp
                 JOIN company_rounds cr ON cr.id = rp.round_id
                 JOIN companies co ON co.id = cr.company_id
                 WHERE {$this->companyWhere('co')}"
            ),
            'slot_assignments' => $this->count(
                "SELECT COUNT(*)
                 FROM application_slot_assignments asa
                 JOIN applications a ON a.id = asa.application_id
                 JOIN candidates c ON c.id = a.candidate_id
                 JOIN companies co ON co.id = a.company_id
                 WHERE {$this->candidateWhere('c')} OR {$this->companyWhere('co')}"
            ),
            'demo_users' => $this->count('SELECT COUNT(*) FROM users WHERE ' . $this->demoUserWhere()),
        ];
    }

    public function hasDemoData(): bool
    {
        return array_sum($this->counts()) > 0;
    }

    /**
     * @return array{before: array<string, int>, after: array<string, int>, deleted: array<string, int>}
     */
    public function clear(?int $actorId): array
    {
        $before = $this->counts();
        $this->pdo->beginTransaction();
        try {
            $candidateIds = $this->ids('SELECT id FROM candidates WHERE ' . $this->candidateWhere());
            $companyIds = $this->ids('SELECT id FROM companies WHERE ' . $this->companyWhere());
            $demoUserIds = $this->ids('SELECT id FROM users WHERE ' . $this->demoUserWhere());
            $applicationIds = $this->ids(
                'SELECT id FROM applications WHERE ' .
                $this->idWhere('candidate_id', $candidateIds) . ' OR ' .
                $this->idWhere('company_id', $companyIds)
            );
            $roundIds = $this->ids('SELECT id FROM company_rounds WHERE ' . $this->idWhere('company_id', $companyIds));
            $scheduleIds = $this->ids('SELECT id FROM round_schedules WHERE ' . $this->idWhere('round_id', $roundIds));
            $preferenceIds = $this->ids(
                'SELECT DISTINCT pr.id
                 FROM preference_requests pr
                 LEFT JOIN preference_options po ON po.request_id = pr.id
                 WHERE ' . $this->idWhere('pr.candidate_id', $candidateIds) . '
                    OR ' . $this->idWhere('pr.decision_company_id', $companyIds) . '
                    OR ' . $this->idWhere('pr.requested_by', $demoUserIds) . '
                    OR ' . $this->idWhere('po.company_id', $companyIds)
            );
            $wantedIds = $this->ids(
                'SELECT id FROM wanted_alerts WHERE ' .
                $this->idWhere('candidate_id', $candidateIds) . ' OR ' .
                $this->idWhere('created_by', $demoUserIds) . ' OR ' .
                $this->idWhere('resolved_by', $demoUserIds)
            );
            $notificationIds = $this->ids(
                "SELECT id FROM notifications
                 WHERE (source_type = 'preference_request' AND " . $this->idWhere('source_id', $preferenceIds) . ')
                    OR (source_type = \'wanted_alert\' AND ' . $this->idWhere('source_id', $wantedIds) . ')
                    OR ' . $this->idWhere('created_by', $demoUserIds) . '
                    OR ' . $this->idWhere('acknowledged_by', $demoUserIds) . '
                    OR (recipient_scope_type = \'company\' AND ' . $this->textWhere('recipient_scope_value', [...self::SMALL_COMPANY_CODES, 'QA%']) . ')'
            );

            $this->deleteWhereIds('notification_deliveries', 'notification_id', $notificationIds);
            $this->deleteWhereIds('notifications', 'id', $notificationIds);
            $this->deleteWhereIds('idempotency_keys', 'application_id', $applicationIds);
            $this->deleteWhereIds('idempotency_keys', 'actor_user_id', $demoUserIds);
            $this->deleteWhereIds('user_board_preferences', 'user_id', $demoUserIds);
            $this->deleteAuditLogs($candidateIds, $companyIds, $applicationIds, $preferenceIds, $wantedIds, $demoUserIds);
            $this->deleteWhereIds('application_slot_assignments', 'application_id', $applicationIds);
            $this->deleteWhereIds('application_slot_assignments', 'round_schedule_id', $scheduleIds);
            $this->deleteWhereIds('candidate_unavailability_windows', 'candidate_id', $candidateIds);
            $this->deleteWhereIds('events', 'application_id', $applicationIds);
            $this->deleteWhereIds('events', 'candidate_id', $candidateIds);
            $this->deleteWhereIds('events', 'company_id', $companyIds);
            $this->nullWhereIds('events', 'actor_user_id', $demoUserIds);
            $this->deleteWhereIds('preference_options', 'request_id', $preferenceIds);
            $this->deleteWhereIds('preference_options', 'company_id', $companyIds);
            $this->deleteWhereIds('preference_requests', 'id', $preferenceIds);
            $this->nullWhereIds('preference_requests', 'requested_by', $demoUserIds);
            $this->nullWhereIds('preference_requests', 'decision_company_id', $companyIds);
            $this->deleteWhereIds('wanted_alerts', 'id', $wantedIds);
            $this->nullWhereIds('wanted_alerts', 'created_by', $demoUserIds);
            $this->nullWhereIds('wanted_alerts', 'resolved_by', $demoUserIds);
            $this->nullWhereIds('notifications', 'created_by', $demoUserIds);
            $this->nullWhereIds('notifications', 'acknowledged_by', $demoUserIds);
            $this->nullWhereIds('candidates', 'placed_company_id', $companyIds);
            $this->nullWhereIds('applications', 'previous_company_id', $companyIds);
            $this->nullWhereIds('applications', 'next_company_id', $companyIds);
            $this->deleteWhereIds('applications', 'id', $applicationIds);
            $this->deleteWhereIds('round_panelists', 'round_id', $roundIds);
            $this->deleteWhereIds('round_schedules', 'id', $scheduleIds);
            $this->deleteWhereIds('round_schedules', 'round_id', $roundIds);
            $this->deleteWhereIds('company_rounds', 'id', $roundIds);
            $this->deleteWhereIds('company_rounds', 'company_id', $companyIds);
            $this->deleteWhereIds('candidates', 'id', $candidateIds);
            $this->deleteWhereIds('companies', 'id', $companyIds);
            $this->deleteWhereIds('users', 'id', $demoUserIds);
            $this->set('synthetic_demo_data_loaded', '0');
            $this->set('synthetic_demo_data_cleared_at', cpe_now());

            (new LegacyDomainSynchronizer())->synchronize($this->pdo);
            Auth::audit($actorId, 'demo_data.clear', 'system', null, $this->summary($before));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $after = $this->counts();
        $deleted = [];
        foreach ($before as $key => $value) {
            $deleted[$key] = max(0, $value - ($after[$key] ?? 0));
        }
        return ['before' => $before, 'after' => $after, 'deleted' => $deleted];
    }

    public function markLoaded(): void
    {
        $this->set('synthetic_demo_data_loaded', '1');
        $this->set('synthetic_demo_data_cleared_at', '');
    }

    private function candidateWhere(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $ids = array_map(fn (string $id): string => $this->pdo->quote($id), self::SMALL_CANDIDATE_IDS);
        return '(' . $prefix . 'external_id IN (' . implode(',', $ids) . ') OR '
            . Database::exactNumericPattern($prefix . 'external_id', 'QAC', 3) . ')';
    }

    private function companyWhere(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $codes = array_map(fn (string $code): string => $this->pdo->quote($code), self::SMALL_COMPANY_CODES);
        return '(' . $prefix . 'code IN (' . implode(',', $codes) . ') OR '
            . Database::exactNumericPattern($prefix . 'code', 'QA', 2) . ')';
    }

    private function demoUserWhere(): string
    {
        $emails = array_map(fn (string $email): string => $this->pdo->quote($email), self::DEMO_USER_EMAILS);
        return 'lower(email) IN (' . implode(',', $emails) . ')';
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /**
     * @return array<int, int>
     */
    private function ids(string $sql): array
    {
        return array_map('intval', $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array<int, int> $ids
     */
    private function idWhere(string $column, array $ids): string
    {
        if ($ids === []) {
            return '0 = 1';
        }
        return $column . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
    }

    /**
     * @param array<int, string> $values
     */
    private function textWhere(string $column, array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            if (str_ends_with($value, '%')) {
                $parts[] = $column . ' LIKE ' . $this->pdo->quote($value);
            } else {
                $parts[] = 'upper(' . $column . ') = ' . $this->pdo->quote(strtoupper($value));
            }
        }
        return $parts === [] ? '0 = 1' : '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * @param array<int, int> $ids
     */
    private function deleteWhereIds(string $table, string $column, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->pdo->exec("DELETE FROM {$table} WHERE " . $this->idWhere($column, $ids));
    }

    /**
     * @param array<int, int> $ids
     */
    private function nullWhereIds(string $table, string $column, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->pdo->exec("UPDATE {$table} SET {$column} = NULL WHERE " . $this->idWhere($column, $ids));
    }

    /**
     * @param array<int, int> $candidateIds
     * @param array<int, int> $companyIds
     * @param array<int, int> $applicationIds
     * @param array<int, int> $preferenceIds
     * @param array<int, int> $wantedIds
     * @param array<int, int> $demoUserIds
     */
    private function deleteAuditLogs(array $candidateIds, array $companyIds, array $applicationIds, array $preferenceIds, array $wantedIds, array $demoUserIds): void
    {
        $clauses = [];
        foreach ([
            ['candidate', $candidateIds],
            ['company', $companyIds],
            ['application', $applicationIds],
            ['preference_request', $preferenceIds],
            ['wanted_alert', $wantedIds],
            ['user', $demoUserIds],
        ] as [$subjectType, $ids]) {
            if ($ids !== []) {
                $clauses[] = "(subject_type = " . $this->pdo->quote((string) $subjectType) . ' AND ' . $this->idWhere('subject_id', $ids) . ')';
            }
        }
        if ($demoUserIds !== []) {
            $clauses[] = $this->idWhere('actor_user_id', $demoUserIds);
        }
        if ($clauses !== []) {
            $this->pdo->exec('DELETE FROM audit_logs WHERE ' . implode(' OR ', $clauses));
        }
    }

    private function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute([$key, $value]);
    }

    /**
     * @param array<string, int> $counts
     */
    private function summary(array $counts): string
    {
        return sprintf(
            'Cleared synthetic demo data: %d candidates, %d companies, %d applications, %d demo users.',
            $counts['candidates'] ?? 0,
            $counts['companies'] ?? 0,
            $counts['applications'] ?? 0,
            $counts['demo_users'] ?? 0
        );
    }
}
