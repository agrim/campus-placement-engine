<?php

declare(strict_types=1);

namespace App\Core\Portability;

use App\Core\Backup\DatabaseBackupService;
use App\Core\Http\UserVisibleException;
use App\Core\Install\PortalKernelSynchronizer;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleLifecycleService;
use App\Core\Modules\ProvidesPortability;
use App\Core\Portal;
use App\Core\Persistence\TransactionRollbackGuard;
use App\Domain\ConfigurationSnapshotService;
use App\Support\Database;
use PDO;
use RuntimeException;

final class PortalPortabilityService
{
    public const SCHEMA = 'career_services.portability.v1';
    public const CORE_SCHEMA = 'career_services.core.v1';
    private const MAX_MANIFEST_BYTES = 1048576;
    private const MAX_PAYLOAD_BYTES = 134217728;

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function export(string $targetDirectory): array
    {
        $targetDirectory = rtrim(trim($targetDirectory), '/');
        if ($targetDirectory === '' || is_dir($targetDirectory) || file_exists($targetDirectory)) {
            throw new RuntimeException('Portability export target must be a new directory path.');
        }
        $parent = dirname($targetDirectory);
        if (!is_dir($parent) && !mkdir($parent, 0775, true)) {
            throw new RuntimeException('Could not create portability export parent directory.');
        }
        $building = $targetDirectory . '.building-' . bin2hex(random_bytes(4));
        if (!mkdir($building . '/modules', 0775, true)) {
            throw new RuntimeException('Could not create portability bundle directory.');
        }

        try {
            $files = [];
            $files[] = $this->writePayload($building, 'core.json', $this->corePayload(), 'core', (string) cpe_config('app.version'));
            $moduleEntries = [];
            foreach ($this->installedModules() as $key => $entry) {
                $module = $entry['instance'];
                if (!$module instanceof ProvidesPortability) {
                    throw new RuntimeException('Installed module has no portability handler: ' . $key);
                }
                $payload = $module->portabilityHandler()->export();
                $module->portabilityHandler()->validate($payload);
                $path = 'modules/' . $key . '.json';
                $files[] = $this->writePayload($building, $path, $payload, $key, $module->manifest()->version());
                $moduleEntries[] = [
                    'key' => $key,
                    'name' => $module->manifest()->name(),
                    'version' => $module->manifest()->version(),
                    'enabled' => (bool) $entry['enabled'],
                    'file' => $path,
                ];
            }
            $institution = $this->institutionRow();
            $manifest = [
                'schema' => self::SCHEMA,
                'bundle_id' => 'bundle_' . bin2hex(random_bytes(16)),
                'created_at' => cpe_now(),
                'source' => [
                    'app_version' => (string) cpe_config('app.version'),
                    'database_driver' => Database::driver(),
                ],
                'institution_public_id' => (string) $institution['public_id'],
                'modules' => $moduleEntries,
                'files' => $files,
                'excluded' => [
                    'users and password hashes',
                    'sessions and idempotency keys',
                    'notification credentials and delivery attempts',
                    'request metadata and domain-event delivery state',
                    'hosted billing and control-plane metadata',
                ],
            ];
            $this->writeJson($building . '/manifest.json', $manifest);
            if (!rename($building, $targetDirectory)) {
                throw new RuntimeException('Could not finalize portability bundle.');
            }
            return [
                'bundle_reference' => (string) $manifest['bundle_id'],
                'bundle_id' => $manifest['bundle_id'],
                'modules' => count($moduleEntries),
                'files' => count($files) + 1,
            ];
        } catch (\Throwable $e) {
            $this->removeTree($building);
            throw $e;
        }
    }

