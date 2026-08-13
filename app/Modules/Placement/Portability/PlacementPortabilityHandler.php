<?php

declare(strict_types=1);

namespace App\Modules\Placement\Portability;

use App\Core\Modules\ModulePortabilityHandler;
use App\Import\CsvImporter;
use App\Modules\Placement\Application\PlacementService;
use App\Modules\Placement\Install\LegacyDomainSynchronizer;
use App\Modules\Placement\Workflow\WorkflowDefinitionFileService;
use App\Modules\Placement\Workflow\WorkflowPublisher;
use App\Modules\Placement\Workflow\WorkflowRepository;
use App\Support\Database;
use PDO;
use RuntimeException;

final class PlacementPortabilityHandler implements ModulePortabilityHandler
{
    public const SCHEMA = 'career_services.module.placement.v1';

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function export(): array
    {
        $workflowFiles = new WorkflowDefinitionFileService($this->pdo);
        $workflows = [];
        foreach ((new WorkflowRepository($this->pdo))->versions() as $version) {
            $payload = $workflowFiles->payloadForVersion((int) $version['id']);
            $payload['active'] = (bool) $version['is_active'];
            $workflows[] = $payload;
        }

        return [
            'schema' => self::SCHEMA,
            'module_version' => (string) cpe_config('modules.placement.version', '0.0.0'),
            'cycle' => $this->pdo->query(
                "SELECT pc.public_id, pc.cycle_key, pc.name, pc.cycle_type, pc.starts_on, pc.ends_on,
                        pc.status, pc.created_at, pc.updated_at, wv.checksum AS active_workflow_checksum
                 FROM placement_cycles pc
                 LEFT JOIN workflow_versions wv ON wv.id = pc.active_workflow_version_id
                 WHERE pc.cycle_key = 'default'"
            )->fetch() ?: [],
            'workflows' => $workflows,
            'companies' => $this->rows(
                'SELECT co.public_id, co.code, co.name, co.slot, co.offer_tier, co.process_type, co.room,
                        co.tracker_name, co.max_active, co.deadline_day, co.deadline_at, co.process_notes,
                        co.tags, co.custom_fields_json, co.created_at, co.updated_at,
                        o.public_id AS organization_public_id, po.public_id AS opportunity_public_id
                 FROM companies co
                 LEFT JOIN organizations o ON o.legacy_company_id = co.id
                 LEFT JOIN placement_opportunities po ON po.legacy_company_id = co.id
                 ORDER BY co.code'
            ),
            'candidates' => $this->rows(
                "SELECT c.public_id, c.external_id, c.name, c.program, c.tags, c.current_location,
                        c.accommodation_notes, c.custom_fields_json, c.opted_out, c.anonymized_at,
                        COALESCE(pc.code, '') AS placed_company_code, c.created_at, c.updated_at,
                        p.public_id AS person_public_id, sp.public_id AS student_profile_public_id,
                        pp.public_id AS participant_public_id
                 FROM candidates c
                 LEFT JOIN companies pc ON pc.id = c.placed_company_id
                 LEFT JOIN people p ON p.legacy_candidate_id = c.id
                 LEFT JOIN student_profiles sp ON sp.person_id = p.id
                 LEFT JOIN placement_cycle_participants pp ON pp.legacy_candidate_id = c.id
                 ORDER BY c.external_id"
            ),
            'applications' => $this->rows(
                "SELECT a.public_id, c.external_id AS candidate_external_id, co.code AS company_code,
                        a.current_status, COALESCE(pc.code, '') AS previous_company_code,
                        COALESCE(nc.code, '') AS next_company_code, a.waitlist_rank,
                        wv.checksum AS workflow_checksum, wi.public_id AS workflow_instance_public_id,
                        a.created_at, a.updated_at
                 FROM applications a
                 JOIN candidates c ON c.id = a.candidate_id
                 JOIN companies co ON co.id = a.company_id
                 LEFT JOIN companies pc ON pc.id = a.previous_company_id
                 LEFT JOIN companies nc ON nc.id = a.next_company_id
                 LEFT JOIN workflow_versions wv ON wv.id = a.workflow_version_id
                 LEFT JOIN workflow_instances wi ON wi.application_id = a.id
                 ORDER BY c.external_id, co.code"
            ),
            'offers' => $this->rows(
                'SELECT po.public_id, c.external_id AS candidate_external_id, co.code AS company_code,
                        po.offer_status, po.offer_tier, po.source, po.offered_at, po.decided_at,
                        po.created_at, po.updated_at
                 FROM placement_offers po
                 JOIN placement_cycle_participants pcp ON pcp.id = po.participant_id
                 JOIN candidates c ON c.id = pcp.legacy_candidate_id
                 JOIN placement_opportunities pop ON pop.id = po.opportunity_id
                 JOIN companies co ON co.id = pop.legacy_company_id
                 ORDER BY c.external_id, co.code, po.source'
            ),
            'company_rounds' => $this->rows(
                'SELECT co.code AS company_code, cr.sequence, cr.label, cr.round_type, cr.room,
                        cr.duration_minutes, cr.instructions, cr.created_at, cr.updated_at
                 FROM company_rounds cr JOIN companies co ON co.id = cr.company_id
                 ORDER BY co.code, cr.sequence, cr.label'
            ),
            'round_schedules' => $this->rows(
                'SELECT co.code AS company_code, cr.sequence AS round_sequence, cr.label AS round_label,
                        rs.sequence, rs.room, rs.schedule_day, rs.starts_at, rs.ends_at, rs.capacity,
                        rs.schedule_status, rs.notes, rs.created_at, rs.updated_at
                 FROM round_schedules rs
                 JOIN company_rounds cr ON cr.id = rs.round_id
                 JOIN companies co ON co.id = cr.company_id
                 ORDER BY co.code, cr.sequence, rs.sequence, rs.starts_at'
            ),
            'round_panelists' => $this->rows(
                'SELECT co.code AS company_code, cr.sequence AS round_sequence, cr.label AS round_label,
                        rp.sequence, rp.name, rp.role, rp.affiliation, rp.contact,
                        rp.availability_status, rp.notes, rp.created_at, rp.updated_at
                 FROM round_panelists rp
                 JOIN company_rounds cr ON cr.id = rp.round_id
                 JOIN companies co ON co.id = cr.company_id
                 ORDER BY co.code, cr.sequence, rp.sequence, rp.name'
            ),
            'candidate_unavailability' => $this->rows(
                'SELECT c.external_id AS candidate_external_id, cuw.label, cuw.schedule_day,
                        cuw.starts_at, cuw.ends_at, cuw.notes, cuw.created_at, cuw.updated_at
                 FROM candidate_unavailability_windows cuw
                 JOIN candidates c ON c.id = cuw.candidate_id
                 ORDER BY c.external_id, cuw.schedule_day, cuw.starts_at'
            ),
            'slot_assignments' => $this->rows(
                'SELECT c.external_id AS candidate_external_id, co.code AS company_code,
                        cr.sequence AS round_sequence, cr.label AS round_label,
                        rs.sequence AS schedule_sequence, rs.room, rs.schedule_day, rs.starts_at,
                        asa.sequence AS assignment_sequence, asa.assignment_status, asa.notes,
                        asa.created_at, asa.updated_at
                 FROM application_slot_assignments asa
                 JOIN applications a ON a.id = asa.application_id
                 JOIN candidates c ON c.id = a.candidate_id
                 JOIN companies co ON co.id = a.company_id
                 JOIN round_schedules rs ON rs.id = asa.round_schedule_id
                 JOIN company_rounds cr ON cr.id = rs.round_id
                 ORDER BY c.external_id, co.code, cr.sequence, asa.sequence'
            ),
            'events' => $this->rows(
                'SELECT a.public_id AS application_public_id, c.external_id AS candidate_external_id,
                        co.code AS company_code, e.from_status, e.to_status, e.actor_role,
                        e.note, e.created_at
                 FROM events e
                 JOIN applications a ON a.id = e.application_id
                 JOIN candidates c ON c.id = e.candidate_id
                 JOIN companies co ON co.id = e.company_id
                 ORDER BY e.id'
            ),
            'workflow_events' => $this->rows(
                'SELECT wte.public_id, a.public_id AS application_public_id, wv.checksum AS workflow_checksum,
                        wte.transition_key, wte.from_state_key, wte.to_state_key, wte.actor_role,
                        wte.reason, wte.note, wte.context_json, wte.occurred_at
                 FROM workflow_transition_events wte
                 JOIN applications a ON a.id = wte.application_id
                 JOIN workflow_versions wv ON wv.id = wte.workflow_version_id
                 ORDER BY wte.id'
            ),
            'preference_requests' => $this->rows(
                "SELECT 'preference_' || pr.id AS request_key, c.external_id AS candidate_external_id,
                        pr.status, pr.note, COALESCE(co.code, '') AS decision_company_code,
                        pr.created_at, pr.resolved_at
                 FROM preference_requests pr
                 JOIN candidates c ON c.id = pr.candidate_id
                 LEFT JOIN companies co ON co.id = pr.decision_company_id
                 ORDER BY pr.id"
            ),
            'preference_options' => $this->rows(
                "SELECT 'preference_' || po.request_id AS request_key, co.code AS company_code
                 FROM preference_options po JOIN companies co ON co.id = po.company_id
                 ORDER BY po.request_id, co.code"
            ),
            'wanted_alerts' => $this->rows(
                'SELECT c.external_id AS candidate_external_id, wa.reason, wa.status,
                        wa.created_at, wa.resolved_at
                 FROM wanted_alerts wa JOIN candidates c ON c.id = wa.candidate_id
                 ORDER BY wa.id'
            ),
            'excluded' => [
                'users',
                'password_hashes',
                'sessions',
                'notification_credentials',
                'notification_delivery_attempts',
                'idempotency_keys',
                'audit_request_metadata',
                'domain_event_outbox',
            ],
        ];
    }

