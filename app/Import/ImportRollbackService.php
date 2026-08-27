<?php

declare(strict_types=1);

namespace App\Import;

use App\Core\Http\UserVisibleException;
use App\Core\Backup\DatabaseBackupService;
use App\Core\Backup\DatabaseRestoreService;
use App\Core\Backup\LegacySqliteBackupConverter;
use RuntimeException;

final class ImportRollbackService
{
    public const LEGACY_POSTGRES_ERROR = 'IMPORT_ROLLBACK_LEGACY_POSTGRES_ISOLATED_VALIDATION_REQUIRED';
    public const LEGACY_POSTGRES_REQUIREMENT = 'postgres_isolated_restore_validation';

    public function createSnapshot(string $type, ?int $actorId, array $report): array
    {
        $dir = $this->dir();
        $id = 'import-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $backup = (new DatabaseBackupService())->create($id, $dir);
        $manifest = [
            'id' => $id,
            'type' => $type,
            'actor_user_id' => $actorId,
            'created_at' => cpe_now(),
            'rows' => (int) ($report['rows'] ?? 0),
            'creates' => (int) ($report['creates'] ?? 0),
            'updates' => (int) ($report['updates'] ?? 0),
            'warnings' => count($report['warnings'] ?? []),
            'backup_file' => $backup->fileName(),
            'backup_reference' => $backup->reference(),
            'backup_driver' => $backup->driver(),
            'backup_sha256' => $backup->sha256(),
            'restored_at' => '',
            'restore_safety_reference' => '',
        ];
        $this->writeManifest($manifest);
        return $manifest;
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 5): array
    {
        $files = glob($this->dir() . '/import-*.json') ?: [];
        rsort($files);
        $rows = [];
        foreach (array_slice($files, 0, max(1, $limit)) as $file) {
            $manifest = $this->readManifestFile($file);
            if ($manifest === null) {
                continue;
            }
            $backupPath = $this->backupPath($manifest);
            $legacyFile = $this->legacyBackupFile($manifest);
            $legacyKind = $this->legacyBackupKind($legacyFile);
            $legacyPath = $legacyFile === '' ? '' : $this->dir() . '/' . $legacyFile;
            $legacyExists = $legacyPath !== '' && !is_link($legacyPath) && is_file($legacyPath);
            $manifest['backup_exists'] = $backupPath !== '' && is_file($backupPath);
            $manifest['backup_size'] = $manifest['backup_exists'] ? (int) filesize($backupPath) : 0;
            $manifest['legacy_archive_exists'] = $legacyExists;
            $manifest['legacy_backup_kind'] = $legacyKind;
            $manifest['legacy_conversion_required'] = $backupPath === ''
                && $legacyKind === 'sqlite'
                && $legacyExists;
            $manifest['legacy_recovery_required'] = $backupPath === ''
                && $legacyKind === 'pgsql'
                && $legacyExists;
            $manifest['legacy_recovery_requirement'] = $legacyKind === 'pgsql'
                ? self::LEGACY_POSTGRES_REQUIREMENT
                : ($legacyKind === 'sqlite' ? 'sqlite_conversion' : '');
            $manifest['legacy_backup_file'] = $legacyFile;
            $manifest['backup_driver'] = $backupPath !== ''
                ? (str_ends_with($backupPath, '.pgdump') ? 'pgsql' : 'sqlite')
                : $legacyKind;
            unset($manifest['backup_path'], $manifest['database_path'], $manifest['restore_safety_path']);
            $rows[] = $manifest;
        }
        return $rows;
    }

