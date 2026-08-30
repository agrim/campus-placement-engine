<?php

declare(strict_types=1);

namespace App\Core\Events;

use Closure;
use RuntimeException;

/**
 * Stable identity and callback for one source-bundled post-commit observer.
 *
 * @internal External consumers use the versioned public event contract.
 */
final class InternalEventSubscription
{
    private readonly Closure $listener;

    public function __construct(
        private readonly string $id,
        private readonly string $eventName,
        private readonly string $moduleKey,
        callable $listener,
    ) {
        if (!self::isValidId($id)) {
            throw new RuntimeException('Internal event subscription ID must be stable, namespaced, and versioned.');
        }
        if (preg_match('/^[a-z][a-z0-9_.]{2,127}$/D', $eventName) !== 1) {
            throw new RuntimeException('Internal event subscription has an invalid event name.');
        }
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $moduleKey) !== 1
            || !str_starts_with($id, 'internal.' . $moduleKey . '.')) {
            throw new RuntimeException('Internal event subscription has an invalid or mismatched module identity.');
        }
        $this->listener = Closure::fromCallable($listener);
    }

    public function id(): string
    {
        return $this->id;
    }

    public static function isValidId(string $id): bool
    {
        return strlen($id) <= 191
            && preg_match('/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9_]{0,63}){2,}\.v[1-9][0-9]{0,5}$/D', $id) === 1;
    }

    public function eventName(): string
    {
        return $this->eventName;
    }

    public function moduleKey(): string
    {
        return $this->moduleKey;
    }

    public function invoke(DomainEvent $event): void
    {
        if ($event->name !== $this->eventName) {
            throw new RuntimeException('Internal event subscription cannot observe a different event name.');
        }
        ($this->listener)($event);
    }
}