    public function validate(array $payload): array
    {
        if (($payload['schema'] ?? '') !== self::SCHEMA) {
            throw new RuntimeException('Unsupported Placement Operations portability schema.');
        }
        if (!is_array($payload['cycle'] ?? null) || trim((string) ($payload['cycle']['public_id'] ?? '')) === '') {
            throw new RuntimeException('Placement portability payload is missing its placement cycle.');
        }
        foreach (['workflows', 'companies', 'candidates', 'applications', 'offers'] as $required) {
            if (!is_array($payload[$required] ?? null)) {
                throw new RuntimeException('Placement portability payload is missing: ' . $required);
            }
        }
        $this->assertUnique($payload['companies'], 'code', 'company code');
        $this->assertUnique($payload['companies'], 'public_id', 'company public id');
        $this->assertUnique($payload['candidates'], 'external_id', 'candidate external id');
        $this->assertUnique($payload['candidates'], 'public_id', 'candidate public id');
        $this->assertUnique($payload['applications'], fn (array $row): string => (string) ($row['candidate_external_id'] ?? '') . '|' . (string) ($row['company_code'] ?? ''), 'application');
        $this->assertUnique($payload['applications'], 'public_id', 'application public id');
        $this->assertUnique(
            $payload['workflows'],
            fn (array $row): string => (string) ($row['workflow_key'] ?? '') . '|' . (string) ($row['checksum'] ?? ''),
            'workflow key and checksum'
        );
        $this->assertUnique($payload['offers'], 'public_id', 'offer public id');
        $this->validateReferences($payload);
        foreach (['password_hash', 'api_token', 'client_secret', 'session_id'] as $forbidden) {
            if ($this->hasFieldContaining($payload, $forbidden)) {
                throw new RuntimeException('Placement portability payload contains a forbidden secret field: ' . $forbidden);
            }
        }
        return [
            'companies' => count($payload['companies']),
            'candidates' => count($payload['candidates']),
            'applications' => count($payload['applications']),
            'workflow_versions' => count($payload['workflows']),
        ];
    }

