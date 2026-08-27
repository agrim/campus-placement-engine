<?php

declare(strict_types=1);

namespace App\Core\Backup;

use App\Core\Http\UserVisibleException;
use App\Core\Persistence\DatabaseOwnership;
use PDO;
use RuntimeException;
use Throwable;

final class LegacySqliteBackupConverter
{
    private const MAX_ARCHIVE_BYTES = 53687091200;

    public function convert(string $sourcePath, ?string $targetDirectory = null): BackupArtifact
    {
        $sourcePath = trim($sourcePath);
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($extension === 'pgdump') {
            throw new UserVisibleException(
                'LEGACY_POSTGRES_BACKUP_CONVERSION_UNSUPPORTED',
                'Legacy PostgreSQL backups require an isolated restore validation; they cannot be converted in place.',
            );
        }
        if ($sourcePath === ''
            || $extension !== 'sqlite'
            || is_link($sourcePath)
            || !is_file($sourcePath)) {
            throw new UserVisibleException(
                'LEGACY_SQLITE_BACKUP_INVALID',
                'Provide a regular legacy SQLite backup file.',
            );
        }
        $size = filesize($sourcePath);
        if ($size === false || $size < 1 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new UserVisibleException(
                'LEGACY_SQLITE_BACKUP_INVALID',
                'The legacy SQLite backup size is invalid.',
            );
        }
        $sourceBasename = basename($sourcePath);
        if (strlen($sourceBasename) > 180
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.sqlite\z/D', $sourceBasename) !== 1) {
            throw new UserVisibleException(
                'LEGACY_SQLITE_BACKUP_INVALID',
                'The legacy SQLite backup filename is invalid.',
            );
        }
        $expectedHash = $this->legacyChecksum($sourcePath, $sourceBasename);
        $source = $this->openReadOnly($sourcePath);
        $this->assertLegacyArchive($source);
        $sourceIdentity = DatabaseOwnership::strictInstalledEngineIdentity($source);
        $source = null;

        $targetDirectory = $targetDirectory === null || trim($targetDirectory) === ''
            ? dirname((string) realpath($sourcePath))
            : rtrim(trim($targetDirectory), DIRECTORY_SEPARATOR);
        if ($targetDirectory === ''
            || is_link($targetDirectory)
            || !is_dir($targetDirectory)
            || !is_writable($targetDirectory)) {
            throw new UserVisibleException(
                'LEGACY_BACKUP_TARGET_INVALID',
                'Provide a writable conversion target directory.',
            );
        }
        $stem = substr($sourceBasename, 0, -7);
        $targetPath = '';
        $targetHandle = null;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $targetDirectory . DIRECTORY_SEPARATOR . $stem
                . '-converted-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
            if (!file_exists($candidate . '.sha256')
                && !file_exists($candidate . BackupMetadata::SUFFIX)
                && is_resource($exclusive = @fopen($candidate, 'x+b'))) {
                $targetPath = $candidate;
                $targetHandle = $exclusive;
                break;
            }
        }
        if ($targetPath === '' || !is_resource($targetHandle)) {
            throw new RuntimeException('Could not allocate a new converted backup filename.');
        }

