<?php

declare(strict_types=1);

namespace App\Core\Events;

final class DomainEvent
{
    public function __construct(
        public readonly string $name,
        public readonly string $aggregateType,
        public readonly string $aggregatePublicId,
        public readonly string $moduleKey,
        public readonly array $payload,
        public readonly string $occurredAt,
    ) {
    }
}