    public function import(array $payload): array
    {
        $counts = $this->validate($payload);
        foreach (['candidates', 'companies', 'applications'] as $table) {
            if ((int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() > 0) {
                throw new RuntimeException('Placement portability import requires an empty operational database. Clear demo data or use a fresh installation.');
            }
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $versionIds = $this->importWorkflows($payload['workflows']);
            $service = new PlacementService($this->pdo);
            $companyIds = $this->importCompanies($payload['companies'], $service);
            $candidateIds = $this->importCandidates($payload['candidates'], $service);
            $applicationIds = $this->importApplications($payload['applications'], $candidateIds, $companyIds, $versionIds, $service);
            $this->importOperationalTables($payload, $companyIds, $candidateIds, $applicationIds);
            (new LegacyDomainSynchronizer())->synchronize($this->pdo);
            $this->restoreDurablePublicIds($payload, $candidateIds, $companyIds);
            $this->importOffers($payload['offers'], $candidateIds, $companyIds);
            $this->importWorkflowEvents($payload['workflow_events'] ?? [], $applicationIds, $versionIds);
            $this->restoreCycle($payload['cycle'], $versionIds);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $counts;
    }

    private function importWorkflows(array $workflows): array
    {
        usort($workflows, fn (array $a, array $b): int => ((int) ($a['exported_version'] ?? 0)) <=> ((int) ($b['exported_version'] ?? 0)));
        $publisher = new WorkflowPublisher($this->pdo);
        $ids = [];
        foreach ($workflows as $workflow) {
            if (($workflow['schema'] ?? '') !== WorkflowDefinitionFileService::SCHEMA || !is_array($workflow['definition'] ?? null)) {
                throw new RuntimeException('Placement bundle contains an invalid workflow definition.');
            }
            $id = $publisher->publish(
                (string) $workflow['workflow_key'],
                $workflow['definition'],
                'portability',
                null,
                !empty($workflow['active'])
            );
            $ids[(string) $workflow['checksum']] = $id;
        }
        return $ids;
    }

    private function importCompanies(array $rows, PlacementService $service): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $service->saveCompany($row, null);
            $ids[(string) $row['code']] = $id;
            $stmt = $this->pdo->prepare('UPDATE companies SET public_id = ?, created_at = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([(string) $row['public_id'], (string) $row['created_at'], (string) $row['updated_at'], $id]);
        }
        return $ids;
    }

    private function importCandidates(array $rows, PlacementService $service): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $service->saveCandidate($row, null);
            $ids[(string) $row['external_id']] = $id;
            $stmt = $this->pdo->prepare(
                'UPDATE candidates SET public_id = ?, anonymized_at = ?, created_at = ?, updated_at = ? WHERE id = ?'
            );
            $stmt->execute([
                (string) $row['public_id'],
                $row['anonymized_at'] ?: null,
                (string) $row['created_at'],
                (string) $row['updated_at'],
                $id,
            ]);
        }
        return $ids;
    }

    private function importApplications(
        array $rows,
        array $candidateIds,
        array $companyIds,
        array $versionIds,
        PlacementService $service
    ): array {
        $ids = [];
        foreach ($rows as $row) {
            $candidateId = $candidateIds[(string) $row['candidate_external_id']] ?? 0;
            $companyId = $companyIds[(string) $row['company_code']] ?? 0;
            if ($candidateId <= 0 || $companyId <= 0) {
                throw new RuntimeException('Application references an unknown candidate or company.');
            }
            $service->saveApplication($candidateId, $companyId, (string) $row['current_status'], $row['waitlist_rank'] === null ? null : (int) $row['waitlist_rank'], null);
            $lookup = $this->pdo->prepare('SELECT id FROM applications WHERE candidate_id = ? AND company_id = ?');
            $lookup->execute([$candidateId, $companyId]);
            $id = (int) $lookup->fetchColumn();
            $ids[(string) $row['public_id']] = $id;
            $previousId = $row['previous_company_code'] !== '' ? ($companyIds[(string) $row['previous_company_code']] ?? null) : null;
            $nextId = $row['next_company_code'] !== '' ? ($companyIds[(string) $row['next_company_code']] ?? null) : null;
            $versionId = $versionIds[(string) ($row['workflow_checksum'] ?? '')] ?? null;
            $stmt = $this->pdo->prepare(
                'UPDATE applications
                 SET public_id = ?, previous_company_id = ?, next_company_id = ?, workflow_version_id = ?, created_at = ?, updated_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([(string) $row['public_id'], $previousId, $nextId, $versionId, (string) $row['created_at'], (string) $row['updated_at'], $id]);
            $instance = $this->pdo->prepare(
                'UPDATE workflow_instances
                 SET public_id = ?, workflow_version_id = COALESCE(?, workflow_version_id), current_state_key = ?, started_at = ?, updated_at = ?
                 WHERE application_id = ?'
            );
            $instance->execute([
                (string) ($row['workflow_instance_public_id'] ?: 'workflow_instance_' . bin2hex(random_bytes(16))),
                $versionId,
                (string) $row['current_status'],
                (string) $row['created_at'],
                (string) $row['updated_at'],
                $id,
            ]);
        }
        return $ids;
    }

    private function importOperationalTables(array $payload, array $companyIds, array $candidateIds, array $applicationIds): void
    {
        $importer = new CsvImporter($this->pdo);
        $this->importCsvRows($payload['company_rounds'] ?? [], $importer->companyRounds(...));
        $this->importCsvRows($payload['round_schedules'] ?? [], $importer->roundSchedules(...));
        $this->importCsvRows($payload['round_panelists'] ?? [], $importer->roundPanelists(...));
        $this->importCsvRows($payload['candidate_unavailability'] ?? [], $importer->candidateUnavailability(...));
        $this->importCsvRows($payload['slot_assignments'] ?? [], $importer->slotAssignments(...));

        $event = $this->pdo->prepare(
            'INSERT INTO events
             (application_id, candidate_id, company_id, from_status, to_status, actor_user_id, actor_role, note, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)'
        );
        foreach ($payload['events'] ?? [] as $row) {
            $applicationId = $applicationIds[(string) $row['application_public_id']] ?? 0;
            $candidateId = $candidateIds[(string) $row['candidate_external_id']] ?? 0;
            $companyId = $companyIds[(string) $row['company_code']] ?? 0;
            if ($applicationId <= 0 || $candidateId <= 0 || $companyId <= 0) {
                throw new RuntimeException('Movement event references an unknown application, candidate, or company.');
            }
            $event->execute([$applicationId, $candidateId, $companyId, $row['from_status'], $row['to_status'], $row['actor_role'], $row['note'], $row['created_at']]);
        }

        $requestIds = [];
        $request = $this->pdo->prepare(
            'INSERT INTO preference_requests
             (candidate_id, status, note, requested_by, decision_company_id, created_at, resolved_at)
             VALUES (?, ?, ?, NULL, ?, ?, ?)'
        );
        foreach ($payload['preference_requests'] ?? [] as $row) {
            $request->execute([
                $candidateIds[(string) $row['candidate_external_id']],
                $row['status'],
                $row['note'],
                $row['decision_company_code'] !== '' ? $companyIds[(string) $row['decision_company_code']] : null,
                $row['created_at'],
                $row['resolved_at'] ?: null,
            ]);
            $requestIds[(string) $row['request_key']] = Database::lastInsertId($this->pdo);
        }
        $option = $this->pdo->prepare('INSERT INTO preference_options (request_id, company_id) VALUES (?, ?)');
        foreach ($payload['preference_options'] ?? [] as $row) {
            $option->execute([$requestIds[(string) $row['request_key']], $companyIds[(string) $row['company_code']]]);
        }
        $wanted = $this->pdo->prepare(
            'INSERT INTO wanted_alerts
             (candidate_id, reason, status, created_by, resolved_by, created_at, resolved_at)
             VALUES (?, ?, ?, NULL, NULL, ?, ?)'
        );
        foreach ($payload['wanted_alerts'] ?? [] as $row) {
            $wanted->execute([
                $candidateIds[(string) $row['candidate_external_id']],
                $row['reason'],
                $row['status'],
                $row['created_at'],
                $row['resolved_at'] ?: null,
            ]);
        }

        $placed = $this->pdo->prepare('UPDATE candidates SET placed_company_id = ? WHERE id = ?');
        foreach ($payload['candidates'] ?? [] as $row) {
            if ((string) $row['placed_company_code'] !== '') {
                $placed->execute([$companyIds[(string) $row['placed_company_code']], $candidateIds[(string) $row['external_id']]]);
            }
        }
    }

    private function restoreDurablePublicIds(array $payload, array $candidateIds, array $companyIds): void
    {
        foreach ($payload['candidates'] as $row) {
            $candidateId = $candidateIds[(string) $row['external_id']];
            $this->pdo->prepare('UPDATE people SET public_id = ? WHERE legacy_candidate_id = ?')
                ->execute([$row['person_public_id'], $candidateId]);
            $this->pdo->prepare('UPDATE student_profiles SET public_id = ? WHERE person_id = (SELECT id FROM people WHERE legacy_candidate_id = ?)')
                ->execute([$row['student_profile_public_id'], $candidateId]);
            $this->pdo->prepare('UPDATE placement_cycle_participants SET public_id = ? WHERE legacy_candidate_id = ?')
                ->execute([$row['participant_public_id'], $candidateId]);
        }
        foreach ($payload['companies'] as $row) {
            $companyId = $companyIds[(string) $row['code']];
            $this->pdo->prepare('UPDATE organizations SET public_id = ? WHERE legacy_company_id = ?')
                ->execute([$row['organization_public_id'], $companyId]);
            $this->pdo->prepare('UPDATE placement_opportunities SET public_id = ? WHERE legacy_company_id = ?')
                ->execute([$row['opportunity_public_id'], $companyId]);
        }
    }

    private function importOffers(array $rows, array $candidateIds, array $companyIds): void
    {
        $participant = $this->pdo->prepare('SELECT id FROM placement_cycle_participants WHERE legacy_candidate_id = ?');
        $opportunity = $this->pdo->prepare('SELECT id FROM placement_opportunities WHERE legacy_company_id = ?');
        $upsert = $this->pdo->prepare(
            'INSERT INTO placement_offers
             (public_id, participant_id, opportunity_id, offer_status, offer_tier, source,
              offered_at, decided_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(participant_id, opportunity_id, source) DO UPDATE SET
                public_id = excluded.public_id,
                offer_status = excluded.offer_status,
                offer_tier = excluded.offer_tier,
                offered_at = excluded.offered_at,
                decided_at = excluded.decided_at,
                created_at = excluded.created_at,
                updated_at = excluded.updated_at'
        );
        foreach ($rows as $row) {
            $candidateId = $candidateIds[(string) $row['candidate_external_id']] ?? 0;
            $companyId = $companyIds[(string) $row['company_code']] ?? 0;
            $participant->execute([$candidateId]);
            $participantId = (int) $participant->fetchColumn();
            $opportunity->execute([$companyId]);
            $opportunityId = (int) $opportunity->fetchColumn();
            if ($participantId <= 0 || $opportunityId <= 0) {
                throw new RuntimeException('Placement offer references an unknown participant or opportunity.');
            }
            $upsert->execute([
                $row['public_id'],
                $participantId,
                $opportunityId,
                $row['offer_status'],
                $row['offer_tier'],
                $row['source'],
                $row['offered_at'] ?: null,
                $row['decided_at'] ?: null,
                $row['created_at'],
                $row['updated_at'],
            ]);
        }
    }

    private function restoreCycle(array $cycle, array $versionIds): void
    {
        $activeVersionId = $versionIds[(string) ($cycle['active_workflow_checksum'] ?? '')] ?? null;
        $stmt = $this->pdo->prepare(
            "UPDATE placement_cycles
             SET public_id = ?, status = ?, active_workflow_version_id = ?, created_at = ?, updated_at = ?
             WHERE cycle_key = 'default'"
        );
        $stmt->execute([
            $cycle['public_id'],
            $cycle['status'],
            $activeVersionId,
            $cycle['created_at'],
            $cycle['updated_at'],
        ]);
    }

    private function importWorkflowEvents(array $rows, array $applicationIds, array $versionIds): void
    {
        $instance = $this->pdo->prepare('SELECT id FROM workflow_instances WHERE application_id = ?');
        $transition = $this->pdo->prepare('SELECT id FROM workflow_transitions WHERE workflow_version_id = ? AND transition_key = ?');
        $insert = $this->pdo->prepare(
            'INSERT INTO workflow_transition_events
             (public_id, workflow_instance_id, application_id, workflow_version_id, workflow_transition_id,
              transition_key, from_state_key, to_state_key, actor_user_id, actor_role, reason, note, context_json, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $applicationId = $applicationIds[(string) $row['application_public_id']] ?? 0;
            $versionId = $versionIds[(string) $row['workflow_checksum']] ?? 0;
            if ($applicationId <= 0 || $versionId <= 0) {
                throw new RuntimeException('Workflow event references an unknown application or workflow version.');
            }
            $instance->execute([$applicationId]);
            $instanceId = (int) $instance->fetchColumn();
            $transition->execute([$versionId, $row['transition_key']]);
            $transitionId = $transition->fetchColumn();
            $insert->execute([
                $row['public_id'],
                $instanceId,
                $applicationId,
                $versionId,
                $transitionId === false ? null : (int) $transitionId,
                $row['transition_key'],
                $row['from_state_key'],
                $row['to_state_key'],
                $row['actor_role'],
                $row['reason'],
                $row['note'],
                $row['context_json'],
                $row['occurred_at'],
            ]);
        }
    }

    private function csv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $handle = fopen('php://temp', 'w+');
        $headers = array_keys($rows[0]);
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header): mixed => $row[$header] ?? '', $headers), ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);
        return $csv;
    }

    private function importCsvRows(array $rows, callable $import): void
    {
        if ($rows !== []) {
            $import($this->csv($rows));
        }
    }

    private function rows(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll();
    }

    private function assertUnique(array $rows, string|callable $key, string $label): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $value = is_callable($key) ? $key($row) : (string) ($row[$key] ?? '');
            if ($value === '' || isset($seen[$value])) {
                throw new RuntimeException('Placement portability payload has an empty or duplicate ' . $label . ': ' . $value);
            }
            $seen[$value] = true;
        }
    }

    private function validateReferences(array $payload): void
    {
        $companies = array_fill_keys(array_map(fn (array $row): string => (string) $row['code'], $payload['companies']), true);
        $candidates = array_fill_keys(array_map(fn (array $row): string => (string) $row['external_id'], $payload['candidates']), true);
        $applications = array_fill_keys(array_map(fn (array $row): string => (string) $row['public_id'], $payload['applications']), true);
        $workflows = array_fill_keys(array_map(fn (array $row): string => (string) $row['checksum'], $payload['workflows']), true);

        foreach ($payload['applications'] as $row) {
            if (!isset($candidates[(string) ($row['candidate_external_id'] ?? '')])
                || !isset($companies[(string) ($row['company_code'] ?? '')])
                || !isset($workflows[(string) ($row['workflow_checksum'] ?? '')])) {
                throw new RuntimeException('Application references a candidate, company, or workflow outside the bundle.');
            }
        }
        foreach ($payload['offers'] as $row) {
            if (!isset($candidates[(string) ($row['candidate_external_id'] ?? '')])
                || !isset($companies[(string) ($row['company_code'] ?? '')])) {
                throw new RuntimeException('Placement offer references a candidate or company outside the bundle.');
            }
        }
        foreach ($payload['workflow_events'] ?? [] as $row) {
            if (!isset($applications[(string) ($row['application_public_id'] ?? '')])
                || !isset($workflows[(string) ($row['workflow_checksum'] ?? '')])) {
                throw new RuntimeException('Workflow event references an application or workflow outside the bundle.');
            }
        }
    }

    private function hasFieldContaining(array $payload, string $needle): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && stripos($key, $needle) !== false) {
                return true;
            }
            if (is_array($value) && $this->hasFieldContaining($value, $needle)) {
                return true;
            }
        }
        return false;
    }
}