        try {
            $sourceHandle = @fopen($sourcePath, 'rb');
            if (!is_resource($sourceHandle)) {
                throw new RuntimeException('Could not read the legacy SQLite backup.');
            }
            try {
                $copied = stream_copy_to_stream($sourceHandle, $targetHandle);
            } finally {
                fclose($sourceHandle);
                fclose($targetHandle);
                $targetHandle = null;
            }
            if ($copied !== $size || !@chmod($targetPath, 0600)) {
                throw new RuntimeException('Could not create the converted SQLite backup copy.');
            }
            $copiedHash = hash_file('sha256', $targetPath);
            if ($copiedHash === false || !hash_equals($expectedHash, strtolower($copiedHash))) {
                throw new UserVisibleException(
                    'LEGACY_SQLITE_BACKUP_CHECKSUM_MISMATCH',
                    'Legacy SQLite backup checksum verification failed.',
                );
            }
            $copyReadOnly = $this->openReadOnly($targetPath);
            $this->assertLegacyArchive($copyReadOnly);
            $copyIdentity = DatabaseOwnership::strictInstalledEngineIdentity($copyReadOnly);
            $copyReadOnly = null;
            if (!hash_equals($sourceIdentity, $copyIdentity)) {
                throw new UserVisibleException(
                    'LEGACY_SQLITE_BACKUP_IDENTITY_MISMATCH',
                    'Legacy SQLite backup identity changed during conversion.',
                );
            }

            $converted = new PDO('sqlite:' . $targetPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $converted->exec('PRAGMA foreign_keys = ON');
            $converted->exec('PRAGMA busy_timeout = 5000');
            DatabaseOwnership::claimOrVerifyInstalledEngine($converted, $sourceIdentity);
            $artifact = (new DatabaseBackupService($converted))->sealExistingSqliteArchive($targetPath);
            $converted = null;
            return $artifact;
        } catch (Throwable $e) {
            if (is_resource($targetHandle)) {
                fclose($targetHandle);
            }
            if (is_file($targetPath) || is_link($targetPath)) {
                @unlink($targetPath);
            }
            throw $e;
        }
    }

    private function legacyChecksum(string $sourcePath, string $sourceBasename): string
    {
        $checksumPath = $sourcePath . '.sha256';
        if (is_link($checksumPath) || !is_file($checksumPath)) {
            throw new UserVisibleException(
                'LEGACY_SQLITE_BACKUP_CHECKSUM_REQUIRED',
                'The legacy SQLite backup requires its original checksum file.',
            );
        }
        $checksumSize = filesize($checksumPath);
        if ($checksumSize === false || $checksumSize < 68 || $checksumSize > 512) {
            throw new UserVisibleException(
                'LEGACY_SQLITE_BACKUP_CHECKSUM_INVALID',
                'The legacy SQLite backup checksum file is invalid.',
            );
        }
        $contents = file_get_contents($checksumPath);
        if (!is_string($contents)
            || preg_match('/\A([a-f0-9]{64}) {2}([A-Za-z0-9._-]+)\n\z/D', $contents, $matches) !== 1
            || !hash_equals($sourceBasename, $matches[2])) {
            throw new UserVisibleException(
                'LEGACY_SQLITE_BACKUP_CHECKSUM_INVALID',
                'The legacy SQLite backup checksum file is invalid.',
            );
        }
        $actual = hash_file('sha256', $sourcePath);
        if ($actual === false || !hash_equals($matches[1], strtolower($actual))) {
            throw new UserVisibleException(
                'LEGACY_SQLITE_BACKUP_CHECKSUM_MISMATCH',
                'Legacy SQLite backup checksum verification failed.',
            );
        }
        return $matches[1];
    }

    private function openReadOnly(string $path): PDO
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            throw new UserVisibleException('LEGACY_SQLITE_BACKUP_INVALID', 'Legacy SQLite backup validation failed.');
        }
        $uriPath = str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $realPath);
        try {
            $pdo = new PDO('sqlite:file:' . $uriPath . '?mode=ro&immutable=1');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA query_only = ON');
            return $pdo;
        } catch (Throwable $e) {
            throw new UserVisibleException(
                'LEGACY_SQLITE_BACKUP_INVALID',
                'Legacy SQLite backup validation failed.',
                $e,
            );
        }
    }

    private function assertLegacyArchive(PDO $pdo): void
    {
        try {
            $integrity = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
            if ($integrity !== ['ok']) {
                throw new RuntimeException('SQLite integrity check failed.');
            }
            DatabaseOwnership::assertLegacyEngineSignature($pdo);
            DatabaseOwnership::strictInstalledEngineIdentity($pdo);
        } catch (Throwable $e) {
            throw new UserVisibleException(
                'LEGACY_SQLITE_BACKUP_SIGNATURE_INVALID',
                'The backup is not a complete unambiguous installed legacy Engine database.',
                $e,
            );
        }
    }
}
