<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Portal;
use App\Hosted\HostedContext;
use PDO;
use RuntimeException;

final class ModuleLifecycleService
{
    private array $definitions;

    public function __construct(private readonly PDO $pdo, ?array $definitions = null)
    {
        $this->definitions = $definitions ?? cpe_config('modules', []);
    }

    public function modules(): array
    {
        $installed = [];
        foreach ($this->pdo->query('SELECT * FROM module_installations')->fetchAll() as $row) {
            $installed[(string) $row['module_key']] = $row;
        }
        $modules = [];
        foreach ($this->definitions as $key => $definition) {
            $manifest = ModuleManifest::fromArray((string) $key, $definition);
            $manifest->assertCompatible((string) cpe_config('app.version', '0.0.0'), $this->definitions);
            $row = $installed[$key] ?? null;
            $configuredEnabled = $row !== null ? (bool) $row['enabled'] : false;
            $entitled = HostedContext::allowsModule((string) $key);
            $modules[] = [
                ...$manifest->toArray(),
                'installed' => $row !== null,
                'installed_version' => $row['version'] ?? null,
                'configured_enabled' => $configuredEnabled,
                'entitled' => $entitled,
                'enabled' => $configuredEnabled && $entitled,
                'installed_at' => $row['installed_at'] ?? null,
                'enabled_at' => $row['enabled_at'] ?? null,
                'disabled_at' => $row['disabled_at'] ?? null,
            ];
        }
        return $modules;
    }

    public function install(string $moduleKey, ?int $actorId = null): void
    {
        $manifest = $this->manifest($moduleKey);
        if ($this->installed($moduleKey)) {
            return;
        }
        foreach ($manifest->requiresModules() as $dependency) {
            if (!$this->installed($dependency)) {
                throw new RuntimeException("Install required module {$dependency} before {$moduleKey}.");
            }
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $now = cpe_now();
            $stmt = $this->pdo->prepare(
                'INSERT INTO module_installations
                 (module_key, version, enabled, installed_at, updated_at, installed_by, enabled_at, configuration_json)
                 VALUES (?, ?, 0, ?, ?, ?, NULL, ?)'
            );
            $stmt->execute([$moduleKey, $manifest->version(), $now, $now, $actorId, '{}']);
            $module = $this->instance($moduleKey);
            if ($module instanceof ModuleLifecycleHooks) {
                $module->onInstall();
            }
            $this->record($moduleKey, 'installed', null, $manifest->version(), $actorId, 'Module installed with data preserved by default.');
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        Portal::reset();
    }

    public function enable(string $moduleKey, ?int $actorId = null): void
    {
        $manifest = $this->manifest($moduleKey);
        if (!HostedContext::allowsModule($moduleKey)) {
            throw new RuntimeException('This module is not included in the hosted tenant entitlement.');
        }
        if (!$this->installed($moduleKey)) {
            $this->install($moduleKey, $actorId);
        }
        foreach ($manifest->requiresModules() as $dependency) {
            if (!$this->enabled($dependency)) {
                throw new RuntimeException("Enable required module {$dependency} before {$moduleKey}.");
            }
        }
        if ($this->enabled($moduleKey)) {
            return;
        }
        $this->transaction(function () use ($manifest, $moduleKey, $actorId): void {
            $now = cpe_now();
            $stmt = $this->pdo->prepare(
                'UPDATE module_installations
                 SET enabled = 1, version = ?, enabled_at = ?, disabled_at = NULL, updated_at = ?
                 WHERE module_key = ?'
            );
            $stmt->execute([$manifest->version(), $now, $now, $moduleKey]);
            $module = $this->instance($moduleKey);
            if ($module instanceof ModuleLifecycleHooks) {
                $module->onEnable();
            }
            $this->record($moduleKey, 'enabled', $manifest->version(), $manifest->version(), $actorId, 'Module enabled.');
        });
        Portal::reset();
    }

    public function disable(string $moduleKey, ?int $actorId = null): void
    {
        $this->manifest($moduleKey);
        foreach ($this->modules() as $candidate) {
            if (!$candidate['enabled'] || !in_array($moduleKey, $candidate['requires_modules'], true)) {
                continue;
            }
            throw new RuntimeException("Disable dependent module {$candidate['key']} before {$moduleKey}.");
        }
        if (!$this->enabled($moduleKey)) {
            return;
        }
        $this->transaction(function () use ($moduleKey, $actorId): void {
            $now = cpe_now();
            $stmt = $this->pdo->prepare(
                'UPDATE module_installations SET enabled = 0, disabled_at = ?, updated_at = ? WHERE module_key = ?'
            );
            $stmt->execute([$now, $now, $moduleKey]);
            $module = $this->instance($moduleKey);
            if ($module instanceof ModuleLifecycleHooks) {
                $module->onDisable();
            }
            $this->record($moduleKey, 'disabled', $module->manifest()->version(), $module->manifest()->version(), $actorId, 'Module disabled; its data was retained.');
        });
        Portal::reset();
    }

    public function uninstall(string $moduleKey, bool $exportCompleted, bool $destructiveConfirmed, ?int $actorId = null): never
    {
        $this->manifest($moduleKey);
        if ($this->enabled($moduleKey)) {
            throw new RuntimeException('Disable the module before uninstalling it.');
        }
        if (!$exportCompleted || !$destructiveConfirmed) {
            throw new RuntimeException('Module uninstall requires a successful export and explicit destructive confirmation.');
        }
        throw new RuntimeException('This bundled module has no destructive uninstall handler. Data remains preserved.');
    }

    private function manifest(string $moduleKey): ModuleManifest
    {
        $definition = $this->definitions[$moduleKey] ?? null;
        if (!is_array($definition)) {
            throw new RuntimeException('Unknown module: ' . $moduleKey);
        }
        $manifest = ModuleManifest::fromArray($moduleKey, $definition);
        $manifest->assertCompatible((string) cpe_config('app.version', '0.0.0'), $this->definitions);
        return $manifest;
    }

    private function instance(string $moduleKey): Module
    {
        $class = (string) ($this->definitions[$moduleKey]['class'] ?? '');
        $module = $class !== '' && class_exists($class) ? new $class() : null;
        if (!$module instanceof Module || $module->key() !== $moduleKey) {
            throw new RuntimeException('Module implementation is unavailable: ' . $moduleKey);
        }
        return $module;
    }

    private function installed(string $moduleKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM module_installations WHERE module_key = ?');
        $stmt->execute([$moduleKey]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function enabled(string $moduleKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT enabled FROM module_installations WHERE module_key = ?');
        $stmt->execute([$moduleKey]);
        return (bool) $stmt->fetchColumn();
    }

    private function record(
        string $moduleKey,
        string $event,
        ?string $fromVersion,
        ?string $toVersion,
        ?int $actorId,
        string $detail
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO module_lifecycle_events
             (module_key, event_type, from_version, to_version, actor_user_id, detail, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$moduleKey, $event, $fromVersion, $toVersion, $actorId, $detail, cpe_now()]);
    }

    private function transaction(callable $callback): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
