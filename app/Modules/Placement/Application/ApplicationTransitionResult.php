<?php

declare(strict_types=1);

namespace App\Modules\Placement\Application;

use RuntimeException;

final class ApplicationTransitionResult
{
    private function __construct(
        private readonly bool $duplicate,
        private readonly string $status,
    ) {
    }

    /** @param array<string, mixed> $result */
    public static function fromLegacyResult(array $result): self
    {
        if (!is_bool($result['duplicate'] ?? null) || !is_string($result['status'] ?? null)) {
            throw new RuntimeException('Application transition returned an invalid result.');
        }
        return new self($result['duplicate'], $result['status']);
    }

    public function duplicate(): bool
    {
        return $this->duplicate;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return array{duplicate: bool, status: string} */
    public function toArray(): array
    {
        return ['duplicate' => $this->duplicate, 'status' => $this->status];
    }
}