    public function validate(string $directory): array
    {
        $directory = rtrim($directory, '/');
        $manifest = $this->readJson($directory . '/manifest.json', self::MAX_MANIFEST_BYTES);
        if (($manifest['schema'] ?? '') !== self::SCHEMA) {
            throw new RuntimeException('Unsupported portability bundle schema.');
        }
        if (preg_match('/^bundle_[a-f0-9]{32}$/', (string) ($manifest['bundle_id'] ?? '')) !== 1) {
            throw new RuntimeException('Portability bundle has an invalid bundle id.');
        }
        $declaredFiles = [];
        foreach ($manifest['files'] ?? [] as $file) {
            if (!is_array($file)) {
                throw new RuntimeException('Portability manifest file entries must be objects.');
            }
            $path = (string) ($file['path'] ?? '');
            if (!$this->safePayloadPath($path) || isset($declaredFiles[$path])) {
                throw new RuntimeException('Portability manifest contains an unsafe or duplicate path: ' . $path);
            }
            $fullPath = $directory . '/' . $path;
            if (is_link($fullPath) || !is_file($fullPath)) {
                throw new RuntimeException('Portability payload file is missing or is a symbolic link: ' . $path);
            }
            $size = filesize($fullPath);
            if ($size === false || $size > self::MAX_PAYLOAD_BYTES || $size !== (int) ($file['bytes'] ?? -1)) {
                throw new RuntimeException('Portability payload size does not match its manifest: ' . $path);
            }
            $actual = hash_file('sha256', $fullPath);
            $expected = strtolower((string) ($file['sha256'] ?? ''));
            if ($actual === false || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1 || !hash_equals($expected, $actual)) {
                throw new RuntimeException('Portability payload checksum mismatch: ' . $path);
            }
            $declaredFiles[$path] = $this->readJson($fullPath, self::MAX_PAYLOAD_BYTES);
        }
        if (!isset($declaredFiles['core.json'])) {
            throw new RuntimeException('Portability bundle is missing core.json.');
        }
        $moduleResults = [];
        $modules = $this->availableModules();
        foreach ($manifest['modules'] ?? [] as $entry) {
            $key = (string) ($entry['key'] ?? '');
            $path = (string) ($entry['file'] ?? '');
            if (!isset($modules[$key]) || !isset($declaredFiles[$path])) {
                throw new RuntimeException('Portability bundle requires an unavailable module: ' . $key);
            }
            $module = $modules[$key];
            if (!$module instanceof ProvidesPortability) {
                throw new RuntimeException('Module cannot import portability data: ' . $key);
            }
            $sourceVersion = (string) ($entry['version'] ?? '');
            if (version_compare($sourceVersion, $module->manifest()->version(), '>')) {
                throw new RuntimeException("Bundle module {$key} {$sourceVersion} is newer than installed {$module->manifest()->version()}.");
            }
            $moduleResults[$key] = $module->portabilityHandler()->validate($declaredFiles[$path]);
        }
        $workflowDefinitions = $this->portableWorkflowDefinitions($declaredFiles);
        $knownRoleKeys = array_values(array_filter(array_map(
            fn (mixed $row): string => is_array($row) ? (string) ($row['role_key'] ?? '') : '',
            (array) ($declaredFiles['core.json']['custom_roles'] ?? [])
        )));
        $this->validateCorePayload($declaredFiles['core.json'], $workflowDefinitions, $knownRoleKeys);
        $manifestInstitutionPublicId = (string) ($manifest['institution_public_id'] ?? '');
        $coreInstitutionPublicId = (string) ($declaredFiles['core.json']['institution']['public_id'] ?? '');
        if ($manifestInstitutionPublicId === ''
            || !hash_equals($manifestInstitutionPublicId, $coreInstitutionPublicId)) {
            throw new RuntimeException('Portability bundle institution identity is inconsistent.');
        }
        $this->rejectUnexpectedFiles($directory, array_keys($declaredFiles));
        return [
            'bundle_reference' => (string) $manifest['bundle_id'],
            'bundle_id' => (string) $manifest['bundle_id'],
            'institution_public_id' => (string) ($manifest['institution_public_id'] ?? ''),
            'modules' => $moduleResults,
            'manifest' => $manifest,
        ];
    }

