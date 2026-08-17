<?php

declare(strict_types=1);

namespace App\Modules\Placement\Workflow;

use RuntimeException;

final class WorkflowDefinitionValidator
{
    public const SEMANTIC_CATEGORIES = [
        'pending',
        'eligible',
        'queued',
        'scheduled',
        'in_transit',
        'waiting',
        'active',
        'completed',
        'offered',
        'accepted',
        'rejected',
        'withdrawn',
        'cancelled',
    ];

    public const GUARDS = [
        'candidate.not_opted_out',
        'placement.not_frozen_or_admin',
        'offer.upgrade_allowed',
        'correction.reason_required',
    ];

    public const EFFECTS = [
        'application.set_state',
        'presence.move_to_opportunity',
        'presence.return_to_control',
        'placement.start_next_scheduled',
        'placement.accept_offer',
        'placement.clear_competing_applications',
    ];

    public function validate(array $definition): array
    {
        $errors = [];
        $states = $definition['states'] ?? [];
        $transitions = $definition['transitions'] ?? [];
        $initial = (string) ($definition['initial_state_key'] ?? '');

        if (trim((string) ($definition['name'] ?? '')) === '') {
            $errors[] = 'Workflow name is required.';
        }
        if (!is_array($states) || count($states) < 2) {
            $errors[] = 'Workflow requires at least two states.';
            $states = [];
        }
        if (!isset($states[$initial])) {
            $errors[] = 'Initial state does not exist: ' . $initial;
        }

        $terminalCount = 0;
        foreach ($states as $key => $state) {
            if (!$this->validKey((string) $key)) {
                $errors[] = 'Invalid state key: ' . $key;
            }
            if (trim((string) ($state['label'] ?? '')) === '') {
                $errors[] = 'State has no label: ' . $key;
            }
            $category = (string) ($state['semantic_category'] ?? '');
            if (!in_array($category, self::SEMANTIC_CATEGORIES, true)) {
                $errors[] = 'State has an unknown semantic category: ' . $key;
            }
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($state['color'] ?? ''))) {
                $errors[] = 'State has an invalid color: ' . $key;
            }
            if (!empty($state['is_terminal'])) {
                $terminalCount++;
            }
        }
        if ($terminalCount === 0) {
            $errors[] = 'Workflow requires at least one terminal state.';
        }

        $seenTransitionKeys = [];
        $reachableEdges = [];
        foreach (is_array($transitions) ? $transitions : [] as $transition) {
            $key = (string) ($transition['key'] ?? '');
            $from = (string) ($transition['from'] ?? '');
            $to = (string) ($transition['to'] ?? '');
            if (!$this->validKey($key)) {
                $errors[] = 'Invalid transition key: ' . $key;
            } elseif (isset($seenTransitionKeys[$key])) {
                $errors[] = 'Duplicate transition key: ' . $key;
            }
            $seenTransitionKeys[$key] = true;
            if (!isset($states[$from])) {
                $errors[] = 'Transition starts from unknown state: ' . $from;
            }
            if (!isset($states[$to])) {
                $errors[] = 'Transition points to unknown state: ' . $to;
            }
            $roles = $transition['roles'] ?? [];
            if (!is_array($roles) || $roles === []) {
                $errors[] = 'Transition has no allowed roles: ' . $key;
            }
            foreach ($transition['guards'] ?? [] as $guard) {
                if (!in_array($guard, self::GUARDS, true)) {
                    $errors[] = 'Transition uses an unknown guard: ' . $key . ' / ' . $guard;
                }
            }
            foreach ($transition['effects'] ?? [] as $effect) {
                if (!in_array($effect, self::EFFECTS, true)) {
                    $errors[] = 'Transition uses an unknown effect: ' . $key . ' / ' . $effect;
                }
            }
            if (empty($transition['is_correction'])) {
                $reachableEdges[$from][] = $to;
                if (!empty($states[$from]['is_terminal'])) {
                    $errors[] = 'Terminal state has an outgoing standard transition: ' . $from;
                }
            }
        }

        if (isset($states[$initial])) {
            $reachable = [$initial => true];
            $queue = [$initial];
            while ($queue !== []) {
                $from = array_shift($queue);
                foreach ($reachableEdges[$from] ?? [] as $to) {
                    if (!isset($reachable[$to])) {
                        $reachable[$to] = true;
                        $queue[] = $to;
                    }
                }
            }
            foreach (array_keys($states) as $stateKey) {
                if (!isset($reachable[$stateKey])) {
                    $errors[] = 'State is unreachable from the initial state: ' . $stateKey;
                }
            }
        }

        return array_values(array_unique($errors));
    }

    public function assertValid(array $definition): void
    {
        $errors = $this->validate($definition);
        if ($errors !== []) {
            throw new RuntimeException('Invalid workflow definition: ' . implode(' ', $errors));
        }
    }

    private function validKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,79}$/', $key) === 1;
    }
}
