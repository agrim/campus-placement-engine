<?php

declare(strict_types=1);

namespace App\Modules\Placement\Workflow;

use App\Core\Http\UserVisibleException;
use App\Support\Database;
use PDO;
use RuntimeException;

final class WorkflowRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hasSchema(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM workflow_definitions LIMIT 1');
            $this->pdo->query('SELECT 1 FROM workflow_instances LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function currentWorkflowKey(): string
    {
        try {
            $value = $this->pdo->query("SELECT value FROM settings WHERE key = 'workflow'")->fetchColumn();
            return trim((string) ($value ?: 'default')) ?: 'default';
        } catch (\Throwable) {
            return 'default';
        }
    }

    public function definitionId(string $workflowKey): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT wd.id
             FROM workflow_definitions wd
             JOIN institutions i ON i.id = wd.institution_id
             WHERE i.slug = 'default' AND wd.workflow_key = ?"
        );
        $stmt->execute([$workflowKey]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function activeVersionId(?string $workflowKey = null): ?int
    {
        $workflowKey ??= $this->currentWorkflowKey();
        $stmt = $this->pdo->prepare(
            "SELECT wd.active_version_id
             FROM workflow_definitions wd
             JOIN institutions i ON i.id = wd.institution_id
             WHERE i.slug = 'default' AND wd.workflow_key = ?"
        );
        $stmt->execute([$workflowKey]);
        $id = $stmt->fetchColumn();
        return $id === false || $id === null ? null : (int) $id;
    }

    public function workflowForCurrentCycle(): ?array
    {
        $versionId = null;
        try {
            $value = $this->pdo->query("SELECT active_workflow_version_id FROM placement_cycles WHERE cycle_key = 'default'")->fetchColumn();
            if ($value !== false && $value !== null) {
                $versionId = (int) $value;
            }
        } catch (\Throwable) {
        }
        $versionId ??= $this->activeVersionId();
        return $versionId === null ? null : $this->workflowForVersion($versionId);
    }

    public function workflowForApplication(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT workflow_version_id FROM applications WHERE id = ?');
        $stmt->execute([$applicationId]);
        $versionId = $stmt->fetchColumn();
        if ($versionId === false) {
            throw new UserVisibleException('WORKFLOW_APPLICATION_NOT_FOUND', 'Application not found.');
        }
        if ($versionId === null) {
            $this->ensureApplicationInstance($applicationId);
            $stmt->execute([$applicationId]);
            $versionId = $stmt->fetchColumn();
        }
        return $versionId === null || $versionId === false ? null : $this->workflowForVersion((int) $versionId);
    }

    public function workflowForVersion(int $versionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT wv.*, wd.workflow_key, wd.name
             FROM workflow_versions wv
             JOIN workflow_definitions wd ON wd.id = wv.workflow_definition_id
             WHERE wv.id = ?'
        );
        $stmt->execute([$versionId]);
        $version = $stmt->fetch();
        if (!$version) {
            return null;
        }

        $stateStmt = $this->pdo->prepare(
            'SELECT state_key, label, semantic_category, display_order, color, is_terminal
             FROM workflow_states WHERE workflow_version_id = ? ORDER BY display_order, id'
        );
        $stateStmt->execute([$versionId]);
        $states = [];
        foreach ($stateStmt->fetchAll() as $state) {
            $states[(string) $state['state_key']] = [
                'label' => (string) $state['label'],
                'order' => (int) $state['display_order'],
                'color' => (string) $state['color'],
                'semantic_category' => (string) $state['semantic_category'],
                'is_terminal' => (bool) $state['is_terminal'],
            ];
        }

        $transitionStmt = $this->pdo->prepare(
            'SELECT * FROM workflow_transitions
             WHERE workflow_version_id = ? ORDER BY display_order, id'
        );
        $transitionStmt->execute([$versionId]);
        $transitions = [];
        foreach ($transitionStmt->fetchAll() as $transition) {
            $transitions[] = $this->normalizeTransitionRow($transition);
        }

        return [
            'key' => (string) $version['workflow_key'],
            'name' => (string) $version['name'],
            'version_id' => (int) $version['id'],
            'version_number' => (int) $version['version_number'],
            'public_id' => (string) $version['public_id'],
            'lifecycle_status' => (string) $version['lifecycle_status'],
            'initial_state_key' => (string) $version['initial_state_key'],
            'states' => $states,
            'transitions' => $transitions,
            'checksum' => (string) $version['checksum'],
        ];
    }

    public function transitionsForApplication(int $applicationId, string $fromState, bool $includeCorrections = false): array
    {
        $workflow = $this->workflowForApplication($applicationId);
        if ($workflow === null) {
            return [];
        }
        return array_values(array_filter(
            $workflow['transitions'],
            fn (array $transition): bool => $transition['from'] === $fromState
                && ($includeCorrections || !$transition['is_correction'])
        ));
    }

    public function ensureApplicationInstance(int $applicationId): int
    {
        $stmt = $this->pdo->prepare('SELECT id, current_status, workflow_version_id, created_at, updated_at FROM applications WHERE id = ?');
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch();
        if (!$application) {
            throw new UserVisibleException('WORKFLOW_APPLICATION_NOT_FOUND', 'Application not found.');
        }

        $versionId = (int) ($application['workflow_version_id'] ?? 0);
        if ($versionId <= 0) {
            $versionId = (int) ($this->activeVersionId() ?? 0);
            if ($versionId <= 0) {
                throw new RuntimeException('No published workflow version is active.');
            }
            $workflow = $this->workflowForVersion($versionId);
            if ($workflow === null || !isset($workflow['states'][(string) $application['current_status']])) {
                throw new RuntimeException('Application status is not present in the active workflow: ' . $application['current_status']);
            }
            $update = $this->pdo->prepare('UPDATE applications SET workflow_version_id = ? WHERE id = ?');
            $update->execute([$versionId, $applicationId]);
        }

        $find = $this->pdo->prepare('SELECT id, current_state_key FROM workflow_instances WHERE application_id = ?');
        $find->execute([$applicationId]);
        $instance = $find->fetch();
        if ($instance) {
            if ((string) $instance['current_state_key'] !== (string) $application['current_status']) {
                $sync = $this->pdo->prepare('UPDATE workflow_instances SET current_state_key = ?, updated_at = ? WHERE id = ?');
                $sync->execute([(string) $application['current_status'], (string) $application['updated_at'], (int) $instance['id']]);
            }
            return (int) $instance['id'];
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO workflow_instances (public_id, application_id, workflow_version_id, current_state_key, started_at, completed_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $workflow = $this->workflowForVersion($versionId);
        $state = $workflow['states'][(string) $application['current_status']] ?? null;
        $completedAt = !empty($state['is_terminal']) ? (string) $application['updated_at'] : null;
        $insert->execute([
            $this->publicId('workflow_instance'),
            $applicationId,
            $versionId,
            (string) $application['current_status'],
            (string) $application['created_at'],
            $completedAt,
            (string) $application['updated_at'],
        ]);
        return Database::lastInsertId($this->pdo);
    }

    public function synchronizeApplicationInstances(): void
    {
        foreach ($this->pdo->query('SELECT id FROM applications ORDER BY id')->fetchAll() as $row) {
            $this->ensureApplicationInstance((int) $row['id']);
        }
    }

    public function recordTransition(
        int $applicationId,
        array $transition,
        ?int $actorId,
        string $actorRole,
        string $reason,
        string $note,
        array $context = []
    ): void {
        $instanceId = $this->ensureApplicationInstance($applicationId);
        $workflow = $this->workflowForApplication($applicationId);
        if ($workflow === null) {
            throw new RuntimeException('Application workflow is unavailable.');
        }
        $toState = (string) $transition['to'];
        $state = $workflow['states'][$toState] ?? null;
        if ($state === null) {
            throw new UserVisibleException('WORKFLOW_TRANSITION_INVALID', 'Transition target is not in the pinned workflow.');
        }
        $now = cpe_now();
        $update = $this->pdo->prepare(
            'UPDATE workflow_instances
             SET current_state_key = ?, completed_at = ?, updated_at = ? WHERE id = ?'
        );
        $update->execute([$toState, !empty($state['is_terminal']) ? $now : null, $now, $instanceId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO workflow_transition_events
             (public_id, workflow_instance_id, application_id, workflow_version_id, workflow_transition_id,
              transition_key, from_state_key, to_state_key, actor_user_id, actor_role, reason, note, context_json, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $this->publicId('workflow_event'),
            $instanceId,
            $applicationId,
            (int) $workflow['version_id'],
            (int) ($transition['id'] ?? 0) ?: null,
            (string) $transition['key'],
            (string) $transition['from'],
            $toState,
            $actorId,
            $actorRole,
            $reason,
            $note,
            json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $now,
        ]);
    }

    public function nextVersionNumber(int $definitionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM workflow_versions WHERE workflow_definition_id = ?');
        $stmt->execute([$definitionId]);
        return (int) $stmt->fetchColumn();
    }

    public function versionIdByChecksum(int $definitionId, string $checksum): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM workflow_versions WHERE workflow_definition_id = ? AND checksum = ?');
        $stmt->execute([$definitionId, $checksum]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function versions(): array
    {
        return $this->pdo->query(
            "SELECT wv.id, wv.public_id, wd.workflow_key, wd.name, wv.version_number,
                    wv.lifecycle_status, wv.source_type, wv.checksum, wv.published_at,
                    CASE WHEN wd.active_version_id = wv.id THEN 1 ELSE 0 END AS is_active,
                    (SELECT COUNT(*) FROM applications a WHERE a.workflow_version_id = wv.id) AS application_count
             FROM workflow_versions wv
             JOIN workflow_definitions wd ON wd.id = wv.workflow_definition_id
             ORDER BY wd.workflow_key, wv.version_number DESC"
        )->fetchAll();
    }

    private function normalizeTransitionRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['transition_key'],
            'label' => (string) $row['label'],
            'from' => (string) $row['from_state_key'],
            'to' => (string) $row['to_state_key'],
            'required_capability' => (string) $row['required_capability'],
            'roles' => array_values(array_filter(array_map('trim', explode(',', (string) $row['roles_csv'])))),
            'guards' => $this->decodeList((string) $row['guards_json']),
            'effects' => $this->decodeList((string) $row['effects_json']),
            'order' => (int) $row['display_order'],
            'is_correction' => (bool) $row['is_correction'],
        ];
    }

    private function decodeList(string $json): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($value) ? array_values($value) : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function publicId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(16));
    }
}
