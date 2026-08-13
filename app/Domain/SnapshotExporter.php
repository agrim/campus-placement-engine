<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Database;
use App\Support\Csv;
use PDO;
use RuntimeException;

final class SnapshotExporter
{
    private const PROFILE_DATASETS = [
        'full' => [],
        'operations' => [
            'candidates',
            'companies',
            'company_rounds',
            'round_panelists',
            'round_schedules',
            'application_slot_assignments',
            'candidate_unavailability_windows',
            'applications',
            'events',
            'preference_requests',
            'preference_options',
            'wanted_alerts',
        ],
        'summary' => [
            'placement_totals',
            'application_status_counts',
            'placements_by_company',
            'candidates_by_program',
            'candidates_by_location',
        ],
        'custom' => null,
    ];

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public static function profiles(): array
    {
        return array_keys(self::PROFILE_DATASETS);
    }

    public function datasetNames(): array
    {
        return array_keys($this->datasets());
    }

    public function normalizeDatasetList(string|array $value): string
    {
        $raw = is_array($value) ? $value : explode(',', $value);
        $known = array_fill_keys($this->datasetNames(), true);
        $normalized = [];
        foreach ($raw as $item) {
            $name = strtolower(trim((string) $item));
            if ($name === '') {
                continue;
            }
            if (!isset($known[$name])) {
                throw new RuntimeException('Unknown export dataset: ' . $name);
            }
            $normalized[$name] = true;
        }
        if ($normalized === []) {
            return (string) cpe_config('settings.export_profile_custom_datasets', 'placement_totals,application_status_counts,placements_by_company');
        }
        return implode(',', array_keys($normalized));
    }

    public function export(?string $targetDir = null, string $profile = 'full'): array
    {
        $profile = $this->normalizeProfile($profile);
        $dir = $this->prepareDirectory($targetDir);
        $files = [];
        foreach ($this->datasetsForProfile($profile) as $name => $dataset) {
            $path = $dir . '/' . $name . '.csv';
            $rowCount = $this->writeCsv($path, $dataset['headers'], $this->pdo->query($dataset['sql'])->fetchAll());
            $files[] = ['file' => basename($path), 'rows' => $rowCount];
        }
        $this->writeCsv($dir . '/manifest.csv', ['file', 'rows'], $files);
        return ['dir' => $dir, 'files' => $files, 'profile' => $profile];
    }

