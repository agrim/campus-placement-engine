<?php

declare(strict_types=1);

namespace App\Modules\Placement\Application;

/**
 * Immutable snapshot of the authenticated browser user at the transport edge.
 *
 * ApplicationTransitionService must match this snapshot against durable user
 * state again inside the transition transaction before it delegates a write.
 */
final class ApplicationTransitionActor
{
    private function __construct(
        private readonly int $userId,
        private readonly string $role,
        private readonly string $scopeType,
        private readonly string $scopeValue,
        private readonly bool $active,
    ) {
    }

    /** @param array<string, mixed> $user */
    public static function fromAuthenticatedUser(array $user): self
    {
        return new self(
            (int) ($user['id'] ?? 0),
            (string) ($user['role'] ?? ''),
            (string) ($user['scope_type'] ?? ''),
            (string) ($user['scope_value'] ?? ''),
            in_array($user['active'] ?? null, [true, 1, '1'], true),
        );
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function scopeType(): string
    {
        return $this->scopeType;
    }

    public function scopeValue(): string
    {
        return $this->scopeValue;
    }

    public function active(): bool
    {
        return $this->active;
    }
}
