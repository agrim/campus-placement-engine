<?php

declare(strict_types=1);

namespace App\Modules\Placement\Application;

use App\Domain\Workflow;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read-only university action queues assembled from existing placement state.
 *
 * This service deliberately creates no parallel workflow or eligibility state.
 * Where the Engine has only a proxy, the returned copy names that limitation.
 */
final class UniversityOperationsWorkspace
{
    private const MAX_ROWS = 100;
    private const DEADLINE_LOOKAHEAD_DAYS = 7;

    private PDO $pdo;
    private Workflow $workflow;
    private DateTimeImmutable $now;

    public function __construct(
        ?PDO $pdo = null,
        ?Workflow $workflow = null,
        ?DateTimeImmutable $now = null,
    ) {
        $this->pdo = $pdo ?? Database::connection();
        $this->workflow = $workflow ?? new Workflow();
        $this->now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    public function snapshot(bool $includeAdvising): array
    {
        $coverage = $this->coverageNeeded();
        $eligibility = $this->eligibilityEvidenceReview();
        $deadlines = $this->configuredProcessDeadlines();
        $clashes = $this->scheduleClashes();
        $attendance = $this->attendanceFollowUp();
        $repeatedNoProgress = $this->repeatedNoProgress();
        $lowCoverage = $this->opportunitiesWithoutRecordedCoverage();
        $adviserActions = $includeAdvising ? $this->adviserActionsDue() : $this->emptyQueue();

        return [
            'generated_at' => $this->now->format('Y-m-d\TH:i:s\Z'),
            'advising_available' => $includeAdvising,
            'summary' => [
                'coverage_needed' => $coverage['count'],
                'eligibility_review' => $eligibility['count'],
                'schedule_clashes' => $clashes['count'],
                'attendance_follow_up' => $attendance['count'],
                'adviser_actions_due' => $adviserActions['count'],
            ],
            'queues' => [
                'coverage_needed' => $coverage,
                'eligibility_review' => $eligibility,
                'configured_deadlines' => $deadlines,
                'schedule_clashes' => $clashes,
                'attendance_follow_up' => $attendance,
                'repeated_no_progress' => $repeatedNoProgress,
                'low_coverage_opportunities' => $lowCoverage,
                'adviser_actions_due' => $adviserActions,
            ],
            'evidence_notes' => [
                'coverage' => 'Coverage uses active, not-opted-out, unplaced candidates with no active application. It is a coverage proxy, not a formal eligibility decision.',
                'eligibility' => 'The Engine has no durable eligibility-rule result. Missing program data or no candidate-opportunity link is surfaced for review without inventing an eligibility outcome.',
                'deadlines' => 'Configured company deadline fields are process finish cut-offs, not application close dates. Application deadlines remain deferred until a governed source exists.',
                'attendance' => 'Assignment status is the durable attendance signal. Assigned, pending, invited, no-show, absent, missed, delayed, and unknown local labels need follow-up; the Engine does not claim a separate candidate-response state.',
                'clashes' => 'Clashes are overlapping active slot assignments with documented day and HH:MM windows. Rescheduling owner, employer contact state, and escalation deadline are omitted because no durable fields currently support them.',
                'low_coverage' => 'Zero recorded candidate links is the strongest current low-coverage signal. Configured active caps are safety limits, not recruitment targets.',
            ],
        ];
    }

    /** @return array{count: int, rows: list<array<string, mixed>>, truncated: bool} */
    private function coverageNeeded(): array
    {
        $active = $this->activeApplicationSql('a');
        $sql = "SELECT c.id AS candidate_id, c.public_id AS candidate_public_id,
                       c.external_id, c.name AS candidate_name, c.program,
                       COUNT(a.id) AS application_count,
                       SUM(CASE WHEN {$active} THEN 1 ELSE 0 END) AS active_application_count
                FROM candidates c
                LEFT JOIN applications a ON a.candidate_id = c.id
                WHERE c.opted_out = 0
                  AND c.placed_company_id IS NULL
                  AND (c.anonymized_at IS NULL OR c.anonymized_at = '')
                GROUP BY c.id, c.public_id, c.external_id, c.name, c.program
                HAVING SUM(CASE WHEN {$active} THEN 1 ELSE 0 END) = 0
                ORDER BY COUNT(a.id), c.external_id";

        return $this->queue($sql, [], static fn (array $row): array => [
            ...$row,
            'candidate_id' => (int) $row['candidate_id'],
            'application_count' => (int) $row['application_count'],
            'active_application_count' => (int) $row['active_application_count'],
        ]);
    }

    /** @return array{count: int, rows: list<array<string, mixed>>, truncated: bool} */
    private function eligibilityEvidenceReview(): array
    {
        $sql = "SELECT c.id AS candidate_id, c.public_id AS candidate_public_id,
                       c.external_id, c.name AS candidate_name, c.program,
                       COUNT(a.id) AS application_count
                FROM candidates c
                LEFT JOIN applications a ON a.candidate_id = c.id
                WHERE c.opted_out = 0
                  AND c.placed_company_id IS NULL
                  AND (c.anonymized_at IS NULL OR c.anonymized_at = '')
                GROUP BY c.id, c.public_id, c.external_id, c.name, c.program
                HAVING TRIM(c.program) = '' OR COUNT(a.id) = 0
                ORDER BY CASE WHEN TRIM(c.program) = '' THEN 0 ELSE 1 END, c.external_id";

        return $this->queue($sql, [], static function (array $row): array {
            $reasons = [];
            if (trim((string) $row['program']) === '') {
                $reasons[] = 'Program missing';
            }
            if ((int) $row['application_count'] === 0) {
                $reasons[] = 'No candidate-opportunity link';
            }
            return [
                ...$row,
                'candidate_id' => (int) $row['candidate_id'],
                'application_count' => (int) $row['application_count'],
                'reason' => implode('; ', $reasons),
            ];
        });
    }

    /** @return array{count: int, rows: list<array<string, mixed>>, truncated: bool} */
    private function configuredProcessDeadlines(): array
    {
        $rows = $this->pdo->query(
            "SELECT co.id AS company_id, co.code AS company_code, co.name AS company_name,
                    co.deadline_day, co.deadline_at, co.process_type
             FROM companies co
             LEFT JOIN placement_opportunities po ON po.legacy_company_id = co.id
             WHERE (TRIM(co.deadline_day) <> '' OR TRIM(co.deadline_at) <> '')
               AND (po.id IS NULL OR po.status = 'open')
             ORDER BY co.code",
        )->fetchAll(PDO::FETCH_ASSOC);

        $timezone = $this->institutionTimezone();
        $localNow = $this->now->setTimezone($timezone);
        $cycleStart = $this->setting('cycle_start_date');
        $result = [];
        foreach ($rows as $row) {
            $deadline = $this->deadlineInstant(
                (string) $row['deadline_day'],
                (string) $row['deadline_at'],
                $cycleStart,
                $timezone,
            );
            if ($deadline === null) {
                $result[] = [
                    ...$row,
                    'company_id' => (int) $row['company_id'],
                    'deadline_display' => trim((string) $row['deadline_day'] . ' ' . (string) $row['deadline_at']),
                    'deadline_status' => 'Needs date setup',
                    'seconds_until' => null,
                ];
                continue;
            }
            $seconds = $deadline->getTimestamp() - $localNow->getTimestamp();
            if ($seconds < -86400 || $seconds > self::DEADLINE_LOOKAHEAD_DAYS * 86400) {
                continue;
            }
            $result[] = [
                ...$row,
                'company_id' => (int) $row['company_id'],
                'deadline_display' => $deadline->format('Y-m-d H:i T'),
                'deadline_status' => $seconds < 0 ? 'Overdue' : ($seconds <= 86400 ? 'Due within 24 hours' : 'Approaching'),
                'seconds_until' => $seconds,
            ];
        }
        usort($result, static function (array $left, array $right): int {
            $leftSeconds = $left['seconds_until'];
            $rightSeconds = $right['seconds_until'];
            return [
                $leftSeconds === null ? 1 : 0,
                $leftSeconds ?? PHP_INT_MAX,
                (string) $left['company_code'],
            ] <=> [
                $rightSeconds === null ? 1 : 0,
                $rightSeconds ?? PHP_INT_MAX,
                (string) $right['company_code'],
            ];
        });

        return $this->arrayQueue($result);
    }

    /** @return array{count: int, rows: list<array<string, mixed>>, truncated: bool} */
    private function scheduleClashes(): array
    {
        $normalizedFirstStatus = "LOWER(REPLACE(REPLACE(TRIM(asa1.assignment_status), '_', '-'), ' ', '-'))";
        $normalizedSecondStatus = "LOWER(REPLACE(REPLACE(TRIM(asa2.assignment_status), '_', '-'), ' ', '-'))";
        $inactive = "('cancelled', 'done', 'completed', 'checked-in', 'attended', 'no-show', 'absent', 'missed', 'declined', 'rejected')";
        $sql = "SELECT c.id AS candidate_id, c.public_id AS candidate_public_id,
                       c.external_id, c.name AS candidate_name,
                       co1.code AS first_company_code, co1.name AS first_company_name,
                       cr1.label AS first_round_label, cr1.round_type AS first_round_type,
                       co1.process_type AS first_process_type,
                       rs1.schedule_day, rs1.starts_at AS first_starts_at, rs1.ends_at AS first_ends_at,
                       asa1.assignment_status AS first_assignment_status,
                       co2.code AS second_company_code, co2.name AS second_company_name,
                       cr2.label AS second_round_label, cr2.round_type AS second_round_type,
                       co2.process_type AS second_process_type,
                       rs2.starts_at AS second_starts_at, rs2.ends_at AS second_ends_at,
                       asa2.assignment_status AS second_assignment_status
                FROM application_slot_assignments asa1
                JOIN applications a1 ON a1.id = asa1.application_id
                JOIN candidates c ON c.id = a1.candidate_id
                JOIN companies co1 ON co1.id = a1.company_id
                JOIN round_schedules rs1 ON rs1.id = asa1.round_schedule_id
                JOIN company_rounds cr1 ON cr1.id = rs1.round_id
                JOIN application_slot_assignments asa2 ON asa2.id > asa1.id
                JOIN applications a2 ON a2.id = asa2.application_id AND a2.candidate_id = a1.candidate_id
                JOIN companies co2 ON co2.id = a2.company_id
                JOIN round_schedules rs2 ON rs2.id = asa2.round_schedule_id
                JOIN company_rounds cr2 ON cr2.id = rs2.round_id
                WHERE {$normalizedFirstStatus} NOT IN {$inactive}
                  AND {$normalizedSecondStatus} NOT IN {$inactive}
                  AND rs1.schedule_status = 'active' AND rs2.schedule_status = 'active'
                  AND TRIM(rs1.starts_at) <> '' AND TRIM(rs1.ends_at) <> ''
                  AND TRIM(rs2.starts_at) <> '' AND TRIM(rs2.ends_at) <> ''
                  AND rs1.schedule_day = rs2.schedule_day
                  AND rs1.starts_at < rs2.ends_at AND rs2.starts_at < rs1.ends_at
                ORDER BY rs1.schedule_day, rs1.starts_at, c.external_id, co1.code, co2.code";

        return $this->queue($sql, [], function (array $row): array {
            return [
                ...$row,
                'candidate_id' => (int) $row['candidate_id'],
                'clash_kind' => $this->clashKind($row),
            ];
        });
    }

    /** @return array{count: int, rows: list<array<string, mixed>>, truncated: bool} */
    private function attendanceFollowUp(): array
    {
        $normalizedStatus = "LOWER(REPLACE(REPLACE(TRIM(asa.assignment_status), '_', '-'), ' ', '-'))";
        $completed = "('confirmed', 'accepted', 'checked-in', 'attended', 'done', 'completed', 'cancelled')";
        $sql = "SELECT c.id AS candidate_id, c.public_id AS candidate_public_id,
                       c.external_id, c.name AS candidate_name,
                       co.code AS company_code, co.name AS company_name,
                       cr.label AS round_label, rs.schedule_day, rs.starts_at, rs.ends_at,
                       asa.assignment_status, asa.updated_at
                FROM application_slot_assignments asa
                JOIN applications a ON a.id = asa.application_id
                JOIN candidates c ON c.id = a.candidate_id
                JOIN companies co ON co.id = a.company_id
                JOIN round_schedules rs ON rs.id = asa.round_schedule_id
                JOIN company_rounds cr ON cr.id = rs.round_id
                WHERE {$normalizedStatus} NOT IN {$completed}
                  AND rs.schedule_status = 'active'
                ORDER BY rs.schedule_day, rs.starts_at, asa.updated_at, c.external_id";

        return $this->queue($sql, [], static function (array $row): array {
            $status = strtolower(str_replace(['_', ' '], '-', trim((string) $row['assignment_status'])));
            $followUp = in_array($status, ['no-show', 'absent', 'missed'], true)
                ? 'Attendance follow-up'
                : (in_array($status, ['', 'assigned', 'pending', 'invited'], true)
                    ? 'Confirmation needed'
                    : 'Review local status');
            return [
                ...$row,
                'candidate_id' => (int) $row['candidate_id'],
                'follow_up' => $followUp,
            ];
        });
    }

    /** @return array{count: int, rows: list<array<string, mixed>>, truncated: bool} */
    private function repeatedNoProgress(): array
    {
        $initial = $this->workflow->initialStateKey();
        $sql = "SELECT c.id AS candidate_id, c.public_id AS candidate_public_id,
                       c.external_id, c.name AS candidate_name, c.program,
                       SUM(CASE WHEN a.current_status = ?
                                 AND EXISTS (SELECT 1 FROM events e WHERE e.application_id = a.id)
                                THEN 1 ELSE 0 END) AS no_progress_count
                FROM candidates c
                JOIN applications a ON a.candidate_id = c.id
                WHERE c.opted_out = 0
                  AND c.placed_company_id IS NULL
                  AND (c.anonymized_at IS NULL OR c.anonymized_at = '')
                GROUP BY c.id, c.public_id, c.external_id, c.name, c.program
                HAVING SUM(CASE WHEN a.current_status = ?
                                  AND EXISTS (SELECT 1 FROM events e WHERE e.application_id = a.id)
                                 THEN 1 ELSE 0 END) >= 2
                ORDER BY no_progress_count DESC, c.external_id";

        return $this->queue($sql, [$initial, $initial], static fn (array $row): array => [
            ...$row,
            'candidate_id' => (int) $row['candidate_id'],
            'no_progress_count' => (int) $row['no_progress_count'],
        ]);
    }

    /** @return array{count: int, rows: list<array<string, mixed>>, truncated: bool} */
    private function opportunitiesWithoutRecordedCoverage(): array
    {
        $sql = "SELECT co.id AS company_id, co.code AS company_code, co.name AS company_name,
                       co.process_type, co.max_active, COUNT(a.id) AS application_count
                FROM companies co
                LEFT JOIN applications a ON a.company_id = co.id
                LEFT JOIN placement_opportunities po ON po.legacy_company_id = co.id
                WHERE po.id IS NULL OR po.status = 'open'
                GROUP BY co.id, co.code, co.name, co.process_type, co.max_active
                HAVING COUNT(a.id) = 0
                ORDER BY co.code";

        return $this->queue($sql, [], static fn (array $row): array => [
            ...$row,
            'company_id' => (int) $row['company_id'],
            'max_active' => (int) $row['max_active'],
            'application_count' => (int) $row['application_count'],
        ]);
    }

    /** @return array{count: int, rows: list<array<string, mixed>>, truncated: bool} */
    private function adviserActionsDue(): array
    {
        $today = $this->now->setTimezone($this->institutionTimezone())->format('Y-m-d');
        $through = $this->now->setTimezone($this->institutionTimezone())
            ->modify('+' . self::DEADLINE_LOOKAHEAD_DAYS . ' days')
            ->format('Y-m-d');
        $sql = "SELECT t.public_id AS task_public_id, t.task_type, t.title, t.due_on,
                       t.subject_reference, sp.external_id,
                       p.display_name AS candidate_name, p.legacy_candidate_id AS candidate_id
                FROM advising_tasks t
                LEFT JOIN student_profiles sp ON sp.id = t.student_profile_id
                LEFT JOIN people p ON p.id = sp.person_id
                WHERE t.task_status = 'open'
                  AND TRIM(t.due_on) <> ''
                  AND t.due_on <= ?
                ORDER BY CASE WHEN t.due_on < ? THEN 0 ELSE 1 END, t.due_on, t.created_at";

        return $this->queue($sql, [$through, $today], static fn (array $row): array => [
            ...$row,
            'candidate_id' => $row['candidate_id'] === null ? null : (int) $row['candidate_id'],
            'due_status' => (string) $row['due_on'] < $today ? 'Overdue' : 'Due soon',
        ]);
    }

    /**
     * @param list<mixed> $params
     * @param callable(array<string, mixed>): array<string, mixed> $transform
     * @return array{count: int, rows: list<array<string, mixed>>, truncated: bool}
     */
    private function queue(string $sql, array $params, callable $transform): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM (' . $sql . ') cpe_workspace_count');
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $rows = $this->pdo->prepare($sql . ' LIMIT ' . self::MAX_ROWS);
        $rows->execute($params);
        return [
            'count' => $total,
            'rows' => array_map($transform, $rows->fetchAll(PDO::FETCH_ASSOC)),
            'truncated' => $total > self::MAX_ROWS,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function arrayQueue(array $rows): array
    {
        return [
            'count' => count($rows),
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
            'truncated' => count($rows) > self::MAX_ROWS,
        ];
    }

    /** @return array{count: int, rows: list<array<string, mixed>>, truncated: bool} */
    private function emptyQueue(): array
    {
        return ['count' => 0, 'rows' => [], 'truncated' => false];
    }

    private function activeApplicationSql(string $alias): string
    {
        $active = [];
        foreach (array_keys($this->workflow->statuses()) as $status) {
            if ($status !== $this->workflow->initialStateKey() && !$this->workflow->isTerminal($status)) {
                $active[] = $status;
            }
        }
        $quoted = $active === [] ? "''" : implode(', ', array_map($this->pdo->quote(...), $active));
        return "({$alias}.current_status IN ({$quoted})"
            . " AND NOT ({$alias}.current_status = 'sent' AND {$alias}.next_company_id IS NOT NULL))";
    }

    /** @param array<string, mixed> $row */
    private function clashKind(array $row): string
    {
        $text = strtolower(implode(' ', [
            (string) $row['first_round_label'],
            (string) $row['first_round_type'],
            (string) $row['first_process_type'],
            (string) $row['second_round_label'],
            (string) $row['second_round_type'],
            (string) $row['second_process_type'],
        ]));
        if (preg_match('/\b(?:assessment|test|exam|case|group discussion|gd)\b/', $text) === 1) {
            return 'Assessment clash';
        }
        if (preg_match('/\b(?:interview|technical|hr|panel)\b/', $text) === 1) {
            return 'Interview clash';
        }
        return 'Schedule clash';
    }

    private function deadlineInstant(
        string $day,
        string $time,
        string $cycleStart,
        DateTimeZone $timezone,
    ): ?DateTimeImmutable {
        $day = trim($day);
        $time = trim($time);
        if (preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $time) !== 1) {
            return null;
        }
        $date = null;
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $day) === 1) {
            $date = $day;
        } elseif (preg_match('/\A\d+\z/D', $day) === 1
            && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $cycleStart) === 1
            && ($cycleDay = filter_var(
                $day,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 36600]],
            )) !== false) {
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $cycleStart, $timezone);
            if ($start instanceof DateTimeImmutable && $start->format('Y-m-d') === $cycleStart) {
                try {
                    $date = $start->modify('+' . ($cycleDay - 1) . ' days')->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            }
        }
        if ($date === null) {
            return null;
        }
        $deadline = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);
        return $deadline instanceof DateTimeImmutable && $deadline->format('Y-m-d H:i') === $date . ' ' . $time
            ? $deadline
            : null;
    }

    private function institutionTimezone(): DateTimeZone
    {
        try {
            return new DateTimeZone($this->setting('timezone') ?: 'UTC');
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    private function setting(string $key): string
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $statement->execute([$key]);
        return (string) ($statement->fetchColumn() ?: '');
    }
}
