<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Modules\ModuleManager;
use App\Core\Modules\ProvidesEventSubscribers;
use RuntimeException;

/** @internal Source-bundled observer registry, not a public extension surface. */
final class InternalEventSubscriberRegistry
{
    /** @var array<string, InternalEventSubscription> */
    private array $subscriptions = [];

    /** @param list<InternalEventSubscription> $subscriptions */
    public function __construct(array $subscriptions)
    {
        foreach ($subscriptions as $subscription) {
            if (!$subscription instanceof InternalEventSubscription) {
                throw new RuntimeException('Internal event subscriber declarations must use InternalEventSubscription.');
            }
            if (isset($this->subscriptions[$subscription->id()])) {
                throw new RuntimeException('Duplicate internal event subscription ID: ' . $subscription->id());
            }
            $this->subscriptions[$subscription->id()] = $subscription;
        }
    }

    public static function fromModules(ModuleManager $modules): self
    {
        return self::fromModuleInstances($modules->modules());
    }

    /** @param array<string, object> $modules */
    public static function fromModuleInstances(array $modules): self
    {
        $subscriptions = [];
        foreach ($modules as $module) {
            if (!$module instanceof ProvidesEventSubscribers) {
                continue;
            }
            foreach ($module->eventSubscribers() as $subscription) {
                if (!$subscription instanceof InternalEventSubscription) {
                    throw new RuntimeException(
                        'Module ' . $module->key() . ' declared an invalid internal event subscription.',
                    );
                }
                if ($subscription->moduleKey() !== $module->key()) {
                    throw new RuntimeException(
                        'Module ' . $module->key() . ' declared an internal event subscription for another module.',
                    );
                }
                $subscriptions[] = $subscription;
            }
        }
        return new self($subscriptions);
    }

    /** @return list<InternalEventSubscription> */
    public function forEvent(string $eventName): array
    {
        return array_values(array_filter(
            $this->subscriptions,
            static fn (InternalEventSubscription $subscription): bool => $subscription->eventName() === $eventName,
        ));
    }

    public function find(string $subscriptionId): ?InternalEventSubscription
    {
        return $this->subscriptions[$subscriptionId] ?? null;
    }

    /** @return list<InternalEventSubscription> */
    public function forModuleEvent(string $moduleKey, string $eventName): array
    {
        return array_values(array_filter(
            $this->subscriptions,
            static fn (InternalEventSubscription $subscription): bool => $subscription->moduleKey() === $moduleKey
                && $subscription->eventName() === $eventName,
        ));
    }

    /** @return list<string> */
    public function moduleKeysForEvent(string $eventName): array
    {
        $keys = [];
        foreach ($this->forEvent($eventName) as $subscription) {
            $keys[$subscription->moduleKey()] = true;
        }
        return array_keys($keys);
    }

    /** @return list<string> */
    public function eventNamesForModule(string $moduleKey): array
    {
        $events = [];
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->moduleKey() === $moduleKey) {
                $events[$subscription->eventName()] = true;
            }
        }
        $names = array_keys($events);
        sort($names);
        return $names;
    }
}
