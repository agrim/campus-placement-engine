<?php

declare(strict_types=1);

namespace App\Modules\Placement\Application;

/**
 * Browser-neutral input for one ordinary application workflow transition.
 * Corrections intentionally remain outside this Phase 4A boundary.
 */
final class ApplicationTransitionCommand
{
    public function __construct(
        private readonly int $applicationId,
        private readonly string $toStatus,
        private readonly string $transitionKey,
        private readonly string $note,
        private readonly string $expectedFromStatus,
        private readonly string $idempotencyKey,
    ) {
    }

    public function applicationId(): int
    {
        return $this->applicationId;
    }

    public function toStatus(): string
    {
        return $this->toStatus;
    }

    public function transitionKey(): string
    {
        return $this->transitionKey;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function expectedFromStatus(): string
    {
        return $this->expectedFromStatus;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
}