    private function prepareDirectory(?string $targetDir): string
    {
        $dir = $targetDir ?: cpe_data_path('exports/snapshot-' . gmdate('Ymd-His'));
        if (is_file($dir)) {
            throw new RuntimeException('Export target is a file: ' . $dir);
        }
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Could not create export directory: ' . $dir);
        }
        $existing = array_diff(scandir($dir) ?: [], ['.', '..']);
        if ($existing !== []) {
            throw new RuntimeException('Export directory must be empty: ' . $dir);
        }
        return $dir;
    }

    private function writeCsv(string $path, array $headers, array $rows): int
    {
        $handle = fopen($path, 'w');
        if (!$handle) {
            throw new RuntimeException('Could not write export file: ' . $path);
        }
        Csv::writeRow($handle, $headers);
        $count = 0;
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            Csv::writeRow($handle, $line);
            $count++;
        }
        fclose($handle);
        return $count;
    }

    private function normalizeProfile(string $profile): string
    {
        $profile = strtolower(trim($profile));
        if ($profile === '') {
            $profile = 'full';
        }
        if (!array_key_exists($profile, self::PROFILE_DATASETS)) {
            throw new RuntimeException('Unknown export profile. Choose one of: ' . implode(', ', self::profiles()));
        }
        return $profile;
    }

    private function datasetsForProfile(string $profile): array
    {
        $datasets = $this->datasets();
        if ($profile === 'full') {
            return $datasets;
        }
        $names = $profile === 'custom'
            ? explode(',', $this->customProfileDatasets())
            : self::PROFILE_DATASETS[$profile];
        $selected = [];
        foreach ($names as $name) {
            if (!isset($datasets[$name])) {
                throw new RuntimeException('Export profile references an unknown dataset: ' . $name);
            }
            $selected[$name] = $datasets[$name];
        }
        return $selected;
    }

    private function customProfileDatasets(): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute(['export_profile_custom_datasets']);
        $value = (string) ($stmt->fetchColumn() ?: cpe_config('settings.export_profile_custom_datasets', ''));
        return $this->normalizeDatasetList($value);
    }

    private function datasets(): array
    {
        return [
            'placement_totals' => [
                'headers' => ['key', 'label', 'count'],
                'sql' => "SELECT key, label, count FROM (
                              SELECT 'candidates' AS key, 'Candidates' AS label, COUNT(*) AS count, 1 AS sort_order FROM candidates
                              UNION ALL SELECT 'placed_candidates', 'Placed candidates', COUNT(*), 2 FROM candidates WHERE placed_company_id IS NOT NULL
                              UNION ALL SELECT 'unplaced_candidates', 'Unplaced candidates', COUNT(*), 3 FROM candidates WHERE placed_company_id IS NULL
                              UNION ALL SELECT 'applications', 'Applications', COUNT(*), 4 FROM applications
                              UNION ALL SELECT 'active_applications', 'Active applications', COUNT(*), 5 FROM applications WHERE current_status NOT IN ('idle', 'placed', 'sent')
                          ) ORDER BY sort_order",
            ],
            'application_status_counts' => [
                'headers' => ['status', 'label', 'count'],
                'sql' => 'SELECT current_status AS status, current_status AS label, COUNT(*) AS count
                          FROM applications
                          GROUP BY current_status
                          ORDER BY current_status',
            ],
            'placements_by_company' => [
                'headers' => ['company_code', 'company_name', 'placed_count'],
                'sql' => 'SELECT co.code AS company_code, co.name AS company_name, COUNT(c.id) AS placed_count
                          FROM companies co
                          JOIN candidates c ON c.placed_company_id = co.id
                          GROUP BY co.id, co.code, co.name
                          ORDER BY placed_count DESC, co.code',
            ],
            'candidates_by_program' => [
                'headers' => ['program', 'candidate_count', 'placed_count'],
                'sql' => "SELECT COALESCE(NULLIF(program, ''), 'Unspecified') AS program,
                                 COUNT(*) AS candidate_count,
                                 SUM(CASE WHEN placed_company_id IS NOT NULL THEN 1 ELSE 0 END) AS placed_count
                          FROM candidates
                          GROUP BY COALESCE(NULLIF(program, ''), 'Unspecified')
                          ORDER BY program",
            ],
            'candidates_by_location' => [
                'headers' => ['current_location', 'candidate_count'],
                'sql' => 'SELECT current_location, COUNT(*) AS candidate_count
                          FROM candidates
                          GROUP BY current_location
                          ORDER BY current_location',
            ],
            'settings' => [
                'headers' => ['key', 'value'],
                'sql' => 'SELECT key, value FROM settings ORDER BY key',
            ],
            'users' => [
                'headers' => ['id', 'name', 'email', 'role', 'scope_type', 'scope_value', 'active', 'created_at'],
                'sql' => 'SELECT id, name, email, role, scope_type, scope_value, active, created_at FROM users ORDER BY id',
            ],
            'user_board_preferences' => [
                'headers' => ['user_email', 'q', 'company', 'status', 'flag', 'actionable', 'compact', 'stale_minutes', 'created_at', 'updated_at'],
                'sql' => 'SELECT u.email AS user_email, ubp.q, ubp.company, ubp.status, ubp.flag,
                                 ubp.actionable, ubp.compact, ubp.stale_minutes, ubp.created_at, ubp.updated_at
                          FROM user_board_preferences ubp
                          JOIN users u ON u.id = ubp.user_id
                          ORDER BY u.email',
            ],
            'notifications' => [
                'headers' => ['id', 'recipient_role', 'recipient_scope_type', 'recipient_scope_value', 'channel', 'template_key', 'subject', 'body', 'status', 'source_type', 'source_id', 'created_by_email', 'acknowledged_by_email', 'created_at', 'acknowledged_at'],
                'sql' => 'SELECT n.id, n.recipient_role, n.recipient_scope_type, n.recipient_scope_value,
                                 n.channel, n.template_key, n.subject, n.body, n.status,
                                 n.source_type, COALESCE(n.source_id, \'\') AS source_id,
                                 COALESCE(cu.email, \'\') AS created_by_email,
                                 COALESCE(au.email, \'\') AS acknowledged_by_email,
                                 n.created_at, COALESCE(n.acknowledged_at, \'\') AS acknowledged_at
                          FROM notifications n
                          LEFT JOIN users cu ON cu.id = n.created_by
                          LEFT JOIN users au ON au.id = n.acknowledged_by
                          ORDER BY n.id',
            ],
            'notification_deliveries' => [
                'headers' => ['id', 'notification_id', 'channel', 'status', 'attempt_count', 'last_error', 'payload_json', 'created_at', 'updated_at', 'delivered_at'],
                'sql' => 'SELECT id, notification_id, channel, status, attempt_count, last_error,
                                 payload_json, created_at, updated_at, COALESCE(delivered_at, \'\') AS delivered_at
                          FROM notification_deliveries
                          ORDER BY id',
            ],
            'candidates' => [
                'headers' => ['id', 'external_id', 'name', 'program', 'tags', 'custom_fields_json', 'current_location', 'accommodation_notes', 'opted_out', 'anonymized_at', 'placed_company_code', 'created_at', 'updated_at'],
                'sql' => 'SELECT c.id, c.external_id, c.name, c.program, c.tags, c.custom_fields_json, c.current_location, c.accommodation_notes, c.opted_out,
                                 COALESCE(c.anonymized_at, \'\') AS anonymized_at, COALESCE(co.code, \'\') AS placed_company_code, c.created_at, c.updated_at
                          FROM candidates c
                          LEFT JOIN companies co ON co.id = c.placed_company_id
                          ORDER BY c.external_id',
            ],
            'companies' => [
                'headers' => ['id', 'code', 'name', 'slot', 'offer_tier', 'process_type', 'room', 'tracker_name', 'max_active', 'deadline_day', 'deadline_at', 'process_notes', 'tags', 'custom_fields_json', 'created_at', 'updated_at'],
                'sql' => 'SELECT id, code, name, slot, offer_tier, process_type, room, tracker_name, max_active, deadline_day, deadline_at, process_notes, tags, custom_fields_json, created_at, updated_at
                          FROM companies ORDER BY code',
            ],
            'company_rounds' => [
                'headers' => ['id', 'company_code', 'sequence', 'label', 'round_type', 'room', 'duration_minutes', 'instructions', 'created_at', 'updated_at'],
                'sql' => 'SELECT cr.id, co.code AS company_code, cr.sequence, cr.label, cr.round_type, cr.room,
                                 cr.duration_minutes, cr.instructions, cr.created_at, cr.updated_at
                          FROM company_rounds cr
                          JOIN companies co ON co.id = cr.company_id
                          ORDER BY co.code, cr.sequence, cr.id',
            ],
            'round_panelists' => [
                'headers' => ['id', 'company_code', 'round_sequence', 'round_label', 'panel_sequence', 'name', 'role', 'affiliation', 'contact', 'availability_status', 'notes', 'created_at', 'updated_at'],
                'sql' => 'SELECT rp.id, co.code AS company_code, cr.sequence AS round_sequence,
                                 cr.label AS round_label, rp.sequence AS panel_sequence, rp.name,
                                 rp.role, rp.affiliation, rp.contact, rp.availability_status, rp.notes, rp.created_at, rp.updated_at
                          FROM round_panelists rp
                          JOIN company_rounds cr ON cr.id = rp.round_id
                          JOIN companies co ON co.id = cr.company_id
                          ORDER BY co.code, cr.sequence, rp.sequence, rp.id',
            ],
            'round_schedules' => [
                'headers' => ['id', 'company_code', 'round_sequence', 'round_label', 'schedule_sequence', 'room', 'schedule_day', 'starts_at', 'ends_at', 'capacity', 'schedule_status', 'notes', 'created_at', 'updated_at'],
                'sql' => 'SELECT rs.id, co.code AS company_code, cr.sequence AS round_sequence,
                                 cr.label AS round_label, rs.sequence AS schedule_sequence, rs.room,
                                 rs.schedule_day, rs.starts_at, rs.ends_at, rs.capacity, rs.schedule_status, rs.notes, rs.created_at, rs.updated_at
                          FROM round_schedules rs
                          JOIN company_rounds cr ON cr.id = rs.round_id
                          JOIN companies co ON co.id = cr.company_id
                          ORDER BY co.code, cr.sequence, rs.schedule_day, rs.sequence, rs.starts_at, rs.id',
            ],
            'application_slot_assignments' => [
                'headers' => ['id', 'candidate_external_id', 'company_code', 'round_sequence', 'round_label', 'schedule_sequence', 'room', 'schedule_day', 'starts_at', 'ends_at', 'assignment_sequence', 'assignment_status', 'notes', 'created_at', 'updated_at'],
                'sql' => 'SELECT asa.id, c.external_id AS candidate_external_id, co.code AS company_code,
                                 cr.sequence AS round_sequence, cr.label AS round_label,
                                 rs.sequence AS schedule_sequence, rs.room, rs.schedule_day, rs.starts_at, rs.ends_at,
                                 asa.sequence AS assignment_sequence, asa.assignment_status, asa.notes,
                                 asa.created_at, asa.updated_at
                          FROM application_slot_assignments asa
                          JOIN applications a ON a.id = asa.application_id
                          JOIN candidates c ON c.id = a.candidate_id
                          JOIN companies co ON co.id = a.company_id
                          JOIN round_schedules rs ON rs.id = asa.round_schedule_id
                          JOIN company_rounds cr ON cr.id = rs.round_id
                          ORDER BY co.code, c.external_id, asa.sequence, cr.sequence, rs.schedule_day, rs.sequence, rs.starts_at, asa.id',
            ],
            'candidate_unavailability_windows' => [
                'headers' => ['id', 'candidate_external_id', 'label', 'schedule_day', 'starts_at', 'ends_at', 'notes', 'created_at', 'updated_at'],
                'sql' => 'SELECT cuw.id, c.external_id AS candidate_external_id, cuw.label, cuw.schedule_day,
                                 cuw.starts_at, cuw.ends_at, cuw.notes, cuw.created_at, cuw.updated_at
                          FROM candidate_unavailability_windows cuw
                          JOIN candidates c ON c.id = cuw.candidate_id
                          ORDER BY c.external_id, cuw.schedule_day, cuw.starts_at, cuw.ends_at, cuw.id',
            ],
            'applications' => [
                'headers' => ['id', 'candidate_external_id', 'company_code', 'current_status', 'previous_company_code', 'next_company_code', 'waitlist_rank', 'created_at', 'updated_at'],
                'sql' => 'SELECT a.id, c.external_id AS candidate_external_id, co.code AS company_code, a.current_status,
                                 COALESCE(pc.code, \'\') AS previous_company_code, COALESCE(nc.code, \'\') AS next_company_code,
                                 COALESCE(a.waitlist_rank, \'\') AS waitlist_rank, a.created_at, a.updated_at
                          FROM applications a
                          JOIN candidates c ON c.id = a.candidate_id
                          JOIN companies co ON co.id = a.company_id
                          LEFT JOIN companies pc ON pc.id = a.previous_company_id
                          LEFT JOIN companies nc ON nc.id = a.next_company_id
                          ORDER BY co.code, c.external_id',
            ],
            'events' => [
                'headers' => ['id', 'application_id', 'candidate_external_id', 'company_code', 'from_status', 'to_status', 'actor_email', 'actor_role', 'note', 'created_at'],
                'sql' => 'SELECT e.id, e.application_id, c.external_id AS candidate_external_id, co.code AS company_code,
                                 e.from_status, e.to_status, COALESCE(u.email, \'\') AS actor_email,
                                 e.actor_role, e.note, e.created_at
                          FROM events e
                          JOIN candidates c ON c.id = e.candidate_id
                          JOIN companies co ON co.id = e.company_id
                          LEFT JOIN users u ON u.id = e.actor_user_id
                          ORDER BY e.id',
            ],
            'preference_requests' => [
                'headers' => ['id', 'candidate_external_id', 'status', 'note', 'requested_by_email', 'decision_company_code', 'created_at', 'resolved_at'],
                'sql' => 'SELECT pr.id, c.external_id AS candidate_external_id, pr.status, pr.note,
                                 COALESCE(u.email, \'\') AS requested_by_email,
                                 COALESCE(co.code, \'\') AS decision_company_code,
                                 pr.created_at, COALESCE(pr.resolved_at, \'\') AS resolved_at
                          FROM preference_requests pr
                          JOIN candidates c ON c.id = pr.candidate_id
                          LEFT JOIN users u ON u.id = pr.requested_by
                          LEFT JOIN companies co ON co.id = pr.decision_company_id
                          ORDER BY pr.id',
            ],
            'preference_options' => [
                'headers' => ['id', 'request_id', 'company_code'],
                'sql' => 'SELECT po.id, po.request_id, co.code AS company_code
                          FROM preference_options po
                          JOIN companies co ON co.id = po.company_id
                          ORDER BY po.request_id, co.code',
            ],
            'wanted_alerts' => [
                'headers' => ['id', 'candidate_external_id', 'reason', 'status', 'created_by_email', 'resolved_by_email', 'created_at', 'resolved_at'],
                'sql' => 'SELECT wa.id, c.external_id AS candidate_external_id, wa.reason, wa.status,
                                 COALESCE(cu.email, \'\') AS created_by_email,
                                 COALESCE(ru.email, \'\') AS resolved_by_email,
                                 wa.created_at, COALESCE(wa.resolved_at, \'\') AS resolved_at
                          FROM wanted_alerts wa
                          JOIN candidates c ON c.id = wa.candidate_id
                          LEFT JOIN users cu ON cu.id = wa.created_by
                          LEFT JOIN users ru ON ru.id = wa.resolved_by
                          ORDER BY wa.id',
            ],
            'audit_logs' => [
                'headers' => ['id', 'actor_email', 'action', 'subject_type', 'subject_id', 'detail', 'ip_address', 'user_agent', 'created_at'],
                'sql' => 'SELECT a.id, COALESCE(u.email, \'\') AS actor_email, a.action, a.subject_type,
                                 COALESCE(a.subject_id, \'\') AS subject_id, a.detail,
                                 a.ip_address, a.user_agent, a.created_at
                          FROM audit_logs a
                          LEFT JOIN users u ON u.id = a.actor_user_id
                          ORDER BY a.id',
            ],
            'workflow_status_overrides' => [
                'headers' => ['status_key', 'label', 'color'],
                'sql' => 'SELECT status_key, label, color FROM workflow_status_overrides ORDER BY status_key',
            ],
            'workflow_transition_overrides' => [
                'headers' => ['from_status', 'to_status', 'roles_csv'],
                'sql' => 'SELECT from_status, to_status, roles_csv FROM workflow_transition_overrides ORDER BY from_status, to_status',
            ],
        ];
    }
}
