<?php

declare(strict_types=1);

namespace App\Import;

use App\Core\Http\UserVisibleException;
use App\Modules\Placement\Install\LegacyDomainSynchronizer;
use App\Modules\Placement\Workflow\WorkflowRepository;
use PDO;
use RuntimeException;

final class CsvImporter
{
    private const HEADER_ALIASES = [
        'external_id' => ['candidate_id', 'student_id', 'roll_no', 'roll_number', 'registration_no', 'registration_number', 'admission_no', 'student_code'],
        'candidate_external_id' => ['external_id', 'candidate_id', 'student_id', 'roll_no', 'roll_number', 'registration_no', 'registration_number', 'admission_no', 'student_code'],
        'name' => ['candidate_name', 'student_name', 'full_name', 'company_name', 'recruiter_name', 'employer_name', 'panelist_name'],
        'program' => ['programme', 'branch', 'department', 'course', 'degree', 'specialization', 'specialisation'],
        'tags' => ['tag', 'labels', 'label_tags', 'cohort', 'category', 'categories', 'segment', 'segments'],
        'custom_fields_json' => ['custom_fields', 'fields_json', 'local_fields', 'metadata_json', 'extra_fields'],
        'current_location' => ['location', 'current_room', 'room_location'],
        'accommodation_notes' => ['accommodation', 'accessibility_notes', 'special_needs', 'candidate_notes'],
        'opted_out' => ['opt_out', 'withdrawn', 'not_participating'],
        'code' => ['company_code', 'recruiter_code', 'employer_code', 'organization_code', 'organisation_code'],
        'company_code' => ['code', 'recruiter_code', 'employer_code', 'organization_code', 'organisation_code'],
        'company_name' => ['name', 'recruiter_name', 'employer_name', 'organization_name', 'organisation_name'],
        'slot' => ['day_slot', 'placement_slot', 'interview_slot'],
        'offer_tier' => ['tier', 'offer_category'],
        'process_type' => ['process', 'selection_process'],
        'room' => ['venue', 'room_no', 'room_number', 'panel_room'],
        'tracker_name' => ['tracker', 'coordinator', 'company_tracker'],
        'max_active' => ['active_cap', 'parallel_capacity'],
        'deadline_day' => ['last_day', 'finish_day'],
        'deadline_at' => ['deadline_time', 'last_time', 'finish_by'],
        'process_notes' => ['company_notes', 'recruiter_notes'],
        'sequence' => ['order', 'sort_order'],
        'label' => ['round_label', 'round_name', 'stage_label', 'stage'],
        'round_label' => ['label', 'round_name', 'stage_label', 'stage'],
        'round_type' => ['type', 'stage_type'],
        'round_sequence' => ['round_no', 'round_number', 'round_order'],
        'duration_minutes' => ['duration', 'minutes'],
        'schedule_day' => ['day', 'date_label', 'schedule_date'],
        'starts_at' => ['start_time', 'start_at', 'start'],
        'ends_at' => ['end_time', 'end_at', 'end'],
        'capacity' => ['seats', 'slots', 'room_capacity'],
        'availability_status' => ['availability', 'panelist_status'],
        'affiliation' => ['organization', 'organisation', 'company'],
        'contact' => ['phone', 'mobile', 'email'],
        'assignment_sequence' => ['assignment_order'],
        'waitlist_rank' => ['rank', 'waitlist', 'list_rank'],
    ];

    private const KNOWN_IMPORT_FIELDS = [
        'external_id',
        'candidate_external_id',
        'name',
        'program',
        'tags',
        'custom_fields_json',
        'current_location',
        'accommodation_notes',
        'opted_out',
        'code',
        'company_code',
        'company_name',
        'slot',
        'offer_tier',
        'process_type',
        'room',
        'tracker_name',
        'max_active',
        'deadline_day',
        'deadline_at',
        'process_notes',
        'sequence',
        'label',
        'round_label',
        'round_type',
        'round_sequence',
        'duration_minutes',
        'instructions',
        'schedule_day',
        'starts_at',
        'ends_at',
        'capacity',
        'schedule_status',
        'notes',
        'availability_status',
        'role',
        'affiliation',
        'contact',
        'assignment_sequence',
        'assignment_status',
        'waitlist_rank',
        'status',
        'gd_round',
        'gd_panel',
    ];

