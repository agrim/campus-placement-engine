<?php

declare(strict_types=1);

namespace App\Modules\Placement\Workflow;

use App\Support\Auth;
use PDO;
use RuntimeException;

final class WorkflowMigrationService
{
    private WorkflowRepository $repository;

    public function __construct(private readonly PDO $pdo)
    {
        $this->repository = new WorkflowRepository($pdo);
    }

    public function preview(int $targetVersionId, array $stateMap = []): array
    {
        $target = $this->target($targetVersionId);
        $rows = $this->applicationsOutsideVersion($targetVersionId);
        $counts = [];
        $unmapped = [];
        foreach ($rows as $row) {
            $from = (string) $row['current_status'];
            $to = (string) ($stateMap[$from] ?? $from);
            $counts[$from] = ($counts[$from] ?? 0) + 1;
            if (!isset($target['states'][$to])) {
                $unmapped[$from] = true;
            }
        }
        ksort($counts);
        return [
            'target_version_id' => $targetVersionId,
            'target_version_number' => $target['version_number'],
            'applications' => count($rows),
            'status_counts' => $counts,
            'unmapped_states' => array_keys($unmapped),
        ];
    }

    public function migrate(
        int $targetVersionId,
        array $stateMap,
        ?int $actorId,
        string $actorRole,
        string $reason
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Workflow version migration requires a reason.');
        }
        $preview = $this->preview($targetVersionId, $stateMap);
        if ($preview['unmapped_states'] !== []) {
            throw new RuntimeException('Workflow migration has unmapped states: ' . implode(', ', $preview['unmapped_states']));
        }
        $target = $this->target($targetVersionId);
        $rows = $this->applicationsOutsideVersion($targetVersionId);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $applicationUpdate = $this->pdo->prepare(
                'UPDATE applications SET current_status = ?, workflow_version_id = ?, updated_at = ? WHERE id = ?'
            );
            $instanceUpdate = $this->pdo->prepare(
                'UPDATE workflow_instances
                 SET workflow_version_id = ?, current_state_key = ?, completed_at = ?, updated_at = ?
                 WHERE id = ?'
            );
            $eventInsert = $this->pdo->prepare(
                'INSERT INTO workflow_transition_events
                 (public_id, workflow_instance_id, application_id, workflow_version_id, workflow_transition_id,
                  transition_key, from_state_key, to_state_key, actor_user_id, actor_role, reason, note, context_json, occurred_at)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $legacyEvent = $this->pdo->prepare(
                'INSERT INTO events
                 (application_id, candidate_id, company_id, from_status, to_status, actor_user_id, actor_role, note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $now = cpe_now();
            foreach ($rows as $row) {
                $applicationId = (int) $row['id'];
                $instanceId = $this->repository->ensureApplicationInstance($applicationId);
                $from = (string) $row['current_status'];
                $to = (string) ($stateMap[$from] ?? $from);
                $terminal = !empty($target['states'][$to]['is_terminal']);
                $applicationUpdate->execute([$to, $targetVersionId, $now, $applicationId]);
                $instanceUpdate->execute([$targetVersionId, $to, $terminal ? $now : null, $now, $instanceId]);
                $note = 'Workflow version migration: ' . $reason;
                $eventInsert->execute([
                    $this->publicId('workflow_event'),
                    $instanceId,
                    $applicationId,
                    $targetVersionId,
                    'workflow_version_migration',
                    $from,
                    $to,
                    $actorId,
                    $actorRole,
                    $reason,
                    $note,
                    json_encode(['previous_version_id' => (int) $row['workflow_version_id']], JSON_THROW_ON_ERROR),
                    $now,
                ]);
                if ($from !== $to) {
                    $legacyEvent->execute([
                        $applicationId,
                        (int) $row['candidate_id'],
                        (int) $row['company_id'],
                        $from,
                        $to,
                        $actorId,
                        $actorRole,
                        $note,
                        $now,
                    ]);
                }
            }
            Auth::audit($actorId, 'workflow.instances.migrate', 'workflow_version', $targetVersionId, 'Migrated ' . count($rows) . ' application(s): ' . $reason);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return [...$preview, 'migrated' => count($rows)];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function target(int $versionId): array
    {
        $workflow = $this->repository->workflowForVersion($versionId);
        if ($workflow === null || $workflow['lifecycle_status'] !== 'published') {
            throw new RuntimeException('Target workflow version must be published.');
        }
        return $workflow;
    }

    private function applicationsOutsideVersion(int $targetVersionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, candidate_id, company_id, current_status, workflow_version_id
             FROM applications
             WHERE workflow_version_id IS NULL OR workflow_version_id != ?
             ORDER BY id'
        );
        $stmt->execute([$targetVersionId]);
        return $stmt->fetchAll();
    }

    private function publicId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(16));
    }
}
