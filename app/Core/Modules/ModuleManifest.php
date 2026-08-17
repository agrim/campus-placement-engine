<?php

declare(strict_types=1);

namespace App\Core\Modules;

use RuntimeException;

final class ModuleManifest
{
    private function __construct(
        private readonly string $key,
        private readonly string $name,
        private readonly string $version,
        private readonly string $coreRequires,
        private readonly string $description,
        private readonly array $requiresModules,
        private readonly array $capabilities,
        private readonly bool $enabledByDefault,
    ) {
    }

    public static function fromArray(string $key, array $definition): self
    {
        $name = trim((string) ($definition['name'] ?? ''));
        $version = trim((string) ($definition['version'] ?? ''));
        $coreRequires = trim((string) ($definition['core_requires'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) !== 1) {
            throw new RuntimeException('Invalid module key: ' . $key);
        }
        if ($name === '' || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new RuntimeException('Module manifest requires a name and semantic version: ' . $key);
        }
        if (preg_match('/^>=\d+\.\d+\.\d+$/', $coreRequires) !== 1) {
            throw new RuntimeException('Module core requirement must use >=x.y.z: ' . $key);
        }
        $requires = self::keys((array) ($definition['requires_modules'] ?? []), 'module dependency');
        if (in_array($key, $requires, true)) {
            throw new RuntimeException('Module cannot depend on itself: ' . $key);
        }
        $capabilities = self::normalizeCapabilities((array) ($definition['capabilities'] ?? []));
        return new self(
            $key,
            $name,
            $version,
            $coreRequires,
            trim((string) ($definition['description'] ?? '')),
            $requires,
            $capabilities,
            !empty($definition['enabled_by_default'])
        );
    }

    public function assertCompatible(string $coreVersion, array $availableModules): void
    {
        $minimum = substr($this->coreRequires, 2);
        if (version_compare($coreVersion, $minimum, '<')) {
            throw new RuntimeException("Module {$this->key} requires core {$this->coreRequires}; installed core is {$coreVersion}.");
        }
        foreach ($this->requiresModules as $dependency) {
            if (!array_key_exists($dependency, $availableModules)) {
                throw new RuntimeException("Module {$this->key} requires unavailable module {$dependency}.");
            }
        }
    }

    public function key(): string { return $this->key; }
    public function name(): string { return $this->name; }
    public function version(): string { return $this->version; }
    public function coreRequires(): string { return $this->coreRequires; }
    public function description(): string { return $this->description; }
    public function requiresModules(): array { return $this->requiresModules; }
    public function capabilities(): array { return $this->capabilities; }
    public function enabledByDefault(): bool { return $this->enabledByDefault; }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'version' => $this->version,
            'core_requires' => $this->coreRequires,
            'description' => $this->description,
            'requires_modules' => $this->requiresModules,
            'capabilities' => $this->capabilities,
            'enabled_by_default' => $this->enabledByDefault,
        ];
    }

    private static function keys(array $values, string $label): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = (string) $value;
            if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $value) !== 1) {
                throw new RuntimeException('Invalid ' . $label . ': ' . $value);
            }
            $result[$value] = true;
        }
        return array_keys($result);
    }

    private static function normalizeCapabilities(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = (string) $value;
            if (preg_match('/^[a-z][a-z0-9_.]{2,127}$/', $value) !== 1) {
                throw new RuntimeException('Invalid module capability: ' . $value);
            }
            $result[$value] = true;
        }
        return array_keys($result);
    }
}
