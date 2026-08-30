<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Security\CapabilityService;
use RuntimeException;

/**
 * Loads only module classes declared by the immutable Engine release.
 *
 * @internal External integrations must use documented event and API contracts.
 */
final class ModuleManager
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly CapabilityService $capabilities,
    ) {
    }

    public function modules(): array
    {
        $available = $this->registry->all();
        return $this->instantiateModules($this->registry->enabled(), $available);
    }

    /**
     * Instantiates an exact set snapshotted while a domain event committed.
     * This is for post-commit fanout only; current enablement is intentionally
     * not used to erase already-durable observer eligibility.
     *
     * @param list<string> $keys
     * @return array<string, Module>
     */
    public function modulesForKeys(array $keys): array
    {
        $available = $this->registry->all();
        $selected = [];
        foreach ($keys as $key) {
            if (!is_string($key) || !array_key_exists($key, $available)) {
                throw new RuntimeException('Domain-event fanout references an unavailable bundled module.');
            }
            $selected[$key] = $available[$key];
        }
        return $this->instantiateModules($selected, $available);
    }

    /** @return list<string> */
    public function internalObserverEventsForKey(string $key): array
    {
        $definition = $this->registry->all()[$key] ?? null;
        if (!is_array($definition) || !is_array($definition['internal_event_observer_events'] ?? null)) {
            throw new RuntimeException('Bundled module event eligibility metadata is invalid.');
        }
        $events = [];
        foreach ($definition['internal_event_observer_events'] as $eventName) {
            if (!is_string($eventName)
                || preg_match('/^[a-z][a-z0-9_.]{2,127}$/D', $eventName) !== 1) {
                throw new RuntimeException('Bundled module event eligibility metadata is invalid.');
            }
            $events[$eventName] = true;
        }
        $names = array_keys($events);
        sort($names);
        return $names;
    }

    private function instantiateModules(array $definitions, array $available): array
    {
        $instances = [];
        foreach ($definitions as $key => $definition) {
            $class = (string) ($definition['class'] ?? '');
            if ($class === '' || !class_exists($class)) {
                throw new RuntimeException('Enabled module has no loadable class: ' . $key);
            }
            $module = new $class();
            if (!$module instanceof Module) {
                throw new RuntimeException('Invalid module implementation for: ' . $key);
            }
            $manifest = ModuleVersionIntegrity::implementationManifest((string) $key, $module, $definition);
            $manifest->assertCompatible((string) cpe_config('app.version', '0.0.0'), $available);
            $instances[$key] = $module;
        }
        return $instances;
    }

    public function routes(): array
    {
        $routes = [];
        foreach ($this->modules() as $module) {
            foreach ($module->routes() as $route) {
                $route['module'] = $module->key();
                $routes[] = $route;
            }
        }
        return $routes;
    }

    public function navigation(array $user): array
    {
        $items = [];
        foreach ($this->modules() as $module) {
            foreach ($module->navigation() as $item) {
                $required = $item['capabilities'] ?? [$item['capability'] ?? 'portal.access'];
                if (!is_array($required) || $required === []) {
                    throw new RuntimeException('Module navigation capability metadata is invalid.');
                }
                foreach ($required as $capability) {
                    if (!is_string($capability) || $capability === '') {
                        throw new RuntimeException('Module navigation capability metadata is invalid.');
                    }
                    if (!$this->capabilities->allows($user, $capability)) {
                        continue 2;
                    }
                }
                $item['module'] = $module->key();
                $items[] = $item;
            }
        }
        usort($items, fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
        return $items;
    }
}
