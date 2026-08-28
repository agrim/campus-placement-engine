<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Http\UserVisibleException;
use App\Core\Portal;
use App\Hosted\HostedContext;
use PDO;
use RuntimeException;

/**
 * Stable Engine-owned module lifecycle for self-hosted and managed deployments.
 *
 * Managed hosting contract v1 exposes modules(), enable(), and disable(). Those
 * operations are idempotent, retain module data on disable, and must be invoked
 * only after the caller has selected and verified the institution data plane.
 */
final class ModuleLifecycleService
{
    public const CONTRACT_VERSION = 1;

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
        $changed = $this->transaction(function () use ($manifest, $moduleKey, $actorId): bool {
            foreach ($manifest->requiresModules() as $dependency) {
                if (!$this->installed($dependency)) {
                    throw new UserVisibleException('MODULE_DEPENDENCY_REQUIRED', 'Install required modules before enabling this module.');
                }
            }
            $now = cpe_now();
            $stmt = $this->pdo->prepare(
                'INSERT INTO module_installations
                 (module_key, version, enabled, installed_at, updated_at, installed_by, enabled_at, configuration_json)
                 VALUES (?, ?, 0, ?, ?, ?, NULL, ?)
                 ON CONFLICT(module_key) DO NOTHING'
            );
            $stmt->execute([$moduleKey, $manifest->version(), $now, $now, $actorId, '{}']);
            if ($stmt->rowCount() === 0) {
                return false;
            }
            $module = $this->instance($moduleKey);
            if ($module instanceof ModuleLifecycleHooks) {
                $module->onInstall();
            }
            $this->record($moduleKey, 'installed', null, $manifest->version(), $actorId, 'Module installed with data preserved by default.');
            return true;
        });
        if ($changed) {
            Portal::reset();
        }
    }

    public function enable(string $moduleKey, ?int $actorId = null): void
    {
        $manifest = $this->manifest($moduleKey);
        if (!HostedContext::allowsModule($moduleKey)) {
            throw new UserVisibleException('MODULE_NOT_ENTITLED', 'This module is not included in the hosted tenant entitlement.');
        }
        if (!$this->installed($moduleKey)) {
            $this->install($moduleKey, $actorId);
        }
        $changed = $this->transaction(function () use ($manifest, $moduleKey, $actorId): bool {
            foreach ($manifest->requiresModules() as $dependency) {
                if (!$this->enabled($dependency)) {
                    throw new UserVisibleException('MODULE_DEPENDENCY_DISABLED', 'Enable required modules before enabling this module.');
                }
            }
            $now = cpe_now();
            $stmt = $this->pdo->prepare(
                'UPDATE module_installations
                 SET enabled = 1, version = ?, enabled_at = ?, disabled_at = NULL, updated_at = ?
                 WHERE module_key = ? AND enabled = 0'
            );
            $stmt->execute([$manifest->version(), $now, $now, $moduleKey]);
            if ($stmt->rowCount() === 0) {
                return false;
            }
            $module = $this->instance($moduleKey);
            if ($module instanceof ModuleLifecycleHooks) {
                $module->onEnable();
            }
            $this->record($moduleKey, 'enabled', $manifest->version(), $manifest->version(), $actorId, 'Module enabled.');
            return true;
        });
        if ($changed) {
            Portal::reset();
        }
    }

    public function disable(string $moduleKey, ?int $actorId = null): void
    {
        $this->manifest($moduleKey);
        $changed = $this->transaction(function () use ($moduleKey, $actorId): bool {
            foreach ($this->modules() as $candidate) {
                if (!$candidate['enabled'] || !in_array($moduleKey, $candidate['requires_modules'], true)) {
                    continue;
                }
                throw new UserVisibleException('MODULE_DEPENDENT_ENABLED', 'Disable dependent modules before disabling this module.');
            }
            $now = cpe_now();
            $stmt = $this->pdo->prepare(
                'UPDATE module_installations
                 SET enabled = 0, disabled_at = ?, updated_at = ?
                 WHERE module_key = ? AND enabled = 1'
            );
            $stmt->execute([$now, $now, $moduleKey]);
            if ($stmt->rowCount() === 0) {
                return false;
            }
            $module = $this->instance($moduleKey);
            if ($module instanceof ModuleLifecycleHooks) {
                $module->onDisable();
            }
            $this->record($moduleKey, 'disabled', $module->manifest()->version(), $module->manifest()->version(), $actorId, 'Module disabled; its data was retained.');
            return true;
        });
        if ($changed) {
            Portal::reset();
        }
    }

    public function uninstall(string $moduleKey, bool $exportCompleted, bool $destructiveConfirmed, ?int $actorId = null): never
    {
        $this->manifest($moduleKey);
        if ($this->enabled($moduleKey)) {
            throw new UserVisibleException('MODULE_DISABLE_REQUIRED', 'Disable the module before uninstalling it.');
        }
        if (!$exportCompleted || !$destructiveConfirmed) {
            throw new UserVisibleException('MODULE_UNINSTALL_CONFIRMATION_REQUIRED', 'Module uninstall requires a successful export and explicit destructive confirmation.');
        }
        throw new UserVisibleException('MODULE_UNINSTALL_UNAVAILABLE', 'This bundled module has no destructive uninstall handler. Data remains preserved.');
    }

    private function manifest(string $moduleKey): ModuleManifest
    {
        $definition = $this->definitions[$moduleKey] ?? null;
        if (!is_array($definition)) {
            throw new UserVisibleException('MODULE_UNKNOWN', 'Unknown module.');
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

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        $sqliteImmediate = $ownsTransaction
            && (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $started = false;
        if ($ownsTransaction) {
            if ($sqliteImmediate) {
                $this->pdo->exec('BEGIN IMMEDIATE');
            } else {
                $this->pdo->beginTransaction();
            }
            $started = true;
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                if ($sqliteImmediate) {
                    $this->pdo->exec('COMMIT');
                } else {
                    $this->pdo->commit();
                }
                $started = false;
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started) {
                if ($sqliteImmediate) {
                    $this->pdo->exec('ROLLBACK');
                } elseif ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            }
            throw $e;
        }
    }
}