    public function import(string $directory): array
    {
        if (!Database::isInstalled()) {
            throw new RuntimeException('Install the target portal before importing a portability bundle.');
        }
        $validation = $this->validate($directory);
        $this->assertTargetIdentityCompatible((string) $validation['institution_public_id']);
        $manifest = $validation['manifest'];
        $payloads = [];
        foreach ($manifest['files'] as $file) {
            $payloads[(string) $file['path']] = $this->readJson(rtrim($directory, '/') . '/' . $file['path'], self::MAX_PAYLOAD_BYTES);
        }
        $backup = (new DatabaseBackupService($this->pdo))->create('portability-import');
        $modules = $this->availableModules();
        $lifecycle = new ModuleLifecycleService($this->pdo);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $moduleResults = [];
            foreach ($manifest['modules'] as $entry) {
                $key = (string) $entry['key'];
                $path = (string) $entry['file'];
                $installed = array_values(array_filter($lifecycle->modules(), fn (array $row): bool => $row['key'] === $key && $row['installed']));
                if ($installed === []) {
                    $lifecycle->install($key);
                }
                $moduleResults[$key] = $modules[$key]->portabilityHandler()->import($payloads[$path]);
                if (!empty($entry['enabled'])) {
                    $lifecycle->enable($key);
                }
            }
            $this->importCorePayload($payloads['core.json']);
            foreach (array_reverse($manifest['modules']) as $entry) {
                if (empty($entry['enabled'])) {
                    $lifecycle->disable((string) $entry['key']);
                }
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                TransactionRollbackGuard::rethrow($this->pdo, $e, 'portability.import', true);
            }
            throw $e;
        }
        (new PortalKernelSynchronizer())->synchronize($this->pdo);
        Portal::reset();
        return [
            'bundle_id' => $validation['bundle_id'],
            'safety_reference' => $backup->reference(),
            'modules' => $moduleResults,
        ];
    }

    private function corePayload(): array
    {
        $institution = $this->institutionRow();
        $roles = $this->pdo->query(
            'SELECT role_key, label, system_role, created_at, updated_at FROM roles WHERE system_role = 0 ORDER BY role_key'
        )->fetchAll();
        $capabilities = $this->pdo->query(
            'SELECT rc.role_key, rc.capability
             FROM role_capabilities rc JOIN roles r ON r.role_key = rc.role_key
             WHERE r.system_role = 0 ORDER BY rc.role_key, rc.capability'
        )->fetchAll();
        $scoped = array_values(array_filter(
            $this->pdo->query('SELECT scope_type, scope_id, setting_key, value, updated_at FROM scoped_settings ORDER BY scope_type, scope_id, setting_key')->fetchAll(),
            fn (array $row): bool => !$this->looksSensitive((string) $row['setting_key'])
        ));
        return [
            'schema' => self::CORE_SCHEMA,
            'institution' => $institution,
            'configuration' => (new ConfigurationSnapshotService($this->pdo))->portabilityPayload(),
            'custom_roles' => $roles,
            'custom_role_capabilities' => $capabilities,
            'scoped_settings' => $scoped,
            'excluded' => ['users', 'memberships', 'password_hashes', 'sessions', 'credentials'],
        ];
    }

    private function validateCorePayload(array $payload, array $workflowDefinitions = [], array $knownRoleKeys = []): void
    {
        if (($payload['schema'] ?? '') !== self::CORE_SCHEMA || !is_array($payload['institution'] ?? null)) {
            throw new RuntimeException('Portability core payload has an unsupported schema.');
        }
        $institutionPublicId = $payload['institution']['public_id'] ?? null;
        if (!is_string($institutionPublicId)
            || preg_match('/^(?:inst|tenant)_[a-f0-9]{32}$/D', $institutionPublicId) !== 1) {
            throw new RuntimeException('Portability core payload has an invalid institution public id.');
        }
        (new ConfigurationSnapshotService($this->pdo))->validatePortabilityPayload(
            (array) ($payload['configuration'] ?? []),
            $workflowDefinitions,
            $knownRoleKeys
        );
        foreach (['custom_roles', 'custom_role_capabilities', 'scoped_settings'] as $list) {
            if (!is_array($payload[$list] ?? null) || !array_is_list($payload[$list])) {
                throw new RuntimeException('Portability core payload has an invalid list: ' . $list);
            }
        }
        foreach (['password_hash', 'client_secret', 'api_token', 'session_id'] as $forbidden) {
            if ($this->hasFieldContaining($payload, $forbidden)) {
                throw new RuntimeException('Portability core payload contains a forbidden secret field: ' . $forbidden);
            }
        }
    }

    private function importCorePayload(array $payload): void
    {
        $knownRoleKeys = array_values(array_filter(array_map(
            fn (mixed $row): string => is_array($row) ? (string) ($row['role_key'] ?? '') : '',
            (array) ($payload['custom_roles'] ?? [])
        )));
        $this->validateCorePayload($payload, [], $knownRoleKeys);
        $role = $this->pdo->prepare(
            'INSERT INTO roles (role_key, label, system_role, created_at, updated_at) VALUES (?, ?, 0, ?, ?)
             ON CONFLICT(role_key) DO UPDATE SET label = excluded.label, updated_at = excluded.updated_at'
        );
        foreach ($payload['custom_roles'] as $row) {
            $role->execute([$row['role_key'], $row['label'], $row['created_at'], $row['updated_at']]);
        }
        $capability = $this->pdo->prepare(
            'INSERT INTO role_capabilities (role_key, capability) VALUES (?, ?)
             ON CONFLICT(role_key, capability) DO NOTHING'
        );
        foreach ($payload['custom_role_capabilities'] as $row) {
            $capability->execute([$row['role_key'], $row['capability']]);
        }
        (new ConfigurationSnapshotService($this->pdo))->importPortabilityPayload($payload['configuration']);
        $setting = $this->pdo->prepare(
            'INSERT INTO scoped_settings (scope_type, scope_id, setting_key, value, updated_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(scope_type, scope_id, setting_key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        foreach ($payload['scoped_settings'] as $row) {
            if ($this->looksSensitive((string) $row['setting_key'])) {
                throw new RuntimeException('Portable scoped setting looks sensitive: ' . $row['setting_key']);
            }
            $setting->execute([$row['scope_type'], $row['scope_id'], $row['setting_key'], $row['value'], $row['updated_at']]);
        }
    }

    private function portableWorkflowDefinitions(array $payloads): array
    {
        $definitions = [];
        $placement = $payloads['modules/placement.json'] ?? null;
        if (!is_array($placement)) {
            return $definitions;
        }
        foreach ($placement['workflows'] ?? [] as $workflow) {
            if (!is_array($workflow)) {
                continue;
            }
            $key = (string) ($workflow['workflow_key'] ?? '');
            $definition = $workflow['definition'] ?? null;
            if ($key !== '' && is_array($definition)) {
                $definitions[$key] = $definition;
            }
        }
        return $definitions;
    }

    private function institutionRow(): array
    {
        $row = $this->pdo->query(
            "SELECT public_id, slug, name, timezone, created_at, updated_at FROM institutions WHERE slug = 'default'"
        )->fetch();
        if (!$row) {
            throw new RuntimeException('The portal has no default institution.');
        }
        return $row;
    }

    private function assertTargetIdentityCompatible(string $bundleInstitutionPublicId): void
    {
        $targetInstitutionPublicId = (string) ($this->institutionRow()['public_id'] ?? '');
        if (preg_match('/\Ainst_[a-f0-9]{32}\z/D', $targetInstitutionPublicId) === 1
            && preg_match('/\Ainst_[a-f0-9]{32}\z/D', $bundleInstitutionPublicId) === 1) {
            return;
        }
        if (preg_match('/\Atenant_[a-f0-9]{32}\z/D', $targetInstitutionPublicId) === 1
            && hash_equals($targetInstitutionPublicId, $bundleInstitutionPublicId)) {
            return;
        }
        throw new RuntimeException('Portability bundle identity is not compatible with the installed target.');
    }

    private function installedModules(): array
    {
        $rows = [];
        foreach ($this->pdo->query('SELECT module_key, enabled FROM module_installations')->fetchAll() as $row) {
            $rows[(string) $row['module_key']] = (bool) $row['enabled'];
        }
        $modules = [];
        foreach ($this->availableModules() as $key => $module) {
            if (array_key_exists($key, $rows)) {
                $modules[$key] = ['instance' => $module, 'enabled' => $rows[$key]];
            }
        }
        return $modules;
    }

    private function availableModules(): array
    {
        $modules = [];
        foreach (cpe_config('modules', []) as $key => $definition) {
            $class = (string) ($definition['class'] ?? '');
            $module = $class !== '' && class_exists($class) ? new $class() : null;
            if (!$module instanceof Module || $module->key() !== $key) {
                throw new RuntimeException('Configured module implementation is unavailable: ' . $key);
            }
            $modules[$key] = $module;
        }
        return $modules;
    }

    private function writePayload(string $root, string $path, array $payload, string $owner, string $version): array
    {
        $fullPath = $root . '/' . $path;
        $this->writeJson($fullPath, $payload);
        $checksum = hash_file('sha256', $fullPath);
        if ($checksum === false) {
            throw new RuntimeException('Could not checksum portability payload: ' . $path);
        }
        return [
            'path' => $path,
            'owner' => $owner,
            'version' => $version,
            'schema' => (string) ($payload['schema'] ?? ''),
            'bytes' => (int) filesize($fullPath),
            'sha256' => $checksum,
        ];
    }

    private function writeJson(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not write portability file: ' . $path);
        }
    }

    private function readJson(string $path, int $maxBytes): array
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Portability JSON file is missing: ' . $path);
        }
        $size = filesize($path);
        if ($size === false || $size > $maxBytes) {
            throw new RuntimeException('Portability JSON file is too large: ' . $path);
        }
        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UserVisibleException('PORTABILITY_JSON_INVALID', 'Portability JSON is invalid.', $e);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('Portability JSON root must be an object: ' . $path);
        }
        return $payload;
    }

    private function safePayloadPath(string $path): bool
    {
        return $path === 'core.json' || preg_match('#^modules/[a-z][a-z0-9_]{1,63}\.json$#', $path) === 1;
    }

    private function rejectUnexpectedFiles(string $directory, array $payloadPaths): void
    {
        $allowed = array_fill_keys(['manifest.json', ...$payloadPaths], true);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Portability bundle must not contain symbolic links.');
            }
            if (!$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace(rtrim($directory, '/') . '/', '', $file->getPathname()), '/');
            if (!isset($allowed[$relative])) {
                throw new RuntimeException('Portability bundle contains an unexpected file: ' . $relative);
            }
        }
    }

    private function looksSensitive(string $key): bool
    {
        return preg_match('/(?:password|secret|token|credential|private_key|session|webhook|gateway_url)/i', $key) === 1;
    }

    private function hasFieldContaining(array $payload, string $needle): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && stripos($key, $needle) !== false) {
                return true;
            }
            if (is_array($value) && $this->hasFieldContaining($value, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
