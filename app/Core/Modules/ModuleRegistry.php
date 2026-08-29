<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Security\AuthorizationUnavailable;
use App\Hosted\HostedContext;
use PDO;
use RuntimeException;

/**
 * Release-bundled module catalog plus institution-local lifecycle state.
 *
 * @internal This registry never discovers executable code from writable paths.
 */
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
            if (!is_string($key) || !is_array($module)) {
                throw AuthorizationUnavailable::moduleState();
            }
            ModuleVersionIntegrity::implementationMetadata($key, $module);
            $row = $installed[$key] ?? null;
            $module['key'] = $key;
            $module['installed'] = $row !== null;
            $module['configured_enabled'] = $row !== null
                ? $row['enabled']
                : $this->pdo === null && (bool) ($module['enabled_by_default'] ?? false);
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
            $result = $this->pdo
                ->query('SELECT module_key, version, enabled FROM module_installations')
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($result as $row) {
                if (count($row) !== 3
                    || !array_key_exists('module_key', $row)
                    || !array_key_exists('version', $row)
                    || !array_key_exists('enabled', $row)
                    || !is_string($row['module_key'])
                    || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $row['module_key']) !== 1
                    || !is_string($row['version'])
                    || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $row['version']) !== 1) {
                    throw AuthorizationUnavailable::moduleState();
                }
                $enabled = match ($row['enabled']) {
                    0, '0' => false,
                    1, '1' => true,
                    default => throw AuthorizationUnavailable::moduleState(),
                };
                if (isset($rows[$row['module_key']])) {
                    throw AuthorizationUnavailable::moduleState();
                }
                $definition = $this->definitions[$row['module_key']] ?? null;
                if (is_array($definition)) {
                    ModuleVersionIntegrity::assertDurableMatchesDefinition(
                        $row['module_key'],
                        $row['version'],
                        $definition,
                    );
                }
                $rows[$row['module_key']] = [
                    'module_key' => $row['module_key'],
                    'version' => $row['version'],
                    'enabled' => $enabled,
                ];
            }
            return $rows;
        } catch (\Throwable) {
            throw AuthorizationUnavailable::moduleState();
        }
    }
}
