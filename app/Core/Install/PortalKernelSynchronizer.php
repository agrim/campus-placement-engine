<?php

declare(strict_types=1);

namespace App\Core\Install;

use App\Core\Institution\InstitutionRepository;
use App\Core\Settings\SettingRepository;
use PDO;

final class PortalKernelSynchronizer
{
    public function synchronize(PDO $pdo, ?string $institutionPublicId = null): void
    {
        if (!$this->hasKernelTables($pdo)) {
            return;
        }

        $this->ensureInstitution($pdo, $institutionPublicId);
        $this->ensureCycle($pdo);
        (new InstitutionRepository($pdo))->synchronizeFromSettings();
        $this->synchronizeCycle($pdo);
        $this->synchronizeModules($pdo);
        $this->synchronizeRoles($pdo);
    }

    private function ensureInstitution(PDO $pdo, ?string $institutionPublicId): void
    {
        $settings = new SettingRepository($pdo);
        $now = cpe_now();
        $stmt = $pdo->prepare(
            'INSERT INTO institutions (public_id, slug, name, timezone, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(slug) DO NOTHING'
        );
        $defaultPublicId = $settings->get('installed_at', '') === ''
            ? 'unbound_' . bin2hex(random_bytes(16))
            : 'inst_' . bin2hex(random_bytes(16));
        $stmt->execute([
            $institutionPublicId ?? $defaultPublicId,
            'default',
            trim($settings->get('college_name', 'Demo College')) ?: 'Demo College',
            trim($settings->get('timezone', 'Asia/Kolkata')) ?: 'Asia/Kolkata',
            $now,
            $now,
        ]);
    }

    private function ensureCycle(PDO $pdo): void
    {
        $settings = new SettingRepository($pdo);
        $institutionId = $pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn();
        if ($institutionId === false) {
            return;
        }
        $now = cpe_now();
        $stmt = $pdo->prepare(
            'INSERT INTO placement_cycles
             (public_id, institution_id, cycle_key, name, cycle_type, starts_on, ends_on, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(institution_id, cycle_key) DO NOTHING'
        );
        $stmt->execute([
            'cycle_' . bin2hex(random_bytes(16)),
            (int) $institutionId,
            'default',
            trim($settings->get('cycle_name', 'Placement Cycle')) ?: 'Placement Cycle',
            trim($settings->get('cycle_type', 'final')) ?: 'final',
            trim($settings->get('cycle_start_date', '')),
            trim($settings->get('cycle_end_date', '')),
            'active',
            $now,
            $now,
        ]);
    }

    private function synchronizeCycle(PDO $pdo): void
    {
        $settings = new SettingRepository($pdo);
        $stmt = $pdo->prepare(
            "UPDATE placement_cycles
             SET name = ?, cycle_type = ?, starts_on = ?, ends_on = ?, updated_at = ?
             WHERE cycle_key = 'default'"
        );
        $stmt->execute([
            trim($settings->get('cycle_name', 'Placement Cycle')) ?: 'Placement Cycle',
            trim($settings->get('cycle_type', 'final')) ?: 'final',
            trim($settings->get('cycle_start_date', '')),
            trim($settings->get('cycle_end_date', '')),
            cpe_now(),
        ]);
    }

    private function synchronizeModules(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO module_installations (module_key, version, enabled, installed_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(module_key) DO UPDATE SET version = excluded.version, updated_at = excluded.updated_at'
        );
        foreach (cpe_config('modules', []) as $key => $module) {
            $now = cpe_now();
            $stmt->execute([
                $key,
                (string) ($module['version'] ?? '0.0.0'),
                !empty($module['enabled_by_default']) ? 1 : 0,
                $now,
                $now,
            ]);
        }
    }

    private function synchronizeRoles(PDO $pdo): void
    {
        $labels = cpe_config('roles', []);
        $capabilityMap = cpe_config('capabilities.roles', []);
        $roleStmt = $pdo->prepare(
            'INSERT INTO roles (role_key, label, system_role, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?)
             ON CONFLICT(role_key) DO UPDATE SET label = excluded.label, updated_at = excluded.updated_at'
        );
        $capabilityStmt = $pdo->prepare(
            'INSERT INTO role_capabilities (role_key, capability) VALUES (?, ?)
             ON CONFLICT(role_key, capability) DO NOTHING'
        );
        foreach ($capabilityMap as $role => $capabilities) {
            $now = cpe_now();
            $roleStmt->execute([$role, (string) ($labels[$role] ?? ucfirst($role)), $now, $now]);
            foreach ($capabilities as $capability) {
                $capabilityStmt->execute([$role, $capability]);
            }
        }

        $pdo->exec(
            "INSERT INTO user_role_assignments (user_id, role_key, scope_type, scope_value, created_at)
             SELECT id, role, scope_type, scope_value, created_at FROM users
             WHERE NOT EXISTS (
                 SELECT 1 FROM user_role_assignments ura
                 WHERE ura.user_id = users.id AND ura.role_key = users.role
             )"
        );
    }

    private function hasKernelTables(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM institutions LIMIT 1');
            $pdo->query('SELECT 1 FROM placement_cycles LIMIT 1');
            $pdo->query('SELECT 1 FROM module_installations LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
