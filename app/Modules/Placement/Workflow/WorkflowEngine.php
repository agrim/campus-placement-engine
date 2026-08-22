<?php

declare(strict_types=1);

namespace App\Modules\Placement\Workflow;

use PDO;
use RuntimeException;

final class WorkflowEngine
{
    private WorkflowRepository $repository;
    private WorkflowGuardEvaluator $guards;

    public function __construct(private readonly PDO $pdo)
    {
        $this->repository = new WorkflowRepository($pdo);
        $this->guards = new WorkflowGuardEvaluator($pdo);
    }

    public function availableTransitions(int $applicationId, string $actorRole, bool $includeCorrections = false, string $reason = ''): array
    {
        $from = $this->currentState($applicationId);
        return array_values(array_filter(
            $this->repository->transitionsForApplication($applicationId, $from, $includeCorrections),
            fn (array $transition): bool => $this->roleAllows($transition, $actorRole)
                && $this->guards->allows($transition, $applicationId, $actorRole, $reason)
        ));
    }

    public function preferredTransition(int $applicationId): ?array
    {
        $from = $this->currentState($applicationId);
        $transitions = $this->repository->transitionsForApplication($applicationId, $from, false);
        return $transitions[0] ?? null;
    }

    public function resolveTarget(
        int $applicationId,
        string $fromState,
        string $toState,
        string $actorRole,
        bool $correction = false,
        string $reason = ''
    ): array {
        $transitions = $this->repository->transitionsForApplication($applicationId, $fromState, $correction);
        foreach ($transitions as $transition) {
            if ((string) $transition['to'] !== $toState || (bool) $transition['is_correction'] !== $correction) {
                continue;
            }
            if (!$this->roleAllows($transition, $actorRole)) {
                break;
            }
            $this->guards->assertAllowed($transition, $applicationId, $actorRole, $reason);
            return $transition;
        }
        throw new RuntimeException("Role {$actorRole} cannot move {$fromState} to {$toState}.");
    }

    public function resolveKey(
        int $applicationId,
        string $transitionKey,
        string $actorRole,
        string $reason = ''
    ): array {
        $from = $this->currentState($applicationId);
        foreach ($this->repository->transitionsForApplication($applicationId, $from, true) as $transition) {
            if ((string) $transition['key'] !== $transitionKey) {
                continue;
            }
            if (!$this->roleAllows($transition, $actorRole)) {
                break;
            }
            $this->guards->assertAllowed($transition, $applicationId, $actorRole, $reason);
            return $transition;
        }
        throw new RuntimeException('Workflow transition is unavailable: ' . $transitionKey);
    }

    public function transitionForEffect(int $applicationId, string $fromState, string $toState, bool $correction = false): array
    {
        foreach ($this->repository->transitionsForApplication($applicationId, $fromState, $correction) as $transition) {
            if ((string) $transition['to'] === $toState && (bool) $transition['is_correction'] === $correction) {
                return $transition;
            }
        }
        throw new RuntimeException("Pinned workflow has no {$fromState} to {$toState} transition.");
    }

    public function resolveCorrection(int $applicationId, string $actorRole, string $reason): array
    {
        $from = $this->currentState($applicationId);
        foreach ($this->repository->transitionsForApplication($applicationId, $from, true) as $transition) {
            if (empty($transition['is_correction'])) {
                continue;
            }
            if (!$this->roleAllows($transition, $actorRole)) {
                break;
            }
            $this->guards->assertAllowed($transition, $applicationId, $actorRole, $reason);
            return $transition;
        }
        throw new RuntimeException("Role {$actorRole} cannot correct an application from {$from}.");
    }

    public function recordAppliedTransition(
        int $applicationId,
        array $transition,
        ?int $actorId,
        string $actorRole,
        string $reason,
        string $note,
        array $context = []
    ): void {
        $this->repository->recordTransition(
            $applicationId,
            $transition,
            $actorId,
            $actorRole,
            $reason,
            $note,
            $context
        );
    }

    public function ensureApplication(int $applicationId): int
    {
        return $this->repository->ensureApplicationInstance($applicationId);
    }

    private function currentState(int $applicationId): string
    {
        $stmt = $this->pdo->prepare('SELECT current_status FROM applications WHERE id = ?');
        $stmt->execute([$applicationId]);
        $state = $stmt->fetchColumn();
        if ($state === false) {
            throw new RuntimeException('Application not found.');
        }
        return (string) $state;
    }

    private function roleAllows(array $transition, string $actorRole): bool
    {
        return $actorRole === 'admin' || in_array($actorRole, $transition['roles'] ?? [], true);
    }
}
