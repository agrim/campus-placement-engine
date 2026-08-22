<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Hosted\HostedContext;
use PDO;
use RuntimeException;

final class ModuleRegistry
{
    public function __construct(
        private readonly array $definitions,
        private readonly ?PDO $pdo = null,
    ) {
    }

    public function all(): array
    {
        $modules = $this->definitions;
        $installed = $this->installedRows();
        foreach ($modules as $key => &$module) {
            $row = $installed[$key] ?? null;
            $module['key'] = $key;
            $module['installed'] = $row !== null;
            $module['configured_enabled'] = $row !== null
                ? (bool) $row['enabled']
                : (bool) ($module['enabled_by_default'] ?? false);
            $module['entitled'] = HostedContext::allowsModule((string) $key);
            $module['enabled'] = $module['configured_enabled'] && $module['entitled'];
            $module['installed_version'] = $row['version'] ?? null;
        }
        unset($module);
        return $modules;
    }

    public function enabled(): array
    {
        return array_filter($this->all(), fn (array $module): bool => (bool) $module['enabled']);
    }

    public function isEnabled(string $key): bool
    {
        return (bool) ($this->all()[$key]['enabled'] ?? false);
    }

    public function requireEnabled(string $key): void
    {
        if (!$this->isEnabled($key)) {
            throw new RuntimeException('Required module is disabled: ' . $key);
        }
    }

    private function installedRows(): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $rows = [];
            foreach ($this->pdo->query('SELECT module_key, version, enabled FROM module_installations')->fetchAll() as $row) {
                $rows[(string) $row['module_key']] = $row;
            }
            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }
}
