<?php

declare(strict_types=1);

namespace App\Modules\Placement\Workflow;

use PDO;
use RuntimeException;

final class WorkflowSimulationService
{
    private WorkflowRepository $repository;

    public function __construct(PDO $pdo)
    {
        $this->repository = new WorkflowRepository($pdo);
    }

    public function simulate(int $versionId, array $steps, array $context = []): array
    {
        $workflow = $this->repository->workflowForVersion($versionId);
        if ($workflow === null) {
            throw new RuntimeException('Workflow version not found.');
        }
        $state = (string) ($context['start_state'] ?? $workflow['initial_state_key']);
        if (!isset($workflow['states'][$state])) {
            throw new RuntimeException('Simulation starts from an unknown state: ' . $state);
        }

        $trace = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                throw new RuntimeException('Simulation steps must be objects.');
            }
            $transitionKey = (string) ($step['transition_key'] ?? '');
            $actorRole = (string) ($step['actor_role'] ?? 'admin');
            $reason = trim((string) ($step['reason'] ?? ''));
            $transition = $this->transition($workflow['transitions'], $transitionKey);
            if ($transition['from'] !== $state) {
                throw new RuntimeException("Simulation step {$index} cannot use {$transitionKey} from {$state}.");
            }
            if ($actorRole !== 'admin' && !in_array($actorRole, $transition['roles'], true)) {
                throw new RuntimeException("Simulation role {$actorRole} cannot use {$transitionKey}.");
            }
            $this->assertGuards($transition['guards'], $actorRole, $reason, $context);
            $from = $state;
            $state = (string) $transition['to'];
            if (in_array('placement.accept_offer', $transition['effects'], true)) {
                $context['has_offer'] = true;
            }
            $trace[] = [
                'step' => $index + 1,
                'transition_key' => $transitionKey,
                'actor_role' => $actorRole,
                'from' => $from,
                'to' => $state,
                'effects' => $transition['effects'],
            ];
        }

        return [
            'workflow_key' => $workflow['key'],
            'version_id' => $workflow['version_id'],
            'version_number' => $workflow['version_number'],
            'start_state' => (string) ($context['start_state'] ?? $workflow['initial_state_key']),
            'end_state' => $state,
            'terminal' => !empty($workflow['states'][$state]['is_terminal']),
            'trace' => $trace,
        ];
    }

    public function explore(int $versionId, int $maxDepth = 30): array
    {
        $workflow = $this->repository->workflowForVersion($versionId);
        if ($workflow === null) {
            throw new RuntimeException('Workflow version not found.');
        }
        $maxDepth = max(1, min(100, $maxDepth));
        $paths = [];
        $deadEnds = [];
        $cycles = [];
        $this->walk(
            $workflow,
            (string) $workflow['initial_state_key'],
            [],
            [],
            $maxDepth,
            $paths,
            $deadEnds,
            $cycles
        );
        return [
            'workflow_key' => $workflow['key'],
            'version_id' => $workflow['version_id'],
            'terminal_paths' => $paths,
            'dead_ends' => array_values(array_unique($deadEnds)),
            'cycles' => array_values(array_unique($cycles)),
        ];
    }

    private function walk(
        array $workflow,
        string $state,
        array $path,
        array $visited,
        int $remaining,
        array &$paths,
        array &$deadEnds,
        array &$cycles
    ): void {
        if (!empty($workflow['states'][$state]['is_terminal'])) {
            $paths[] = [...$path, $state];
            return;
        }
        if ($remaining === 0) {
            $cycles[] = implode(' -> ', [...$path, $state]);
            return;
        }
        if (isset($visited[$state])) {
            $cycles[] = implode(' -> ', [...$path, $state]);
            return;
        }
        $outgoing = array_values(array_filter(
            $workflow['transitions'],
            fn (array $transition): bool => $transition['from'] === $state && empty($transition['is_correction'])
        ));
        if ($outgoing === []) {
            $deadEnds[] = $state;
            return;
        }
        $visited[$state] = true;
        foreach ($outgoing as $transition) {
            $this->walk(
                $workflow,
                (string) $transition['to'],
                [...$path, $state],
                $visited,
                $remaining - 1,
                $paths,
                $deadEnds,
                $cycles
            );
        }
    }

    private function transition(array $transitions, string $key): array
    {
        foreach ($transitions as $transition) {
            if ($transition['key'] === $key) {
                return $transition;
            }
        }
        throw new RuntimeException('Simulation references an unknown transition: ' . $key);
    }

    private function assertGuards(array $guards, string $actorRole, string $reason, array $context): void
    {
        foreach ($guards as $guard) {
            $allowed = match ($guard) {
                'candidate.not_opted_out' => empty($context['opted_out']),
                'placement.not_frozen_or_admin' => empty($context['placement_frozen']) || $actorRole === 'admin',
                'offer.upgrade_allowed' => empty($context['has_offer']) || !empty($context['allow_offer_upgrade']),
                'correction.reason_required' => $reason !== '',
                default => false,
            };
            if (!$allowed) {
                throw new RuntimeException('Simulation guard blocked transition: ' . $guard);
            }
        }
    }
}
