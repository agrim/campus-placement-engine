<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Security\CapabilityService;
use RuntimeException;

final class ModuleManager
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly CapabilityService $capabilities,
    ) {
    }

    public function modules(): array
    {
        $instances = [];
        foreach ($this->registry->enabled() as $key => $definition) {
            $class = (string) ($definition['class'] ?? '');
            if ($class === '' || !class_exists($class)) {
                throw new RuntimeException('Enabled module has no loadable class: ' . $key);
            }
            $module = new $class();
            if (!$module instanceof Module || $module->key() !== $key) {
                throw new RuntimeException('Invalid module implementation for: ' . $key);
            }
            $manifest = $module->manifest();
            if ($manifest->key() !== $key || $manifest->version() !== (string) ($definition['version'] ?? '')) {
                throw new RuntimeException('Module implementation does not match its manifest: ' . $key);
            }
            $manifest->assertCompatible((string) cpe_config('app.version', '0.0.0'), $this->registry->all());
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
                $capability = (string) ($item['capability'] ?? 'portal.access');
                if (!$this->capabilities->allows($user, $capability)) {
                    continue;
                }
                $item['module'] = $module->key();
                $items[] = $item;
            }
        }
        usort($items, fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
        return $items;
    }
}