    private ?array $headerAliasCandidates = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function normalizeHeaderAliasJson(string $json): string
    {
        $json = trim($json);
        if ($json === '') {
            return '';
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new UserVisibleException('IMPORT_HEADER_ALIASES_INVALID', 'Import header aliases must be a JSON object of canonical_field to alias list.');
        }
        $known = array_fill_keys(self::KNOWN_IMPORT_FIELDS, true);
        $normalized = [];
        foreach ($payload as $canonical => $aliases) {
            $canonicalKey = $this->normalizeHeaderKey((string) $canonical);
            if ($canonicalKey === '' || !isset($known[$canonicalKey])) {
                throw new UserVisibleException('IMPORT_HEADER_FIELD_UNKNOWN', 'Import header aliases reference an unknown field.');
            }
            if (is_string($aliases)) {
                $aliases = array_map('trim', explode(',', $aliases));
            }
            if (!is_array($aliases)) {
                throw new UserVisibleException('IMPORT_HEADER_ALIASES_INVALID', 'Import header aliases must be a string or list for each field.');
            }
            $seen = [];
            foreach ($aliases as $alias) {
                $alias = trim((string) $alias);
                $aliasKey = $this->normalizeHeaderKey($alias);
                if ($alias === '' || $aliasKey === '') {
                    continue;
                }
                $seen[$alias] = true;
            }
            if ($seen !== []) {
                $normalized[$canonicalKey] = array_keys($seen);
            }
        }
        ksort($normalized);
        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Could not normalize import header aliases.');
        }
        return $encoded === '[]' ? '' : $encoded;
    }

    public function preview(string $type, string $csv, array $validStatuses = []): array
    {
        return match ($type) {
            'candidates' => $this->previewCandidates($csv),
            'companies' => $this->previewCompanies($csv),
            'rounds' => $this->previewCompanyRounds($csv),
            'schedules' => $this->previewRoundSchedules($csv),
            'panelists' => $this->previewRoundPanelists($csv),
            'assignments' => $this->previewSlotAssignments($csv),
            'unavailability' => $this->previewCandidateUnavailability($csv),
            'shortlists' => $this->previewShortlists($csv, $validStatuses),
            'legacy' => $this->previewLegacyWide($csv, $validStatuses),
            default => throw new UserVisibleException('IMPORT_TYPE_INVALID', 'Unknown import type.'),
        };
    }

    public function candidates(string $csv): int
    {
        $rows = $this->rows($csv, ['external_id', 'name']);
        $normalized = [];
        foreach ($rows as $row) {
            if (($row['external_id'] ?? '') === '' || ($row['name'] ?? '') === '') {
                throw new RuntimeException('Candidate ID and name are required.');
            }
            $normalized[] = [
                'external_id' => $row['external_id'],
                'name' => $row['name'],
                'program' => $row['program'] ?? '',
                'tags' => $row['tags'] ?? '',
                'current_location' => $row['current_location'] ?? 'CP',
                'accommodation_notes' => $row['accommodation_notes'] ?? '',
                'custom_fields_json' => $this->normalizeCustomFieldsJson($row['custom_fields_json'] ?? '{}', 'Candidate custom fields', (int) ($row['__row'] ?? 0)),
                'opted_out' => $this->boolInt($row['opted_out'] ?? '0'),
            ];
        }
        $count = 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO candidates (external_id, name, program, tags, current_location, accommodation_notes, custom_fields_json, opted_out, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(external_id) DO UPDATE SET name = excluded.name, program = excluded.program, tags = excluded.tags, current_location = excluded.current_location, accommodation_notes = excluded.accommodation_notes, custom_fields_json = excluded.custom_fields_json, opted_out = excluded.opted_out, updated_at = excluded.updated_at'
        );
        foreach ($normalized as $row) {
            $now = cpe_now();
            $stmt->execute([
                $row['external_id'],
                $row['name'],
                $row['program'],
                $row['tags'],
                $row['current_location'],
                $row['accommodation_notes'],
                $row['custom_fields_json'],
                $row['opted_out'],
                $now,
                $now,
            ]);
            $count++;
        }
        $this->synchronizeDurableDomain();
        return $count;
    }

    public function companies(string $csv): int
    {
        $rows = $this->rows($csv, ['code', 'name']);
        $normalized = [];
        foreach ($rows as $row) {
            if (($row['code'] ?? '') === '' || ($row['name'] ?? '') === '') {
                throw new RuntimeException('Company code and name are required.');
            }
            $normalized[] = [
                'code' => strtoupper($row['code']),
                'name' => $row['name'],
                'slot' => $row['slot'] ?? '',
                'offer_tier' => $row['offer_tier'] ?? '',
                'process_type' => $row['process_type'] ?? '',
                'room' => $row['room'] ?? '',
                'tracker_name' => $row['tracker_name'] ?? '',
                'max_active' => $this->nonNegativeInt($row['max_active'] ?? '0', 'max_active', (int) ($row['__row'] ?? 0)),
                'deadline_day' => $row['deadline_day'] ?? '',
                'deadline_at' => $row['deadline_at'] ?? '',
                'process_notes' => $row['process_notes'] ?? '',
                'tags' => $row['tags'] ?? '',
                'custom_fields_json' => $this->normalizeCustomFieldsJson($row['custom_fields_json'] ?? '{}', 'Company custom fields', (int) ($row['__row'] ?? 0)),
            ];
        }
        $count = 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO companies (code, name, slot, offer_tier, process_type, room, tracker_name, max_active, deadline_day, deadline_at, process_notes, tags, custom_fields_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(code) DO UPDATE SET
                name = excluded.name,
                slot = excluded.slot,
                offer_tier = excluded.offer_tier,
                process_type = excluded.process_type,
                room = excluded.room,
                tracker_name = excluded.tracker_name,
                max_active = excluded.max_active,
                deadline_day = excluded.deadline_day,
                deadline_at = excluded.deadline_at,
                process_notes = excluded.process_notes,
                tags = excluded.tags,
                custom_fields_json = excluded.custom_fields_json,
                updated_at = excluded.updated_at'
        );
        foreach ($normalized as $row) {
            $now = cpe_now();
            $stmt->execute([
                $row['code'],
                $row['name'],
                $row['slot'],
                $row['offer_tier'],
                $row['process_type'],
                $row['room'],
                $row['tracker_name'],
                $row['max_active'],
                $row['deadline_day'],
                $row['deadline_at'],
                $row['process_notes'],
                $row['tags'],
                $row['custom_fields_json'],
                $now,
                $now,
            ]);
            $count++;
        }
        $this->synchronizeDurableDomain();
        return $count;
    }

    public function companyRounds(string $csv): int
    {
        $rows = $this->rows($csv, ['company_code', 'label']);
        $normalized = [];
        foreach ($rows as $row) {
            $companyId = $this->idFor('companies', 'code', strtoupper($row['company_code']));
            $normalized[] = [
                'company_id' => $companyId,
                'sequence' => $this->positiveInt($row['sequence'] ?? '1', 'sequence', (int) ($row['__row'] ?? 0)),
                'label' => $row['label'],
                'round_type' => $row['round_type'] ?? '',
                'room' => $row['room'] ?? '',
                'duration_minutes' => $this->nonNegativeInt($row['duration_minutes'] ?? '0', 'duration_minutes', (int) ($row['__row'] ?? 0)),
                'instructions' => $row['instructions'] ?? '',
            ];
        }
        $count = 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO company_rounds (company_id, sequence, label, round_type, room, duration_minutes, instructions, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(company_id, sequence, label) DO UPDATE SET
                round_type = excluded.round_type,
                room = excluded.room,
                duration_minutes = excluded.duration_minutes,
                instructions = excluded.instructions,
                updated_at = excluded.updated_at'
        );
        foreach ($normalized as $row) {
            $now = cpe_now();
            $stmt->execute([
                $row['company_id'],
                $row['sequence'],
                $row['label'],
                $row['round_type'],
                $row['room'],
                $row['duration_minutes'],
                $row['instructions'],
                $now,
                $now,
            ]);
            $count++;
        }
        return $count;
    }

    public function roundSchedules(string $csv): int
    {
        $rows = $this->rows($csv, ['company_code', 'round_sequence', 'round_label', 'room']);
        $normalized = [];
        foreach ($rows as $row) {
            $companyCode = strtoupper($row['company_code'] ?? '');
            $roundSequence = $this->positiveInt($row['round_sequence'] ?? '', 'round_sequence', (int) ($row['__row'] ?? 0));
            $roundLabel = $row['round_label'] ?? '';
            $room = $row['room'] ?? '';
            if ($companyCode === '' || $roundLabel === '' || $room === '') {
                throw new RuntimeException("Row {$row['__row']}: company_code, round_label, and room are required.");
            }
            $normalized[] = [
                'round_id' => $this->roundIdFor($companyCode, $roundSequence, $roundLabel),
                'sequence' => $this->positiveInt($row['sequence'] ?? '1', 'sequence', (int) ($row['__row'] ?? 0)),
                'room' => $room,
                'schedule_day' => $row['schedule_day'] ?? '',
                'starts_at' => $row['starts_at'] ?? '',
                'ends_at' => $row['ends_at'] ?? '',
                'capacity' => $this->nonNegativeInt($row['capacity'] ?? '0', 'capacity', (int) ($row['__row'] ?? 0)),
                'schedule_status' => $this->normalizeScheduleStatus($row['schedule_status'] ?? 'active'),
                'notes' => $row['notes'] ?? '',
            ];
        }
        $count = 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO round_schedules (round_id, sequence, room, schedule_day, starts_at, ends_at, capacity, schedule_status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(round_id, sequence, room, starts_at) DO UPDATE SET
                schedule_day = excluded.schedule_day,
                ends_at = excluded.ends_at,
                capacity = excluded.capacity,
                schedule_status = excluded.schedule_status,
                notes = excluded.notes,
                updated_at = excluded.updated_at'
        );
        foreach ($normalized as $row) {
            $now = cpe_now();
            $stmt->execute([
                $row['round_id'],
                $row['sequence'],
                $row['room'],
                $row['schedule_day'],
                $row['starts_at'],
                $row['ends_at'],
                $row['capacity'],
                $row['schedule_status'],
                $row['notes'],
                $now,
                $now,
            ]);
            $count++;
        }
        return $count;
    }

    public function roundPanelists(string $csv): int
    {
        $rows = $this->rows($csv, ['company_code', 'round_sequence', 'round_label', 'name']);
        $normalized = [];
        foreach ($rows as $row) {
            $companyCode = strtoupper($row['company_code'] ?? '');
            $roundSequence = $this->positiveInt($row['round_sequence'] ?? '', 'round_sequence', (int) ($row['__row'] ?? 0));
            $roundLabel = $row['round_label'] ?? '';
            $name = $row['name'] ?? '';
            if ($companyCode === '' || $roundLabel === '' || $name === '') {
                throw new RuntimeException("Row {$row['__row']}: company_code, round_label, and name are required.");
            }
            $normalized[] = [
                'round_id' => $this->roundIdFor($companyCode, $roundSequence, $roundLabel),
                'sequence' => $this->positiveInt($row['sequence'] ?? '1', 'sequence', (int) ($row['__row'] ?? 0)),
                'name' => $name,
                'role' => $row['role'] ?? '',
                'affiliation' => $row['affiliation'] ?? '',
                'contact' => $row['contact'] ?? '',
                'availability_status' => $this->normalizePanelistAvailabilityStatus($row['availability_status'] ?? 'active'),
                'notes' => $row['notes'] ?? '',
            ];
        }
        $count = 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO round_panelists (round_id, sequence, name, role, affiliation, contact, availability_status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(round_id, sequence, name) DO UPDATE SET
                role = excluded.role,
                affiliation = excluded.affiliation,
                contact = excluded.contact,
                availability_status = excluded.availability_status,
                notes = excluded.notes,
                updated_at = excluded.updated_at'
        );
        foreach ($normalized as $row) {
            $now = cpe_now();
            $stmt->execute([
                $row['round_id'],
                $row['sequence'],
                $row['name'],
                $row['role'],
                $row['affiliation'],
                $row['contact'],
                $row['availability_status'],
                $row['notes'],
                $now,
                $now,
            ]);
            $count++;
        }
        return $count;
    }

    public function slotAssignments(string $csv): int
    {
        $rows = $this->rows($csv, ['candidate_external_id', 'company_code', 'round_sequence', 'round_label', 'room']);
        $normalized = [];
        foreach ($rows as $row) {
            $candidateExternalId = $row['candidate_external_id'] ?? '';
            $companyCode = strtoupper($row['company_code'] ?? '');
            $roundSequence = $this->positiveInt($row['round_sequence'] ?? '', 'round_sequence', (int) ($row['__row'] ?? 0));
            $roundLabel = $row['round_label'] ?? '';
            $scheduleSequence = $this->positiveInt($row['schedule_sequence'] ?? '1', 'schedule_sequence', (int) ($row['__row'] ?? 0));
            $room = $row['room'] ?? '';
            $scheduleDay = $row['schedule_day'] ?? '';
            $startsAt = $row['starts_at'] ?? '';
            if ($candidateExternalId === '' || $companyCode === '' || $roundLabel === '' || $room === '') {
                throw new RuntimeException("Row {$row['__row']}: candidate_external_id, company_code, round_label, and room are required.");
            }
            $applicationId = $this->applicationIdFor($candidateExternalId, $companyCode);
            $scheduleId = $this->scheduleIdFor($companyCode, $roundSequence, $roundLabel, $scheduleSequence, $room, $startsAt, $scheduleDay);
            $normalized[] = [
                'application_id' => $applicationId,
                'round_schedule_id' => $scheduleId,
                'sequence' => $this->positiveInt($row['assignment_sequence'] ?? '1', 'assignment_sequence', (int) ($row['__row'] ?? 0)),
                'assignment_status' => $row['assignment_status'] ?? 'assigned',
                'notes' => $row['notes'] ?? '',
            ];
        }
        $count = 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO application_slot_assignments (application_id, round_schedule_id, sequence, assignment_status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(application_id, round_schedule_id) DO UPDATE SET
                sequence = excluded.sequence,
                assignment_status = excluded.assignment_status,
                notes = excluded.notes,
                updated_at = excluded.updated_at'
        );
        foreach ($normalized as $row) {
            $now = cpe_now();
            $stmt->execute([
                $row['application_id'],
                $row['round_schedule_id'],
                $row['sequence'],
                $row['assignment_status'],
                $row['notes'],
                $now,
                $now,
            ]);
            $count++;
        }
        return $count;
    }

    public function candidateUnavailability(string $csv): int
    {
        $rows = $this->rows($csv, ['candidate_external_id', 'starts_at', 'ends_at']);
        $normalized = [];
        foreach ($rows as $row) {
            $candidateExternalId = $row['candidate_external_id'] ?? '';
            $startsAt = $row['starts_at'] ?? '';
            $endsAt = $row['ends_at'] ?? '';
            if ($candidateExternalId === '' || $startsAt === '' || $endsAt === '') {
                throw new RuntimeException("Row {$row['__row']}: candidate_external_id, starts_at, and ends_at are required.");
            }
            $this->assertTime($startsAt, 'starts_at', (int) ($row['__row'] ?? 0));
            $this->assertTime($endsAt, 'ends_at', (int) ($row['__row'] ?? 0));
            $this->assertTimeRange($startsAt, $endsAt, (int) ($row['__row'] ?? 0));
            $normalized[] = [
                'candidate_id' => $this->idFor('candidates', 'external_id', $candidateExternalId),
                'label' => $row['label'] ?? 'Unavailable',
                'schedule_day' => $row['schedule_day'] ?? '',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'notes' => $row['notes'] ?? '',
            ];
        }
        $count = 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO candidate_unavailability_windows (candidate_id, label, schedule_day, starts_at, ends_at, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(candidate_id, schedule_day, starts_at, ends_at, label) DO UPDATE SET
                notes = excluded.notes,
                updated_at = excluded.updated_at'
        );
        foreach ($normalized as $row) {
            $now = cpe_now();
            $stmt->execute([
                $row['candidate_id'],
                $row['label'],
                $row['schedule_day'],
                $row['starts_at'],
                $row['ends_at'],
                $row['notes'],
                $now,
                $now,
            ]);
            $count++;
        }
        return $count;
    }

    public function shortlists(string $csv, array $validStatuses = []): int
    {
        $rows = $this->rows($csv, ['candidate_external_id', 'company_code']);
        $validStatuses = $this->validStatuses($validStatuses);
        $normalized = [];
        foreach ($rows as $row) {
            $status = $row['status'] ?? 'scheduled';
            if (!in_array($status, $validStatuses, true)) {
                throw new RuntimeException("Row {$row['__row']} has unknown workflow status: {$status}");
            }
            $normalized[] = [
                'candidate_id' => $this->idFor('candidates', 'external_id', $row['candidate_external_id']),
                'company_id' => $this->idFor('companies', 'code', strtoupper($row['company_code'])),
                'status' => $status,
                'waitlist_rank' => isset($row['waitlist_rank']) && $row['waitlist_rank'] !== ''
                    ? $this->positiveInt($row['waitlist_rank'], 'waitlist_rank', (int) ($row['__row'] ?? 0))
                    : null,
            ];
        }
        $count = 0;
        foreach ($normalized as $row) {
            $now = cpe_now();
            $stmt = $this->pdo->prepare(
                'INSERT INTO applications (candidate_id, company_id, current_status, waitlist_rank, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT(candidate_id, company_id) DO UPDATE SET current_status = excluded.current_status, waitlist_rank = excluded.waitlist_rank, updated_at = excluded.updated_at'
            );
            $stmt->execute([$row['candidate_id'], $row['company_id'], $row['status'], $row['waitlist_rank'], $now, $now]);
            $count++;
        }
        $this->synchronizeDurableDomain(true);
        return $count;
    }

    public function legacyWide(string $csv, array $validStatuses = []): int
    {
        $rows = $this->rows($csv, ['external_id', 'name', 'company_code']);
        $validStatuses = $this->validStatuses($validStatuses);
        $count = 0;
        foreach ($rows as $row) {
            $candidateCsv = "external_id,name,program,current_location\n" .
                $row['external_id'] . ',' . $row['name'] . ',' . ($row['program'] ?? '') . ',' . ($row['current_location'] ?? 'CP') . "\n";
            $companyName = $row['company_name'] ?? $row['company_code'];
            $companyCsv = "code,name,slot,process_type,room\n" . strtoupper($row['company_code']) . ',' . $companyName . ',' . ($row['slot'] ?? '') . ',' . ($row['process_type'] ?? '') . ',' . ($row['room'] ?? '') . "\n";
            $status = $this->legacyStatus($row);
            if (!in_array($status, $validStatuses, true)) {
                throw new RuntimeException("Row {$row['__row']} maps to unavailable workflow status: {$status}");
            }
            $shortlistCsv = "candidate_external_id,company_code,status\n" . $row['external_id'] . ',' . strtoupper($row['company_code']) . ',' . $status . "\n";
            $this->candidates($candidateCsv);
            $this->companies($companyCsv);
            $this->shortlists($shortlistCsv, $validStatuses);
            $this->legacyGdPanelAssignment($row);
            $count++;
        }
        return $count;
    }

    private function previewCandidates(string $csv): array
    {
        $rows = $this->rows($csv, ['external_id', 'name']);
        $report = $this->newReport('candidates', count($rows));
        $seen = [];
        foreach ($rows as $row) {
            $rowNo = (int) $row['__row'];
            $externalId = $row['external_id'] ?? '';
            if ($externalId === '' || ($row['name'] ?? '') === '') {
                $this->addError($report, $rowNo, 'Candidate ID and name are required.');
                continue;
            }
            $this->validatePreviewCustomFields($report, $row, 'Candidate custom fields');
            $this->noteDuplicate($report, $seen, $externalId, $rowNo, 'candidate');
            if ($this->existsBy('candidates', 'external_id', $externalId)) {
                $report['updates']++;
                $this->addSample($report, "Update candidate {$externalId}");
            } else {
                $report['creates']++;
                $this->addSample($report, "Create candidate {$externalId}");
            }
        }
        return $this->finishReport($report);
    }

    private function previewCompanies(string $csv): array
    {
        $rows = $this->rows($csv, ['code', 'name']);
        $report = $this->newReport('companies', count($rows));
        $seen = [];
        foreach ($rows as $row) {
            $rowNo = (int) $row['__row'];
            $code = strtoupper($row['code'] ?? '');
            if ($code === '' || ($row['name'] ?? '') === '') {
                $this->addError($report, $rowNo, 'Company code and name are required.');
                continue;
            }
            $this->validatePreviewInt($report, $row, 'max_active', false);
            $this->validatePreviewCustomFields($report, $row, 'Company custom fields');
            $this->noteDuplicate($report, $seen, $code, $rowNo, 'company');
            if ($this->existsBy('companies', 'code', $code)) {
                $report['updates']++;
                $this->addSample($report, "Update company {$code}");
            } else {
                $report['creates']++;
                $this->addSample($report, "Create company {$code}");
            }
        }
        return $this->finishReport($report);
    }

    private function previewCompanyRounds(string $csv): array
    {
        $rows = $this->rows($csv, ['company_code', 'label']);
        $report = $this->newReport('rounds', count($rows));
        $seen = [];
        foreach ($rows as $row) {
            $rowNo = (int) $row['__row'];
            $code = strtoupper($row['company_code'] ?? '');
            $label = $row['label'] ?? '';
            if ($code === '' || $label === '') {
                $this->addError($report, $rowNo, 'Company code and round label are required.');
                continue;
            }
            $companyId = $this->idForOrNull('companies', 'code', $code);
            if ($companyId === null) {
                $this->addError($report, $rowNo, "Missing company: {$code}");
                continue;
            }
            $sequence = $this->previewIntValue($report, $row, 'sequence', true, 1);
            $this->validatePreviewInt($report, $row, 'duration_minutes', false);
            $key = $companyId . ':' . $sequence . ':' . $label;
            $this->noteDuplicate($report, $seen, $key, $rowNo, 'company round');
            if ($this->roundExists($companyId, $sequence, $label)) {
                $report['updates']++;
                $this->addSample($report, "Update {$code} round {$sequence}. {$label}");
            } else {
                $report['creates']++;
                $this->addSample($report, "Create {$code} round {$sequence}. {$label}");
            }
        }
        return $this->finishReport($report);
    }

    private function previewRoundSchedules(string $csv): array
    {
        $rows = $this->rows($csv, ['company_code', 'round_sequence', 'round_label', 'room']);
        $report = $this->newReport('schedules', count($rows));
        $seen = [];
        foreach ($rows as $row) {
            $rowNo = (int) $row['__row'];
            $code = strtoupper($row['company_code'] ?? '');
            $roundSequenceValue = $row['round_sequence'] ?? '';
            $roundLabel = $row['round_label'] ?? '';
            $room = $row['room'] ?? '';
            if ($code === '' || $roundSequenceValue === '' || $roundLabel === '' || $room === '') {
                $this->addError($report, $rowNo, 'Company code, round sequence, round label, and room are required.');
                continue;
            }
            $roundSequence = $this->previewIntValue($report, $row, 'round_sequence', true, 1);
            $scheduleSequence = $this->previewIntValue($report, $row, 'sequence', true, 1);
            $this->validatePreviewInt($report, $row, 'capacity', false);
            $this->validatePreviewScheduleStatus($report, $row);
            $roundId = $this->roundIdForOrNull($code, $roundSequence, $roundLabel);
            if ($roundId === null) {
                $this->addError($report, $rowNo, "Missing round: {$code} {$roundSequence}. {$roundLabel}");
                continue;
            }
            $startsAt = $row['starts_at'] ?? '';
            $key = $roundId . ':' . $scheduleSequence . ':' . $room . ':' . $startsAt;
            $this->noteDuplicate($report, $seen, $key, $rowNo, 'round schedule');
            if ($this->scheduleExists($roundId, $scheduleSequence, $room, $startsAt)) {
                $report['updates']++;
                $this->addSample($report, "Update {$code} {$roundSequence}. {$roundLabel}: {$room}");
            } else {
                $report['creates']++;
                $this->addSample($report, "Create {$code} {$roundSequence}. {$roundLabel}: {$room}");
            }
        }
        return $this->finishReport($report);
    }

    private function previewRoundPanelists(string $csv): array
    {
        $rows = $this->rows($csv, ['company_code', 'round_sequence', 'round_label', 'name']);
        $report = $this->newReport('panelists', count($rows));
        $seen = [];
        foreach ($rows as $row) {
            $rowNo = (int) $row['__row'];
            $code = strtoupper($row['company_code'] ?? '');
            $roundSequenceValue = $row['round_sequence'] ?? '';
            $roundLabel = $row['round_label'] ?? '';
            $name = $row['name'] ?? '';
            if ($code === '' || $roundSequenceValue === '' || $roundLabel === '' || $name === '') {
                $this->addError($report, $rowNo, 'Company code, round sequence, round label, and panelist name are required.');
                continue;
            }
            $roundSequence = $this->previewIntValue($report, $row, 'round_sequence', true, 1);
            $panelSequence = $this->previewIntValue($report, $row, 'sequence', true, 1);
            $this->validatePreviewPanelistAvailabilityStatus($report, $row);
            $roundId = $this->roundIdForOrNull($code, $roundSequence, $roundLabel);
            if ($roundId === null) {
                $this->addError($report, $rowNo, "Missing round: {$code} {$roundSequence}. {$roundLabel}");
                continue;
            }
            $key = $roundId . ':' . $panelSequence . ':' . $name;
            $this->noteDuplicate($report, $seen, $key, $rowNo, 'round panelist');
            if ($this->panelistExists($roundId, $panelSequence, $name)) {
                $report['updates']++;
                $this->addSample($report, "Update {$code} {$roundSequence}. {$roundLabel}: {$name}");
            } else {
                $report['creates']++;
                $this->addSample($report, "Create {$code} {$roundSequence}. {$roundLabel}: {$name}");
            }
        }
        return $this->finishReport($report);
    }

    private function previewSlotAssignments(string $csv): array
    {
        $rows = $this->rows($csv, ['candidate_external_id', 'company_code', 'round_sequence', 'round_label', 'room']);
        $report = $this->newReport('assignments', count($rows));
        $seen = [];
        foreach ($rows as $row) {
            $rowNo = (int) $row['__row'];
            $candidateExternalId = $row['candidate_external_id'] ?? '';
            $companyCode = strtoupper($row['company_code'] ?? '');
            $roundSequenceValue = $row['round_sequence'] ?? '';
            $roundLabel = $row['round_label'] ?? '';
            $room = $row['room'] ?? '';
            if ($candidateExternalId === '' || $companyCode === '' || $roundSequenceValue === '' || $roundLabel === '' || $room === '') {
                $this->addError($report, $rowNo, 'Candidate external ID, company code, round sequence, round label, and room are required.');
                continue;
            }
            $roundSequence = $this->previewIntValue($report, $row, 'round_sequence', true, 1);
            $scheduleSequence = $this->previewIntValue($report, $row, 'schedule_sequence', true, 1);
            $assignmentSequence = $this->previewIntValue($report, $row, 'assignment_sequence', true, 1);
            $applicationId = $this->applicationIdForOrNull($candidateExternalId, $companyCode);
            if ($applicationId === null) {
                $this->addError($report, $rowNo, "Missing application: {$candidateExternalId} / {$companyCode}");
            }
            $scheduleDay = $row['schedule_day'] ?? '';
            $startsAt = $row['starts_at'] ?? '';
            $scheduleId = $this->scheduleIdForOrNull($companyCode, $roundSequence, $roundLabel, $scheduleSequence, $room, $startsAt, $scheduleDay);
            if ($scheduleId === null) {
                $this->addError($report, $rowNo, "Missing schedule: {$companyCode} {$roundSequence}. {$roundLabel} / {$room} {$startsAt}");
            }
            if ($applicationId === null || $scheduleId === null) {
                continue;
            }
            $key = $applicationId . ':' . $scheduleId;
            $this->noteDuplicate($report, $seen, $key, $rowNo, 'slot assignment');
            if ($this->slotAssignmentExists($applicationId, $scheduleId)) {
                $report['updates']++;
                $this->addSample($report, "Update {$candidateExternalId} / {$companyCode} slot {$assignmentSequence}");
            } else {
                $report['creates']++;
                $this->addSample($report, "Create {$candidateExternalId} / {$companyCode} slot {$assignmentSequence}");
            }
        }
        return $this->finishReport($report);
    }

    private function previewCandidateUnavailability(string $csv): array
    {
        $rows = $this->rows($csv, ['candidate_external_id', 'starts_at', 'ends_at']);
        $report = $this->newReport('unavailability', count($rows));
        $seen = [];
        foreach ($rows as $row) {
            $rowNo = (int) $row['__row'];
            $candidateExternalId = $row['candidate_external_id'] ?? '';
            $startsAt = $row['starts_at'] ?? '';
            $endsAt = $row['ends_at'] ?? '';
            $label = $row['label'] ?? 'Unavailable';
            if ($candidateExternalId === '' || $startsAt === '' || $endsAt === '') {
                $this->addError($report, $rowNo, 'Candidate external ID, start time, and end time are required.');
                continue;
            }
            $this->validatePreviewTime($report, $row, 'starts_at');
            $this->validatePreviewTime($report, $row, 'ends_at');
            $this->validatePreviewTimeRange($report, $row, 'starts_at', 'ends_at');
            $candidateId = $this->idForOrNull('candidates', 'external_id', $candidateExternalId);
            if ($candidateId === null) {
                $this->addError($report, $rowNo, "Missing candidate: {$candidateExternalId}");
                continue;
            }
            $key = $candidateId . ':' . ($row['schedule_day'] ?? '') . ':' . $startsAt . ':' . $endsAt . ':' . $label;
            $this->noteDuplicate($report, $seen, $key, $rowNo, 'candidate unavailable window');
            if ($this->candidateUnavailabilityExists($candidateId, (string) ($row['schedule_day'] ?? ''), $startsAt, $endsAt, $label)) {
                $report['updates']++;
                $this->addSample($report, "Update {$candidateExternalId} unavailable {$startsAt}-{$endsAt}");
            } else {
                $report['creates']++;
                $this->addSample($report, "Create {$candidateExternalId} unavailable {$startsAt}-{$endsAt}");
            }
        }
        return $this->finishReport($report);
    }

    private function previewShortlists(string $csv, array $validStatuses): array
    {
        $rows = $this->rows($csv, ['candidate_external_id', 'company_code']);
        $validStatuses = $this->validStatuses($validStatuses);
        $report = $this->newReport('shortlists', count($rows));
        $seen = [];
        foreach ($rows as $row) {
            $rowNo = (int) $row['__row'];
            $candidateExternalId = $row['candidate_external_id'] ?? '';
            $companyCode = strtoupper($row['company_code'] ?? '');
            $status = $row['status'] ?? 'scheduled';
            if ($candidateExternalId === '' || $companyCode === '') {
                $this->addError($report, $rowNo, 'Candidate external ID and company code are required.');
                continue;
            }
            if (!in_array($status, $validStatuses, true)) {
                $this->addError($report, $rowNo, "Unknown workflow status: {$status}");
            }
            $candidateId = $this->idForOrNull('candidates', 'external_id', $candidateExternalId);
            if ($candidateId === null) {
                $this->addError($report, $rowNo, "Missing candidate: {$candidateExternalId}");
            }
            $companyId = $this->idForOrNull('companies', 'code', $companyCode);
            if ($companyId === null) {
                $this->addError($report, $rowNo, "Missing company: {$companyCode}");
            }
            $this->validatePreviewInt($report, $row, 'waitlist_rank', true);
            if ($candidateId === null || $companyId === null) {
                continue;
            }
            $key = $candidateId . ':' . $companyId;
            $this->noteDuplicate($report, $seen, $key, $rowNo, 'shortlist');
            if ($this->applicationExists($candidateId, $companyId)) {
                $report['updates']++;
                $this->addSample($report, "Update {$candidateExternalId} for {$companyCode}");
            } else {
                $report['creates']++;
                $this->addSample($report, "Create {$candidateExternalId} for {$companyCode}");
            }
        }
        return $this->finishReport($report);
    }

    private function previewLegacyWide(string $csv, array $validStatuses): array
    {
        $rows = $this->rows($csv, ['external_id', 'name', 'company_code']);
        $report = $this->newReport('legacy', count($rows));
        $validStatuses = $this->validStatuses($validStatuses);
        $seen = [];
        foreach ($rows as $row) {
            $rowNo = (int) $row['__row'];
            $externalId = $row['external_id'] ?? '';
            $companyCode = strtoupper($row['company_code'] ?? '');
            if ($externalId === '' || ($row['name'] ?? '') === '' || $companyCode === '') {
                $this->addError($report, $rowNo, 'External ID, name, and company code are required.');
                continue;
            }
            $status = $this->legacyStatus($row);
            if (!in_array($status, $validStatuses, true)) {
                $this->addError($report, $rowNo, "Legacy status maps to unavailable workflow status: {$status}");
            }
            $gdRound = $this->previewIntValue($report, $row, 'gd_round', false, 0);
            $gdPanel = $this->previewIntValue($report, $row, 'gd_panel', false, 0);
            if (($gdRound > 0 && $gdPanel === 0) || ($gdRound === 0 && $gdPanel > 0)) {
                $this->addWarning($report, $rowNo, 'Legacy GD panel assignment needs both gd_round and gd_panel; the application will import without a slot assignment.');
            }
            $key = $externalId . ':' . $companyCode;
            $this->noteDuplicate($report, $seen, $key, $rowNo, 'legacy row');
            $report['creates']++;
            $this->addSample($report, "Upsert {$externalId}, {$companyCode}, status {$status}");
            if ($gdRound > 0 && $gdPanel > 0) {
                $this->addSample($report, "Map {$externalId}, {$companyCode} to GD Round {$gdRound} / Panel {$gdPanel}");
            }
        }
        return $this->finishReport($report);
    }

    /** @return array<int, array<string, string>> */
    private function rows(string $csv, array $required): array
    {
        $this->assertCsvSizeWithinLimit($csv);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $headers = fgetcsv($handle, null, ',', '"', '');
        if (!$headers) {
            throw new UserVisibleException('IMPORT_HEADER_REQUIRED', 'CSV has no header row.');
        }
        $rawHeaders = array_map(fn ($h) => trim((string) $h), $headers);
        $headers = [];
        foreach ($rawHeaders as $header) {
            $normalized = $this->normalizeHeader($header, $required);
            if ($normalized === '') {
                throw new UserVisibleException('IMPORT_HEADER_EMPTY', 'CSV has an empty header column.');
            }
            if (in_array($normalized, $headers, true)) {
                throw new UserVisibleException('IMPORT_HEADER_DUPLICATE', 'CSV has duplicate column after header normalization.');
            }
            $headers[] = $normalized;
        }
        foreach ($required as $field) {
            if (!in_array($field, $headers, true)) {
                throw new UserVisibleException('IMPORT_HEADER_MISSING', 'CSV is missing a required column.');
            }
        }

        $rows = [];
        $line = 1;
        $maxRows = $this->maxCsvRows();
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($values === [null] || $values === false) {
                continue;
            }
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = trim((string) ($values[$i] ?? ''));
            }
            if (implode('', $row) !== '') {
                if (count($rows) + 1 > $maxRows) {
                    fclose($handle);
                    throw new UserVisibleException('IMPORT_ROW_LIMIT_EXCEEDED', "CSV input has too many data rows. Limit is {$maxRows}.");
                }
                $row['__row'] = (string) $line;
                $rows[] = $row;
            }
        }
        fclose($handle);
        return $rows;
    }

    private function assertCsvSizeWithinLimit(string $csv): void
    {
        $maxBytes = $this->maxCsvBytes();
        $bytes = strlen($csv);
        if ($bytes > $maxBytes) {
            throw new UserVisibleException('IMPORT_SIZE_LIMIT_EXCEEDED', "CSV input is too large. Limit is {$maxBytes} bytes.");
        }
    }

    private function maxCsvBytes(): int
    {
        $value = getenv('CPE_IMPORT_MAX_BYTES');
        if ($value === false || trim((string) $value) === '') {
            $value = cpe_config('imports.max_bytes', 5000000);
        }
        return max(1, min(50000000, (int) $value));
    }

    private function maxCsvRows(): int
    {
        $value = getenv('CPE_IMPORT_MAX_ROWS');
        if ($value === false || trim((string) $value) === '') {
            $value = cpe_config('imports.max_rows', 10000);
        }
        return max(1, min(100000, (int) $value));
    }

    private function normalizeHeader(string $header, array $required): string
    {
        $key = $this->normalizeHeaderKey($header);
        if ($key === '' || in_array($key, $required, true)) {
            return $key;
        }
        if ($key === 'company_name' && in_array('company_code', $required, true)) {
            return $key;
        }
        $candidates = $this->headerAliasCandidates()[$key] ?? [];
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $required, true)) {
                return $candidate;
            }
        }
        return $candidates[0] ?? $key;
    }

    /** @return array<string, array<int, string>> */
    private function headerAliasCandidates(): array
    {
        if ($this->headerAliasCandidates !== null) {
            return $this->headerAliasCandidates;
        }
        $aliases = [];
        foreach (self::HEADER_ALIASES as $canonical => $values) {
            foreach ([$canonical, ...$values] as $value) {
                $this->addHeaderAlias($aliases, $canonical, $value);
            }
        }
        foreach ($this->customHeaderAliases() as $canonical => $values) {
            foreach ($values as $value) {
                $this->addHeaderAlias($aliases, $canonical, $value);
            }
        }
        $this->headerAliasCandidates = $aliases;
        return $this->headerAliasCandidates;
    }

    private function addHeaderAlias(array &$aliases, string $canonical, string $value): void
    {
        $key = $this->normalizeHeaderKey($value);
        if ($key === '') {
            return;
        }
        $aliases[$key] ??= [];
        if (!in_array($canonical, $aliases[$key], true)) {
            $aliases[$key][] = $canonical;
        }
    }

    private function customHeaderAliases(): array
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute(['import_header_aliases_json']);
        $json = $this->normalizeHeaderAliasJson((string) ($stmt->fetchColumn() ?: ''));
        if ($json === '') {
            return [];
        }
        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : [];
    }

    private function normalizeHeaderKey(string $header): string
    {
        $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($header))) ?? '';
        return trim($key, '_');
    }

    private function newReport(string $type, int $rows): array
    {
        return [
            'type' => $type,
            'rows' => $rows,
            'creates' => 0,
            'updates' => 0,
            'warnings' => [],
            'errors' => [],
            'samples' => [],
            'valid' => true,
        ];
    }

    private function finishReport(array $report): array
    {
        $report['valid'] = $report['errors'] === [];
        return $report;
    }

    private function addError(array &$report, int $rowNo, string $message): void
    {
        $report['errors'][] = "Row {$rowNo}: {$message}";
    }

    private function addWarning(array &$report, int $rowNo, string $message): void
    {
        $report['warnings'][] = "Row {$rowNo}: {$message}";
    }

    private function addSample(array &$report, string $message): void
    {
        if (count($report['samples']) < 8) {
            $report['samples'][] = $message;
        }
    }

    private function noteDuplicate(array &$report, array &$seen, string $key, int $rowNo, string $label): void
    {
        if (isset($seen[$key])) {
            $this->addWarning($report, $rowNo, "Duplicate {$label} also appears on row {$seen[$key]}; the later row wins.");
            return;
        }
        $seen[$key] = $rowNo;
    }

    private function validatePreviewInt(array &$report, array $row, string $field, bool $positive): void
    {
        if (!isset($row[$field]) || $row[$field] === '') {
            return;
        }
        $this->previewIntValue($report, $row, $field, $positive, $positive ? 1 : 0);
    }

    private function previewIntValue(array &$report, array $row, string $field, bool $positive, int $default): int
    {
        $value = $row[$field] ?? '';
        if ($value === '') {
            return $default;
        }
        if (!ctype_digit($value)) {
            $this->addError($report, (int) ($row['__row'] ?? 0), "{$field} must be a " . ($positive ? 'positive' : 'non-negative') . ' integer.');
            return $default;
        }
        $number = (int) $value;
        if ($positive && $number < 1) {
            $this->addError($report, (int) ($row['__row'] ?? 0), "{$field} must be a positive integer.");
        }
        return $number;
    }

    private function validatePreviewScheduleStatus(array &$report, array $row): void
    {
        $value = strtolower(trim((string) ($row['schedule_status'] ?? 'active')));
        if ($value !== '' && !in_array($value, ['active', 'paused', 'break', 'cancelled'], true)) {
            $this->addError($report, (int) ($row['__row'] ?? 0), 'schedule_status must be active, paused, break, or cancelled.');
        }
    }

    private function validatePreviewPanelistAvailabilityStatus(array &$report, array $row): void
    {
        $value = strtolower(trim((string) ($row['availability_status'] ?? 'active')));
        if ($value !== '' && !in_array($value, ['active', 'break', 'unavailable'], true)) {
            $this->addError($report, (int) ($row['__row'] ?? 0), 'availability_status must be active, break, or unavailable.');
        }
    }

    private function validatePreviewCustomFields(array &$report, array $row, string $label): void
    {
        try {
            $this->normalizeCustomFieldsJson((string) ($row['custom_fields_json'] ?? ''), $label, (int) ($row['__row'] ?? 0));
        } catch (UserVisibleException $e) {
            $this->addError($report, (int) ($row['__row'] ?? 0), $e->publicMessage());
        }
    }

    private function normalizeCustomFieldsJson(string $value, string $label, int $rowNo): string
    {
        $value = trim($value);
        if ($value === '') {
            return '{}';
        }
        if (strlen($value) > 5000) {
            throw new UserVisibleException('IMPORT_CUSTOM_FIELDS_INVALID', $label . ' JSON must be 5000 bytes or fewer.');
        }
        $payload = json_decode($value);
        if (!$payload instanceof \stdClass) {
            throw new UserVisibleException('IMPORT_CUSTOM_FIELDS_INVALID', $label . ' must be a JSON object.');
        }
        $fields = get_object_vars($payload);
        ksort($fields);
        $normalized = new \stdClass();
        foreach ($fields as $key => $fieldValue) {
            $key = trim((string) $key);
            if ($key === '' || strlen($key) > 60) {
                throw new UserVisibleException('IMPORT_CUSTOM_FIELDS_INVALID', $label . ' keys must be 1 to 60 characters.');
            }
            if (is_array($fieldValue) || is_object($fieldValue)) {
                throw new UserVisibleException('IMPORT_CUSTOM_FIELDS_INVALID', $label . ' values must be strings, numbers, booleans, or null.');
            }
            $normalized->{$key} = $fieldValue;
        }
        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $prefix = $rowNo > 0 ? "Row {$rowNo}: " : '';
            throw new RuntimeException($prefix . 'Could not normalize ' . strtolower($label) . '.');
        }
        return $encoded;
    }

    private function validatePreviewTime(array &$report, array $row, string $field): void
    {
        $value = (string) ($row[$field] ?? '');
        if ($value === '') {
            return;
        }
        if (!$this->isTime($value)) {
            $this->addError($report, (int) ($row['__row'] ?? 0), "{$field} must use HH:MM.");
        }
    }

    private function validatePreviewTimeRange(array &$report, array $row, string $startField, string $endField): void
    {
        $start = (string) ($row[$startField] ?? '');
        $end = (string) ($row[$endField] ?? '');
        if (!$this->isTime($start) || !$this->isTime($end)) {
            return;
        }
        if ($this->timeMinutes($end) <= $this->timeMinutes($start)) {
            $this->addError($report, (int) ($row['__row'] ?? 0), "{$endField} must be after {$startField}.");
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

    private function idForOrNull(string $table, string $column, string $value): ?int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
        $stmt->execute([$value]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function existsBy(string $table, string $column, string $value): bool
    {
        return $this->idForOrNull($table, $column, $value) !== null;
    }

    private function applicationExists(int $candidateId, int $companyId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM applications WHERE candidate_id = ? AND company_id = ?');
        $stmt->execute([$candidateId, $companyId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function roundExists(int $companyId, int $sequence, string $label): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM company_rounds WHERE company_id = ? AND sequence = ? AND label = ?');
        $stmt->execute([$companyId, $sequence, $label]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function roundIdFor(string $companyCode, int $sequence, string $label): int
    {
        $roundId = $this->roundIdForOrNull($companyCode, $sequence, $label);
        if ($roundId === null) {
            throw new RuntimeException("Missing company round: {$companyCode} {$sequence}. {$label}");
        }
        return $roundId;
    }

    private function roundIdForOrNull(string $companyCode, int $sequence, string $label): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.id
             FROM company_rounds cr
             JOIN companies co ON co.id = cr.company_id
             WHERE co.code = ? AND cr.sequence = ? AND cr.label = ?'
        );
        $stmt->execute([$companyCode, $sequence, $label]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function panelistExists(int $roundId, int $sequence, string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM round_panelists WHERE round_id = ? AND sequence = ? AND name = ?');
        $stmt->execute([$roundId, $sequence, $name]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function scheduleExists(int $roundId, int $sequence, string $room, string $startsAt): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM round_schedules WHERE round_id = ? AND sequence = ? AND room = ? AND starts_at = ?');
        $stmt->execute([$roundId, $sequence, $room, $startsAt]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function slotAssignmentExists(int $applicationId, int $scheduleId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM application_slot_assignments WHERE application_id = ? AND round_schedule_id = ?');
        $stmt->execute([$applicationId, $scheduleId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function candidateUnavailabilityExists(int $candidateId, string $scheduleDay, string $startsAt, string $endsAt, string $label): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM candidate_unavailability_windows
             WHERE candidate_id = ? AND schedule_day = ? AND starts_at = ? AND ends_at = ? AND label = ?'
        );
        $stmt->execute([$candidateId, $scheduleDay, $startsAt, $endsAt, $label]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function applicationIdFor(string $candidateExternalId, string $companyCode): int
    {
        $id = $this->applicationIdForOrNull($candidateExternalId, $companyCode);
        if ($id === null) {
            throw new RuntimeException("Missing application: {$candidateExternalId} / {$companyCode}");
        }
        return $id;
    }

    private function applicationIdForOrNull(string $candidateExternalId, string $companyCode): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id
             FROM applications a
             JOIN candidates c ON c.id = a.candidate_id
             JOIN companies co ON co.id = a.company_id
             WHERE c.external_id = ? AND co.code = ?'
        );
        $stmt->execute([$candidateExternalId, $companyCode]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function scheduleIdFor(string $companyCode, int $roundSequence, string $roundLabel, int $scheduleSequence, string $room, string $startsAt, string $scheduleDay = ''): int
    {
        $id = $this->scheduleIdForOrNull($companyCode, $roundSequence, $roundLabel, $scheduleSequence, $room, $startsAt, $scheduleDay);
        if ($id === null) {
            throw new RuntimeException("Missing schedule: {$companyCode} {$roundSequence}. {$roundLabel} / {$room} {$startsAt}");
        }
        return $id;
    }

    private function scheduleIdForOrNull(string $companyCode, int $roundSequence, string $roundLabel, int $scheduleSequence, string $room, string $startsAt, string $scheduleDay = ''): ?int
    {
        $sql = 'SELECT rs.id
                FROM round_schedules rs
                JOIN company_rounds cr ON cr.id = rs.round_id
                JOIN companies co ON co.id = cr.company_id
                WHERE co.code = ? AND cr.sequence = ? AND cr.label = ?
                  AND rs.sequence = ? AND rs.room = ? AND rs.starts_at = ?';
        $params = [$companyCode, $roundSequence, $roundLabel, $scheduleSequence, $room, $startsAt];
        if (trim($scheduleDay) !== '') {
            $sql .= ' AND rs.schedule_day = ?';
            $params[] = trim($scheduleDay);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function validStatuses(array $validStatuses): array
    {
        if ($validStatuses !== []) {
            return array_values($validStatuses);
        }
        $workflow = cpe_config('workflows.default.statuses', []);
        return array_keys($workflow);
    }

    private function legacyGdPanelAssignment(array $row): void
    {
        $gdRound = $this->nonNegativeInt($row['gd_round'] ?? '0', 'gd_round', (int) ($row['__row'] ?? 0));
        $gdPanel = $this->nonNegativeInt($row['gd_panel'] ?? '0', 'gd_panel', (int) ($row['__row'] ?? 0));
        if ($gdRound === 0 || $gdPanel === 0) {
            return;
        }

        $companyCode = strtoupper($row['company_code'] ?? '');
        $companyId = $this->idFor('companies', 'code', $companyCode);
        $applicationId = $this->applicationIdFor($row['external_id'] ?? '', $companyCode);
        $roundLabel = "GD Round {$gdRound}";
        $room = "GD Panel {$gdPanel}";
        $now = cpe_now();

        $round = $this->pdo->prepare(
            'INSERT INTO company_rounds (company_id, sequence, label, round_type, room, duration_minutes, instructions, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(company_id, sequence, label) DO UPDATE SET
                round_type = excluded.round_type,
                room = excluded.room,
                instructions = excluded.instructions,
                updated_at = excluded.updated_at'
        );
        $round->execute([
            $companyId,
            $gdRound,
            $roundLabel,
            'gd',
            $room,
            0,
            'Imported from legacy GD round/panel columns.',
            $now,
            $now,
        ]);
        $roundId = $this->roundIdFor($companyCode, $gdRound, $roundLabel);
        $scheduleDay = trim((string) ($row['slot'] ?? ''));

        $schedule = $this->pdo->prepare(
            'INSERT INTO round_schedules (round_id, sequence, room, schedule_day, starts_at, ends_at, capacity, schedule_status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(round_id, sequence, room, starts_at) DO UPDATE SET
                schedule_day = excluded.schedule_day,
                ends_at = excluded.ends_at,
                schedule_status = excluded.schedule_status,
                notes = excluded.notes,
                updated_at = excluded.updated_at'
        );
        $schedule->execute([
            $roundId,
            $gdPanel,
            $room,
            $scheduleDay,
            '',
            '',
            0,
            'active',
            'Imported from legacy GD round/panel columns.',
            $now,
            $now,
        ]);
        $scheduleId = $this->scheduleIdFor($companyCode, $gdRound, $roundLabel, $gdPanel, $room, '', $scheduleDay);

        $assignment = $this->pdo->prepare(
            'INSERT INTO application_slot_assignments (application_id, round_schedule_id, sequence, assignment_status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(application_id, round_schedule_id) DO UPDATE SET
                sequence = excluded.sequence,
                assignment_status = excluded.assignment_status,
                notes = excluded.notes,
                updated_at = excluded.updated_at'
        );
        $assignment->execute([
            $applicationId,
            $scheduleId,
            $gdRound,
            'assigned',
            'Imported from legacy GD round/panel columns.',
            $now,
            $now,
        ]);
    }

    private function positiveInt(string $value, string $field, int $rowNo): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException("Row {$rowNo}: {$field} must be a positive integer.");
        }
        return (int) $value;
    }

    private function nonNegativeInt(string $value, string $field, int $rowNo): int
    {
        if ($value === '') {
            return 0;
        }
        if (!ctype_digit($value)) {
            throw new RuntimeException("Row {$rowNo}: {$field} must be a non-negative integer.");
        }
        return (int) $value;
    }

    private function normalizeScheduleStatus(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['active', 'paused', 'break', 'cancelled'], true) ? $value : 'active';
    }

    private function normalizePanelistAvailabilityStatus(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['active', 'break', 'unavailable'], true) ? $value : 'active';
    }

    private function legacyStatus(array $row): string
    {
        $map = [
            0 => 'idle',
            1 => 'scheduled',
            2 => 'intransit',
            3 => 'arrived',
            4 => 'requested',
            5 => 'sendin',
            6 => 'inside',
            7 => 'exit',
            8 => 'requestaway',
            9 => 'sendaway',
            10 => 'sent',
            11 => 'placed',
        ];
        $highest = 0;
        foreach (range(0, 11) as $i) {
            if (isset($row['stat' . $i]) && trim((string) $row['stat' . $i]) !== '') {
                $highest = max($highest, (int) $row['stat' . $i]);
            }
        }
        return $map[$highest] ?? 'idle';
    }

    private function boolInt(string $value): int
    {
        return in_array(strtolower(trim($value)), ['1', 'yes', 'true', 'y'], true) ? 1 : 0;
    }

    private function assertTime(string $value, string $field, int $rowNo): void
    {
        if (!$this->isTime($value)) {
            throw new RuntimeException("Row {$rowNo}: {$field} must use HH:MM.");
        }
    }

    private function assertTimeRange(string $start, string $end, int $rowNo): void
    {
        if ($this->timeMinutes($end) <= $this->timeMinutes($start)) {
            throw new RuntimeException("Row {$rowNo}: ends_at must be after starts_at.");
        }
    }

    private function isTime(string $value): bool
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', trim($value), $matches)) {
            return false;
        }
        return (int) $matches[1] <= 23 && (int) $matches[2] <= 59;
    }

    private function timeMinutes(string $value): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', trim($value), 2));
        return ($hours * 60) + $minutes;
    }

    private function synchronizeDurableDomain(bool $workflows = false): void
    {
        (new LegacyDomainSynchronizer())->synchronize($this->pdo);
        if ($workflows) {
            (new WorkflowRepository($this->pdo))->synchronizeApplicationInstances();
        }
    }
}
