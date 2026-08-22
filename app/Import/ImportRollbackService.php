<?php

declare(strict_types=1);

namespace App\Import;

use App\Core\Backup\DatabaseBackupService;
use App\Core\Backup\DatabaseRestoreService;
use App\Support\Database;
use RuntimeException;

final class ImportRollbackService
{
    public function createSnapshot(string $type, ?int $actorId, array $report): array
    {
        $dir = $this->dir();
        $id = 'import-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $backup = (new DatabaseBackupService())->create($id, $dir);
        $backupPath = (string) $backup['path'];

        $manifest = [
            'id' => $id,
            'type' => $type,
            'actor_user_id' => $actorId,
            'created_at' => cpe_now(),
            'rows' => (int) ($report['rows'] ?? 0),
            'creates' => (int) ($report['creates'] ?? 0),
            'updates' => (int) ($report['updates'] ?? 0),
            'warnings' => count($report['warnings'] ?? []),
            'backup_path' => $backupPath,
            'backup_driver' => $backup['driver'],
            'backup_sha256' => $backup['sha256'],
            'database_path' => Database::path(),
            'restored_at' => '',
            'restore_safety_path' => '',
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
            $backupPath = (string) ($manifest['backup_path'] ?? '');
            $manifest['backup_exists'] = $backupPath !== '' && is_file($backupPath);
            $manifest['backup_size'] = $manifest['backup_exists'] ? (int) filesize($backupPath) : 0;
            $rows[] = $manifest;
        }
        return $rows;
    }

    public function restore(string $id): array
    {
        $id = trim($id);
        if (!preg_match('/^import-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $id)) {
            throw new RuntimeException('Invalid import rollback id.');
        }
        $manifest = $this->readManifest($id);
        if ($manifest === null) {
            throw new RuntimeException('Import rollback snapshot not found.');
        }
        if ((string) ($manifest['restored_at'] ?? '') !== '') {
            throw new RuntimeException('This import rollback snapshot has already been restored.');
        }
        $backupPath = (string) ($manifest['backup_path'] ?? '');
        if ($backupPath === '' || !is_file($backupPath)) {
            throw new RuntimeException('Import rollback database copy is missing.');
        }

        $restore = (new DatabaseRestoreService())->restore($backupPath, $this->dir());

        $manifest['restored_at'] = cpe_now();
        $manifest['restore_safety_path'] = $restore['safety_path'];
        $this->writeManifest($manifest);
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
        if ($json === false || file_put_contents($this->manifestPath($id), $json . "\n") === false) {
            throw new RuntimeException('Could not write import rollback manifest.');
        }
    }
}