    public function restore(string $id): array
    {
        $id = trim($id);
        if (!preg_match('/^import-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $id)) {
            throw new UserVisibleException('IMPORT_ROLLBACK_ID_INVALID', 'Invalid import rollback id.');
        }
        $manifest = $this->readManifest($id);
        if ($manifest === null) {
            throw new UserVisibleException('IMPORT_ROLLBACK_NOT_FOUND', 'Import rollback snapshot not found.');
        }
        if ((string) ($manifest['restored_at'] ?? '') !== '') {
            throw new UserVisibleException('IMPORT_ROLLBACK_ALREADY_RESTORED', 'This import rollback snapshot has already been restored.');
        }
        $backupPath = $this->backupPath($manifest);
        $legacyFile = $this->legacyBackupFile($manifest);
        if ($backupPath === '' && $this->legacyBackupKind($legacyFile) === 'pgsql') {
            $this->refuseLegacyPostgres();
        }
        if ($backupPath === '' && $legacyFile !== '') {
            throw new UserVisibleException(
                'IMPORT_ROLLBACK_LEGACY_CONVERSION_REQUIRED',
                'This legacy import rollback snapshot must be explicitly converted before restore.',
            );
        }
        if ($backupPath === '' || !is_file($backupPath)) {
            throw new RuntimeException('Import rollback database copy is missing.');
        }

        $restore = (new DatabaseRestoreService())->restore($backupPath, $this->dir());

        $manifest['restored_at'] = cpe_now();
        $manifest['restore_safety_reference'] = $restore['safety_reference'];
        $this->writeManifest($manifest);
        return $manifest;
    }

    public function convertLegacy(string $id): array
    {
        $id = trim($id);
        if (!preg_match('/^import-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $id)) {
            throw new UserVisibleException('IMPORT_ROLLBACK_ID_INVALID', 'Invalid import rollback id.');
        }
        $manifest = $this->readManifest($id);
        if ($manifest === null) {
            throw new UserVisibleException('IMPORT_ROLLBACK_NOT_FOUND', 'Import rollback snapshot not found.');
        }
        if ($this->backupPath($manifest) !== '') {
            throw new UserVisibleException(
                'IMPORT_ROLLBACK_ALREADY_CURRENT',
                'This import rollback snapshot already uses the current backup format.',
            );
        }
        if ((string) ($manifest['restored_at'] ?? '') !== '') {
            throw new UserVisibleException(
                'IMPORT_ROLLBACK_ALREADY_RESTORED',
                'This import rollback snapshot has already been restored.',
            );
        }
        $legacyFile = $this->legacyBackupFile($manifest);
        if ($legacyFile === '') {
            throw new UserVisibleException(
                'IMPORT_ROLLBACK_LEGACY_INVALID',
                'The legacy import rollback manifest does not identify a safe backup file.',
            );
        }
        if ($this->legacyBackupKind($legacyFile) === 'pgsql') {
            $this->refuseLegacyPostgres();
        }
        $legacyPath = $this->dir() . '/' . $legacyFile;
        if (!is_file($legacyPath) || is_link($legacyPath)) {
            throw new UserVisibleException(
                'IMPORT_ROLLBACK_LEGACY_MISSING',
                'Place the original legacy backup and checksum in the configured import rollback directory.',
            );
        }
        $artifact = (new LegacySqliteBackupConverter())->convert($legacyPath, $this->dir());
        unset($manifest['backup_path'], $manifest['database_path'], $manifest['restore_safety_path']);
        $manifest['backup_file'] = $artifact->fileName();
        $manifest['backup_reference'] = $artifact->reference();
        $manifest['backup_driver'] = $artifact->driver();
        $manifest['backup_sha256'] = $artifact->sha256();
        $manifest['legacy_backup_file'] = $legacyFile;
        $manifest['legacy_converted_at'] = cpe_now();
        $manifest['restore_safety_reference'] = (string) ($manifest['restore_safety_reference'] ?? '');
        try {
            $this->writeManifest($manifest);
        } catch (\Throwable $e) {
            foreach ([
                $artifact->internalChecksumPath(),
                $artifact->internalMetadataPath(),
                $artifact->internalPath(),
            ] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            throw $e;
        }
        $manifest['legacy_conversion_required'] = false;
        return $manifest;
    }

    private function dir(): string
    {
        $dir = getenv('CPE_IMPORT_ROLLBACK_DIR') ?: cpe_data_path('imports');
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Could not create import rollback directory.');
        }
        return $dir;
    }

    private function manifestPath(string $id): string
    {
        return $this->dir() . '/' . $id . '.json';
    }

    private function backupPath(array $manifest): string
    {
        $file = (string) ($manifest['backup_file'] ?? '');
        if ($file === '' || basename($file) !== $file
            || preg_match(
                '/\Aimport-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}'
                . '(?:-converted-[0-9]{8}-[0-9]{6}-[a-f0-9]{6})?\.(?:sqlite|pgdump)\z/D',
                $file,
            ) !== 1) {
            return '';
        }
        return $this->dir() . '/' . $file;
    }

    private function legacyBackupFile(array $manifest): string
    {
        $stored = (string) ($manifest['backup_path'] ?? '');
        if ($stored === '') {
            $stored = (string) ($manifest['legacy_backup_file'] ?? '');
        }
        if ($stored === '' || str_contains($stored, "\0") || str_contains($stored, '\\')) {
            return '';
        }
        $segments = explode('/', $stored);
        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return '';
        }
        $file = basename($stored);
        $id = preg_quote((string) ($manifest['id'] ?? ''), '/');
        if ($id === ''
            || preg_match(
                '/\A' . $id . '-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.(?:sqlite|pgdump)\z/D',
                $file,
            ) !== 1) {
            return '';
        }
        return $file;
    }

    private function legacyBackupKind(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'sqlite' => 'sqlite',
            'pgdump' => 'pgsql',
            default => '',
        };
    }

    private function refuseLegacyPostgres(): never
    {
        throw new UserVisibleException(
            self::LEGACY_POSTGRES_ERROR,
            'Legacy PostgreSQL rollback snapshots require manual isolated restore validation; direct conversion and restore are unsupported.',
        );
    }

    private function readManifest(string $id): ?array
    {
        return $this->readManifestFile($this->manifestPath($id));
    }

    private function readManifestFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(array $manifest): void
    {
        $id = (string) ($manifest['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Import rollback manifest is missing an id.');
        }
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $path = $this->manifestPath($id);
        $temporary = $path . '.writing-' . bin2hex(random_bytes(4));
        if ($json === false
            || file_put_contents($temporary, $json . "\n", LOCK_EX) === false
            || !@chmod($temporary, 0600)
            || !@rename($temporary, $path)) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            throw new RuntimeException('Could not write import rollback manifest.');
        }
    }
}
