<?php

declare(strict_types=1);

namespace App\Modules\Placement\Workflow;

use App\Support\Database;
use PDO;
use RuntimeException;

final class WorkflowPublisher
{
    private WorkflowRepository $repository;
    private WorkflowDefinitionValidator $validator;

    public function __construct(private readonly PDO $pdo)
    {
        $this->repository = new WorkflowRepository($pdo);
        $this->validator = new WorkflowDefinitionValidator();
    }

    public function synchronize(): void
    {
        if (!$this->repository->hasSchema()) {
            return;
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $selectedKey = $this->repository->currentWorkflowKey();
            foreach (cpe_config('workflows', []) as $key => $template) {
                $definitionId = $this->ensureDefinition((string) $key, (string) ($template['name'] ?? $key), (string) $key);
                if ($this->versionCount($definitionId) === 0) {
                    $definition = $this->fromTemplate((string) $key, $template);
                    $hasOverrides = $key === $selectedKey && $this->applyLegacyOverrides($definition);
                    $this->insertPublishedVersion(
                        $definitionId,
                        $definition,
                        $hasOverrides ? 'legacy_import' : 'template',
                        null,
                        true
                    );
                }
            }

            $versionId = $this->repository->activeVersionId($selectedKey);
            if ($versionId === null) {
                $versionId = $this->repository->activeVersionId('default');
            }
            if ($versionId !== null) {
                $this->bindCurrentCycle($versionId);
                $this->repository->synchronizeApplicationInstances();
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function publish(string $workflowKey, array $definition, string $sourceType, ?int $actorId, bool $activate = true): int
    {
        if (!$this->repository->hasSchema()) {
            throw new RuntimeException('Versioned workflow storage is unavailable.');
        }
        $this->validator->assertValid($definition);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $definitionId = $this->ensureDefinition(
                $workflowKey,
                (string) $definition['name'],
                (string) ($definition['source_template_key'] ?? '')
            );
            $versionId = $this->insertPublishedVersion($definitionId, $definition, $sourceType, $actorId, $activate);
            if ($activate && $workflowKey === $this->repository->currentWorkflowKey()) {
                $this->bindCurrentCycle($versionId);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $versionId;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function publishCurrentEdits(array $statusInput, array $transitionInput, ?int $actorId): int
    {
        $workflow = $this->repository->workflowForCurrentCycle();
        if ($workflow === null) {
            throw new RuntimeException('No active workflow is available to edit.');
        }
        $definition = [
            'name' => $workflow['name'],
            'initial_state_key' => $workflow['initial_state_key'],
            'states' => $workflow['states'],
            'transitions' => $workflow['transitions'],
            'source_template_key' => $workflow['key'],
        ];

        foreach ($statusInput as $key => $row) {
            if (!isset($definition['states'][$key]) || !is_array($row)) {
                continue;
            }
            $label = preg_replace('/\s+/', ' ', trim(strip_tags((string) ($row['label'] ?? '')))) ?? '';
            $color = trim((string) ($row['color'] ?? ''));
            if ($label === '' || mb_strlen($label) > 80) {
                throw new RuntimeException('Workflow state labels must be between 1 and 80 characters.');
            }
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                throw new RuntimeException('Workflow state colors must use six-digit hex values.');
            }
            $definition['states'][$key]['label'] = $label;
            $definition['states'][$key]['color'] = strtolower($color);
        }

        foreach ($definition['transitions'] as &$transition) {
            if (!empty($transition['is_correction'])) {
                continue;
            }
            $rolesCsv = $transitionInput[$transition['from']][$transition['to']] ?? null;
            if ($rolesCsv === null) {
                continue;
            }
            $roles = array_values(array_unique(array_filter(array_map(
                fn (string $role): string => strtolower(trim($role)),
                explode(',', (string) $rolesCsv)
            ))));
            if ($roles === []) {
                throw new RuntimeException('Every workflow transition requires at least one role.');
            }
            foreach ($roles as $role) {
                if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $role) !== 1) {
                    throw new RuntimeException('Workflow transition contains an invalid role key: ' . $role);
                }
            }
            $transition['roles'] = $roles;
        }
        unset($transition);

        $versionId = $this->publish((string) $workflow['key'], $definition, 'admin', $actorId, true);
        $this->writeLegacyOverrides($definition);
        return $versionId;
    }

    public function publishCurrentForm(array $input, ?int $actorId): int
    {
        $workflow = $this->repository->workflowForCurrentCycle();
        if ($workflow === null) {
            throw new RuntimeException('No active workflow is available to edit.');
        }
        $states = [];
        foreach ((array) ($input['states'] ?? []) as $key => $row) {
            if (!is_array($row) || !empty($row['delete'])) {
                continue;
            }
            $states[(string) $key] = $this->stateFromInput((string) $key, $row);
        }
        $newState = (array) ($input['new_state'] ?? []);
        $newStateKey = strtolower(trim((string) ($newState['key'] ?? '')));
        if ($newStateKey !== '') {
            if (isset($states[$newStateKey])) {
                throw new RuntimeException('Workflow state key already exists: ' . $newStateKey);
            }
            $states[$newStateKey] = $this->stateFromInput($newStateKey, $newState);
        }

        $transitions = [];
        foreach ((array) ($input['transitions'] ?? []) as $key => $row) {
            if (!is_array($row) || !empty($row['delete'])) {
                continue;
            }
            $transitions[] = $this->transitionFromInput((string) $key, $row);
        }
        $newTransition = (array) ($input['new_transition'] ?? []);
        $newTransitionKey = strtolower(trim((string) ($newTransition['key'] ?? '')));
        if ($newTransitionKey !== '') {
            $transitions[] = $this->transitionFromInput($newTransitionKey, $newTransition);
        }

        $definition = [
            'name' => $this->cleanLabel((string) ($input['name'] ?? $workflow['name']), 120, 'Workflow name'),
            'source_template_key' => (string) $workflow['key'],
            'initial_state_key' => strtolower(trim((string) ($input['initial_state_key'] ?? $workflow['initial_state_key']))),
            'states' => $states,
            'transitions' => $transitions,
        ];
        $versionId = $this->publish((string) $workflow['key'], $definition, 'admin', $actorId, true);
        if ($this->isTemplateCompatible($definition)) {
            $this->writeLegacyOverrides($definition);
        } else {
            $this->disableLegacyMirror();
        }
        return $versionId;
    }

    public function publishPortableOverrides(
        string $workflowKey,
        array $statusRows,
        array $transitionRows,
        ?int $actorId = null
    ): int {
        $template = cpe_config('workflows.' . $workflowKey);
        if (is_array($template)) {
            $definition = $this->fromTemplate($workflowKey, $template);
        } else {
            $versionId = $this->repository->activeVersionId($workflowKey);
            if ($versionId === null) {
                throw new RuntimeException('Configuration references an unknown workflow: ' . $workflowKey);
            }
            $payload = (new WorkflowDefinitionFileService($this->pdo))->payloadForVersion($versionId);
            $definition = $payload['definition'];
        }
        foreach ($statusRows as $row) {
            $key = (string) ($row['status_key'] ?? '');
            if (!isset($definition['states'][$key])) {
                throw new RuntimeException('Workflow status override references unknown status: ' . $key);
            }
            $definition['states'][$key]['label'] = (string) $row['label'];
            $definition['states'][$key]['color'] = strtolower((string) $row['color']);
        }
        foreach ($transitionRows as $row) {
            $matched = false;
            foreach ($definition['transitions'] as &$transition) {
                if (!empty($transition['is_correction'])
                    || $transition['from'] !== (string) ($row['from_status'] ?? '')
                    || $transition['to'] !== (string) ($row['to_status'] ?? '')) {
                    continue;
                }
                $transition['roles'] = array_values(array_filter(array_map('trim', explode(',', (string) $row['roles_csv']))));
                $matched = true;
                break;
            }
            unset($transition);
            if (!$matched) {
                throw new RuntimeException('Workflow transition override references an unknown transition.');
            }
        }
        $versionId = $this->publish($workflowKey, $definition, 'import', $actorId, true);
        if ($this->isTemplateCompatible($definition)) {
            $this->writeLegacyOverrides($definition);
        } else {
            $this->disableLegacyMirror();
        }
        return $versionId;
    }

    public function synchronizeLegacyMirrorIfChanged(): void
    {
        if (!$this->repository->hasSchema()) {
            return;
        }
        $marker = $this->setting('workflow_legacy_mirror_checksum');
        if ($marker === '') {
            return;
        }
        $current = $this->legacyMirrorChecksum();
        if (hash_equals($marker, $current)) {
            return;
        }
        $statusRows = $this->pdo->query(
            'SELECT status_key, label, color FROM workflow_status_overrides ORDER BY status_key'
        )->fetchAll();
        $transitionRows = $this->pdo->query(
            'SELECT from_status, to_status, roles_csv FROM workflow_transition_overrides ORDER BY from_status, to_status'
        )->fetchAll();
        $this->publishPortableOverrides($this->repository->currentWorkflowKey(), $statusRows, $transitionRows);
    }

    public function fromTemplate(string $workflowKey, array $template): array
    {
        $states = [];
        $outgoing = [];
        foreach ($template['transitions'] ?? [] as $from => $targets) {
            foreach ($targets as $to => $_roles) {
                $outgoing[(string) $from][] = (string) $to;
            }
        }
        $orderedKeys = array_keys($template['statuses'] ?? []);
        usort($orderedKeys, fn (string $a, string $b): int => ((int) $template['statuses'][$a]['order']) <=> ((int) $template['statuses'][$b]['order']));
        $initial = (string) ($orderedKeys[0] ?? 'idle');

        foreach ($template['statuses'] ?? [] as $key => $state) {
            $states[(string) $key] = [
                'label' => (string) ($state['label'] ?? $key),
                'order' => (int) ($state['order'] ?? 0),
                'color' => strtolower((string) ($state['color'] ?? '#ffffff')),
                'semantic_category' => $this->semanticCategory((string) $key),
                'is_terminal' => empty($outgoing[(string) $key]),
            ];
        }

        $transitions = [];
        $order = 0;
        foreach ($template['transitions'] ?? [] as $from => $targets) {
            foreach ($targets as $to => $roles) {
                $transitions[] = [
                    'key' => $this->transitionKey('advance', (string) $from, (string) $to),
                    'label' => 'Move to ' . (string) ($states[$to]['label'] ?? $to),
                    'from' => (string) $from,
                    'to' => (string) $to,
                    'required_capability' => 'placement.application.transition',
                    'roles' => array_values(array_unique(array_map('strval', (array) $roles))),
                    'guards' => $this->guardsFor((string) $to),
                    'effects' => $this->effectsFor((string) $to),
                    'order' => $order++,
                    'is_correction' => false,
                ];
            }
        }

        foreach ($states as $key => $state) {
            if ($key === $initial || $state['is_terminal']) {
                continue;
            }
            $transitions[] = [
                'key' => $this->transitionKey('correct', $key, $initial),
                'label' => 'Return to ' . (string) $states[$initial]['label'],
                'from' => $key,
                'to' => $initial,
                'required_capability' => 'placement.application.correct',
                'roles' => ['admin', 'control', 'placement', 'company'],
                'guards' => ['correction.reason_required'],
                'effects' => ['application.set_state'],
                'order' => 10000 + $order++,
                'is_correction' => true,
            ];
        }

        $definition = [
            'name' => (string) ($template['name'] ?? $workflowKey),
            'source_template_key' => $workflowKey,
            'initial_state_key' => $initial,
            'states' => $states,
            'transitions' => $transitions,
        ];
        $this->validator->assertValid($definition);
        return $definition;
    }

    private function insertPublishedVersion(
        int $definitionId,
        array $definition,
        string $sourceType,
        ?int $actorId,
        bool $activate
    ): int {
        $this->validator->assertValid($definition);
        $canonical = $this->canonicalJson($definition);
        $checksum = hash('sha256', $canonical);
        $existing = $this->repository->versionIdByChecksum($definitionId, $checksum);
        if ($existing !== null) {
            if ($activate) {
                $this->activateVersion($definitionId, $existing, (string) $definition['name']);
            }
            return $existing;
        }

        $now = cpe_now();
        $version = $this->pdo->prepare(
            'INSERT INTO workflow_versions
             (public_id, workflow_definition_id, version_number, lifecycle_status, source_type, initial_state_key,
              definition_json, checksum, created_by, published_by, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $version->execute([
            $this->publicId('workflow_version'),
            $definitionId,
            $this->repository->nextVersionNumber($definitionId),
            'published',
            $sourceType,
            (string) $definition['initial_state_key'],
            $canonical,
            $checksum,
            $actorId,
            $actorId,
            $now,
            $now,
            $now,
        ]);
        $versionId = Database::lastInsertId($this->pdo);

        $stateStmt = $this->pdo->prepare(
            'INSERT INTO workflow_states
             (workflow_version_id, state_key, label, semantic_category, display_order, color, is_terminal)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($definition['states'] as $key => $state) {
            $stateStmt->execute([
                $versionId,
                $key,
                (string) $state['label'],
                (string) $state['semantic_category'],
                (int) $state['order'],
                (string) $state['color'],
                !empty($state['is_terminal']) ? 1 : 0,
            ]);
        }

        $transitionStmt = $this->pdo->prepare(
            'INSERT INTO workflow_transitions
             (workflow_version_id, transition_key, label, from_state_key, to_state_key, required_capability,
              roles_csv, guards_json, effects_json, display_order, is_correction)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($definition['transitions'] as $transition) {
            $transitionStmt->execute([
                $versionId,
                (string) $transition['key'],
                (string) $transition['label'],
                (string) $transition['from'],
                (string) $transition['to'],
                (string) ($transition['required_capability'] ?? 'placement.application.transition'),
                implode(',', $transition['roles']),
                json_encode(array_values($transition['guards'] ?? []), JSON_THROW_ON_ERROR),
                json_encode(array_values($transition['effects'] ?? []), JSON_THROW_ON_ERROR),
                (int) ($transition['order'] ?? 0),
                !empty($transition['is_correction']) ? 1 : 0,
            ]);
        }

        if ($activate) {
            $this->activateVersion($definitionId, $versionId, (string) $definition['name']);
        }
        return $versionId;
    }

    private function ensureDefinition(string $key, string $name, string $sourceTemplateKey): int
    {
        $existing = $this->repository->definitionId($key);
        if ($existing !== null) {
            return $existing;
        }
        $institutionId = $this->pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn();
        if ($institutionId === false) {
            throw new RuntimeException('Default institution is unavailable for workflow publication.');
        }
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO workflow_definitions
             (public_id, institution_id, workflow_key, name, description, source_template_key, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->publicId('workflow'), (int) $institutionId, $key, $name, '', $sourceTemplateKey, $now, $now]);
        return Database::lastInsertId($this->pdo);
    }

    private function activateVersion(int $definitionId, int $versionId, string $name): void
    {
        $stmt = $this->pdo->prepare('UPDATE workflow_definitions SET name = ?, active_version_id = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$name, $versionId, cpe_now(), $definitionId]);
    }

    private function bindCurrentCycle(int $versionId): void
    {
        $stmt = $this->pdo->prepare("UPDATE placement_cycles SET active_workflow_version_id = ?, updated_at = ? WHERE cycle_key = 'default'");
        $stmt->execute([$versionId, cpe_now()]);
    }

    private function versionCount(int $definitionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM workflow_versions WHERE workflow_definition_id = ?');
        $stmt->execute([$definitionId]);
        return (int) $stmt->fetchColumn();
    }

    private function applyLegacyOverrides(array &$definition): bool
    {
        $changed = false;
        try {
            foreach ($this->pdo->query('SELECT status_key, label, color FROM workflow_status_overrides')->fetchAll() as $row) {
                $key = (string) $row['status_key'];
                if (!isset($definition['states'][$key])) {
                    continue;
                }
                $definition['states'][$key]['label'] = (string) $row['label'];
                $definition['states'][$key]['color'] = (string) $row['color'];
                $changed = true;
            }
            foreach ($this->pdo->query('SELECT from_status, to_status, roles_csv FROM workflow_transition_overrides')->fetchAll() as $row) {
                foreach ($definition['transitions'] as &$transition) {
                    if (!empty($transition['is_correction'])
                        || $transition['from'] !== (string) $row['from_status']
                        || $transition['to'] !== (string) $row['to_status']) {
                        continue;
                    }
                    $roles = array_values(array_filter(array_map('trim', explode(',', (string) $row['roles_csv']))));
                    if ($roles !== []) {
                        $transition['roles'] = $roles;
                        $changed = true;
                    }
                }
                unset($transition);
            }
        } catch (\Throwable) {
            return false;
        }
        return $changed;
    }

    private function writeLegacyOverrides(array $definition): void
    {
        $templateKey = (string) ($definition['source_template_key'] ?? $this->repository->currentWorkflowKey());
        $template = cpe_config('workflows.' . $templateKey, []);
        $this->pdo->exec('DELETE FROM workflow_status_overrides');
        $status = $this->pdo->prepare('INSERT INTO workflow_status_overrides (status_key, label, color) VALUES (?, ?, ?)');
        foreach ($definition['states'] as $key => $state) {
            $base = $template['statuses'][$key] ?? null;
            if ($base !== null
                && (string) ($base['label'] ?? '') === (string) $state['label']
                && strtolower((string) ($base['color'] ?? '')) === strtolower((string) $state['color'])) {
                continue;
            }
            $status->execute([$key, (string) $state['label'], (string) $state['color']]);
        }
        $this->pdo->exec('DELETE FROM workflow_transition_overrides');
        $transitionStmt = $this->pdo->prepare('INSERT INTO workflow_transition_overrides (from_status, to_status, roles_csv) VALUES (?, ?, ?)');
        foreach ($definition['transitions'] as $transition) {
            if (!empty($transition['is_correction'])) {
                continue;
            }
            $baseRoles = $template['transitions'][$transition['from']][$transition['to']] ?? null;
            if (is_array($baseRoles) && array_values($baseRoles) === array_values($transition['roles'])) {
                continue;
            }
            $transitionStmt->execute([$transition['from'], $transition['to'], implode(',', $transition['roles'])]);
        }
        $marker = $this->pdo->prepare(
            "INSERT INTO settings (key, value) VALUES ('workflow_legacy_mirror_checksum', ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value"
        );
        $marker->execute([$this->legacyMirrorChecksum()]);
    }

    private function disableLegacyMirror(): void
    {
        $this->pdo->exec('DELETE FROM workflow_status_overrides');
        $this->pdo->exec('DELETE FROM workflow_transition_overrides');
        $stmt = $this->pdo->prepare("DELETE FROM settings WHERE key = 'workflow_legacy_mirror_checksum'");
        $stmt->execute();
    }

    private function isTemplateCompatible(array $definition): bool
    {
        $templateKey = (string) ($definition['source_template_key'] ?? '');
        $template = cpe_config('workflows.' . $templateKey);
        if (!is_array($template)) {
            return false;
        }
        $stateKeys = array_keys($definition['states']);
        $templateStateKeys = array_keys($template['statuses'] ?? []);
        sort($stateKeys);
        sort($templateStateKeys);
        if ($stateKeys !== $templateStateKeys) {
            return false;
        }
        $pairs = [];
        foreach ($definition['transitions'] as $transition) {
            if (empty($transition['is_correction'])) {
                $pairs[] = $transition['from'] . '>' . $transition['to'];
            }
        }
        $templatePairs = [];
        foreach ($template['transitions'] ?? [] as $from => $targets) {
            foreach (array_keys($targets) as $to) {
                $templatePairs[] = $from . '>' . $to;
            }
        }
        sort($pairs);
        sort($templatePairs);
        return $pairs === $templatePairs;
    }

    private function stateFromInput(string $key, array $row): array
    {
        return [
            'label' => $this->cleanLabel((string) ($row['label'] ?? ''), 80, 'State label for ' . $key),
            'order' => (int) ($row['order'] ?? 0),
            'color' => $this->color((string) ($row['color'] ?? '')),
            'semantic_category' => strtolower(trim((string) ($row['semantic_category'] ?? 'waiting'))),
            'is_terminal' => !empty($row['is_terminal']),
        ];
    }

    private function transitionFromInput(string $key, array $row): array
    {
        $correction = !empty($row['is_correction']);
        $roles = array_values(array_unique(array_filter(array_map(
            fn (string $role): string => strtolower(trim($role)),
            explode(',', (string) ($row['roles'] ?? ''))
        ))));
        return [
            'key' => strtolower(trim($key)),
            'label' => $this->cleanLabel((string) ($row['label'] ?? ''), 80, 'Transition label for ' . $key),
            'from' => strtolower(trim((string) ($row['from'] ?? ''))),
            'to' => strtolower(trim((string) ($row['to'] ?? ''))),
            'required_capability' => $correction ? 'placement.application.correct' : 'placement.application.transition',
            'roles' => $roles,
            'guards' => $this->vocabularySelection((array) ($row['guards'] ?? []), WorkflowDefinitionValidator::GUARDS),
            'effects' => $this->vocabularySelection((array) ($row['effects'] ?? []), WorkflowDefinitionValidator::EFFECTS),
            'order' => (int) ($row['order'] ?? 0),
            'is_correction' => $correction,
        ];
    }

    private function vocabularySelection(array $selected, array $allowed): array
    {
        $values = array_values(array_unique(array_map('strval', $selected)));
        foreach ($values as $value) {
            if (!in_array($value, $allowed, true)) {
                throw new RuntimeException('Unknown workflow rule vocabulary item: ' . $value);
            }
        }
        return $values;
    }

    private function cleanLabel(string $value, int $maxLength, string $field): string
    {
        $value = preg_replace('/\s+/', ' ', trim(strip_tags($value))) ?? '';
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new RuntimeException($field . ' must be between 1 and ' . $maxLength . ' characters.');
        }
        return $value;
    }

    private function color(string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^#[0-9a-f]{6}$/', $value)) {
            throw new RuntimeException('Workflow state colors must use six-digit hex values.');
        }
        return $value;
    }

    private function legacyMirrorChecksum(): string
    {
        $payload = [
            'states' => $this->pdo->query(
                'SELECT status_key, label, color FROM workflow_status_overrides ORDER BY status_key'
            )->fetchAll(),
            'transitions' => $this->pdo->query(
                'SELECT from_status, to_status, roles_csv FROM workflow_transition_overrides ORDER BY from_status, to_status'
            )->fetchAll(),
        ];
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function setting(string $key): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? '' : (string) $value;
    }

    private function semanticCategory(string $stateKey): string
    {
        return match ($stateKey) {
            'idle' => 'pending',
            'eligible' => 'eligible',
            'scheduled' => 'scheduled',
            'intransit' => 'in_transit',
            'inside', 'interview', 'in_interview', 'technical', 'hr', 'test', 'screening' => 'active',
            'offer', 'selected' => 'offered',
            'placed', 'accepted' => 'accepted',
            'rejected' => 'rejected',
            'withdrawn' => 'withdrawn',
            'cancelled' => 'cancelled',
            'sent', 'feedback', 'screened' => 'completed',
            'registered', 'applied', 'shortlisted', 'invited', 'link_sent' => 'queued',
            default => 'waiting',
        };
    }

    private function guardsFor(string $to): array
    {
        $guards = $to === 'idle' ? [] : ['candidate.not_opted_out'];
        if (in_array($to, ['placed', 'accepted'], true)) {
            $guards[] = 'placement.not_frozen_or_admin';
            $guards[] = 'offer.upgrade_allowed';
        }
        return $guards;
    }

    private function effectsFor(string $to): array
    {
        $effects = ['application.set_state'];
        if (in_array($to, ['intransit', 'arrived', 'requested', 'sendin', 'inside', 'exit', 'requestaway', 'sendaway'], true)) {
            $effects[] = 'presence.move_to_opportunity';
        }
        if ($to === 'sent') {
            $effects[] = 'presence.return_to_control';
            $effects[] = 'placement.start_next_scheduled';
        }
        if (in_array($to, ['placed', 'accepted'], true)) {
            $effects[] = 'placement.accept_offer';
            $effects[] = 'placement.clear_competing_applications';
        }
        return $effects;
    }

    private function transitionKey(string $prefix, string $from, string $to): string
    {
        return substr($prefix . '_' . $from . '_to_' . $to, 0, 80);
    }

    private function canonicalJson(array $definition): string
    {
        $value = $this->sortRecursively($definition);
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }
        return $value;
    }

    private function publicId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(16));
    }
}
