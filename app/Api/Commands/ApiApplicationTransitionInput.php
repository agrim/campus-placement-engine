<?php

declare(strict_types=1);

namespace App\Api\Commands;

/** Validated command transport values; the clear idempotency key is memory-only. */
final class ApiApplicationTransitionInput
{
    public function __construct(
        private readonly string $transitionKey,
        private readonly string $targetStatus,
        private readonly string $note,
        private readonly string $idempotencyKey,
        private readonly string $ifMatch,
    ) {
    }

    public function transitionKey(): string { return $this->transitionKey; }
    public function targetStatus(): string { return $this->targetStatus; }
    public function note(): string { return $this->note; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function ifMatch(): string { return $this->ifMatch; }

    /** @return array{if_match: string, note: string, target_status: string, transition_key: string} */
    public function fingerprintRequest(): array
    {
        return [
            'if_match' => $this->ifMatch,
            'note' => $this->note,
            'target_status' => $this->targetStatus,
            'transition_key' => $this->transitionKey,
        ];
    }
}
