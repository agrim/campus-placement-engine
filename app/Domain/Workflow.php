<?php

declare(strict_types=1);

namespace App\Domain;

use App\Modules\Placement\Workflow\WorkflowDefinitionValidator;
use App\Modules\Placement\Workflow\WorkflowRepository;
use App\Support\Database;

final class Workflow
{
    private array $workflow;

    public function __construct(?array $workflow = null, ?string $key = null)
    {
        if ($workflow !== null) {
            $this->workflow = $workflow;
            return;
        }
        try {
            $repository = new WorkflowRepository(Database::connection());
            if ($repository->hasSchema()) {
                $published = $repository->workflowForCurrentCycle();
                if ($published !== null) {
                    $this->workflow = $this->legacyShape($published);
                    $this->applyOverrides();
                    return;
                }
            }
        } catch (\Throwable) {
        }
        $key ??= $this->currentKey();
        $this->workflow = cpe_config('workflows.' . $key, cpe_config('workflows.default'));
        $this->applyOverrides();
    }

    public static function available(): array
    {
        return cpe_config('workflows', []);
    }

    public function currentKey(): string
    {
        try {
            if (Database::isInstalled()) {
                $stmt = Database::connection()->query("SELECT value FROM settings WHERE key = 'workflow'");
                return (string) ($stmt->fetchColumn() ?: 'default');
            }
        } catch (\Throwable) {
        }
        return 'default';
    }

    public function name(): string
    {
        return $this->workflow['name'];
    }

    public function statuses(): array
    {
        $statuses = $this->workflow['statuses'];
        uasort($statuses, fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        return $statuses;
    }

    public function statusLabel(string $status): string
    {
        return $this->workflow['statuses'][$status]['label'] ?? $status;
    }

    public function statusColor(string $status): string
    {
        return $this->workflow['statuses'][$status]['color'] ?? '#ffffff';
    }

    public function nextStatus(string $status): ?string
    {
        foreach ($this->workflow['transition_definitions'] ?? [] as $transition) {
            if ($transition['from'] === $status && empty($transition['is_correction'])) {
                return (string) $transition['to'];
            }
        }
        $statuses = $this->statuses();
        $keys = array_keys($statuses);
        $index = array_search($status, $keys, true);
        if ($index === false || !isset($keys[$index + 1])) {
            return null;
        }
        return $keys[$index + 1];
    }

    public function canTransition(string $from, string $to, string $role): bool
    {
        $allowed = $this->workflow['transitions'][$from][$to] ?? [];
        return in_array($role, $allowed, true) || $role === 'admin';
    }

    public function validate(): array
    {
        if (isset($this->workflow['transition_definitions'])) {
            return (new WorkflowDefinitionValidator())->validate([
                'name' => $this->name(),
                'initial_state_key' => (string) ($this->workflow['initial_state_key'] ?? array_key_first($this->statuses())),
                'states' => $this->workflow['statuses'],
                'transitions' => $this->workflow['transition_definitions'],
            ]);
        }
        $errors = [];
        foreach ($this->workflow['transitions'] as $from => $targets) {
            if (!isset($this->workflow['statuses'][$from])) {
                $errors[] = "Transition starts from unknown status: {$from}";
            }
            foreach ($targets as $to => $roles) {
                if (!isset($this->workflow['statuses'][$to])) {
                    $errors[] = "Transition points to unknown status: {$to}";
                }
                if (!is_array($roles) || $roles === []) {
                    $errors[] = "Transition {$from} -> {$to} has no roles";
                }
            }
        }
        return $errors;
    }

    private function applyOverrides(): void
    {
        try {
            if (!Database::isInstalled()) {
                return;
            }
            $pdo = Database::connection();
            foreach ($pdo->query('SELECT status_key, label, color FROM workflow_status_overrides')->fetchAll() as $row) {
                if (isset($this->workflow['statuses'][$row['status_key']])) {
                    $this->workflow['statuses'][$row['status_key']]['label'] = $row['label'];
                    $this->workflow['statuses'][$row['status_key']]['color'] = $row['color'];
                }
            }
            foreach ($pdo->query('SELECT from_status, to_status, roles_csv FROM workflow_transition_overrides')->fetchAll() as $row) {
                if (isset($this->workflow['transitions'][$row['from_status']][$row['to_status']])) {
                    $roles = array_values(array_filter(array_map('trim', explode(',', $row['roles_csv']))));
                    if ($roles !== []) {
                        $this->workflow['transitions'][$row['from_status']][$row['to_status']] = $roles;
                    }
                }
            }
        } catch (\Throwable) {
        }
    }

    public function transitions(): array
    {
        return $this->workflow['transitions'];
    }

    public function transitionDefinitions(bool $includeCorrections = false): array
    {
        $definitions = $this->workflow['transition_definitions'] ?? [];
        return array_values(array_filter(
            $definitions,
            fn (array $transition): bool => $includeCorrections || empty($transition['is_correction'])
        ));
    }

    public function availableTransitions(string $from, string $role, bool $includeCorrections = false): array
    {
        return array_values(array_filter(
            $this->transitionDefinitions($includeCorrections),
            fn (array $transition): bool => $transition['from'] === $from
                && ($role === 'admin' || in_array($role, $transition['roles'], true))
        ));
    }

    public function versionNumber(): ?int
    {
        return isset($this->workflow['version_number']) ? (int) $this->workflow['version_number'] : null;
    }

    public function initialStateKey(): string
    {
        return (string) ($this->workflow['initial_state_key'] ?? array_key_first($this->statuses()) ?? 'idle');
    }

    public function isTerminal(string $stateKey): bool
    {
        $state = $this->statuses()[$stateKey] ?? null;
        if ($state === null) {
            return false;
        }
        if (array_key_exists('is_terminal', $state)) {
            return !empty($state['is_terminal']);
        }
        return $this->nextStatus($stateKey) === null;
    }

    private function legacyShape(array $published): array
    {
        $transitions = [];
        foreach ($published['transitions'] as $transition) {
            if (!empty($transition['is_correction'])) {
                continue;
            }
            $transitions[$transition['from']][$transition['to']] = $transition['roles'];
        }
        return [
            'name' => $published['name'],
            'statuses' => $published['states'],
            'transitions' => $transitions,
            'transition_definitions' => $published['transitions'],
            'initial_state_key' => $published['initial_state_key'],
            'version_id' => $published['version_id'],
            'version_number' => $published['version_number'],
        ];
    }
}
