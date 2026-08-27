<?php

declare(strict_types=1);

namespace App\Core\Backup;

use App\Core\Http\UserVisibleException;
use App\Core\Persistence\DatabaseOwnership;
use PDO;
use RuntimeException;

final class BackupMetadata
{
    public const SCHEMA = 'cpe.database-backup.v1';
    public const SUFFIX = '.metadata.json';
    public const MAX_BYTES = 65536;
    /** Allows a small amount of clock skew without accepting future-dated backups as fresh. */
    public const MAX_FUTURE_SKEW_SECONDS = 300;

    /** @return array<string, int|string> */
    public static function create(PDO $pdo, string $driver, string $archiveSha256): array
    {
        $identity = self::databaseIdentity($pdo, $driver);
        return [
            'schema' => self::SCHEMA,
            'driver' => $driver,
            'owner_kind' => $identity['owner_kind'],
            'owner_contract_version' => $identity['owner_contract_version'],
            'institution_public_id' => $identity['institution_public_id'],
            'engine_version' => (string) cpe_config('app.version'),
            'archive_sha256' => strtolower($archiveSha256),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @return array<string, int|string> */
    public static function read(string $path): array
    {
        if (is_link($path) || !is_file($path)) {
            throw new UserVisibleException(
                'DATABASE_BACKUP_METADATA_REQUIRED',
                'A backup metadata file is required.',
            );
        }
        $size = @filesize($path);
        if ($size === false || $size < 2 || $size > self::MAX_BYTES) {
            throw new UserVisibleException(
                'DATABASE_BACKUP_METADATA_INVALID',
                'The backup metadata file is invalid.',
            );
        }
        try {
            $contents = @file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Backup metadata could not be read.');
            }
            $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new UserVisibleException(
                'DATABASE_BACKUP_METADATA_INVALID',
                'The backup metadata file is invalid.',
                $e,
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new UserVisibleException(
                'DATABASE_BACKUP_METADATA_INVALID',
                'The backup metadata file is invalid.',
            );
        }
        return self::validate($decoded);
    }

    /** @return array{owner_kind: string, owner_contract_version: int, institution_public_id: string} */
    public static function databaseIdentity(PDO $pdo, string $driver): array
    {
        if (!in_array($driver, ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('Database backup metadata requires a supported database driver.');
        }
        $ownershipColumns = $driver === 'sqlite'
            ? ', typeof(singleton_id) AS singleton_storage, typeof(contract_version) AS version_storage'
            : '';
        $ownership = $pdo->query(
            'SELECT singleton_id, owner_kind, contract_version' . $ownershipColumns
            . ' FROM cpe_database_ownership',
        )->fetchAll(PDO::FETCH_ASSOC);
        $institutions = $pdo->query(
            "SELECT public_id FROM institutions WHERE slug = 'default'",
        )->fetchAll(PDO::FETCH_ASSOC);
        $installedAt = $pdo->query(
            "SELECT value FROM settings WHERE key = 'installed_at'",
        )->fetchAll(PDO::FETCH_COLUMN);
        if (count($ownership) !== 1
            || (int) ($ownership[0]['singleton_id'] ?? 0) !== 1
            || (string) ($ownership[0]['owner_kind'] ?? '') !== DatabaseOwnership::OWNER_ENGINE_INSTITUTION
            || (int) ($ownership[0]['contract_version'] ?? 0) !== DatabaseOwnership::CONTRACT_VERSION
            || ($driver === 'sqlite'
                && (($ownership[0]['singleton_storage'] ?? null) !== 'integer'
                    || ($ownership[0]['version_storage'] ?? null) !== 'integer'))
            || count($institutions) !== 1
            || preg_match('/\A(?:inst|tenant)_[a-f0-9]{32}\z/D', (string) ($institutions[0]['public_id'] ?? '')) !== 1
            || count($installedAt) !== 1
            || trim((string) $installedAt[0]) === '') {
            throw new RuntimeException('Database backup identity evidence is incomplete or invalid.');
        }
        return [
            'owner_kind' => DatabaseOwnership::OWNER_ENGINE_INSTITUTION,
            'owner_contract_version' => DatabaseOwnership::CONTRACT_VERSION,
            'institution_public_id' => (string) $institutions[0]['public_id'],
        ];
    }

    /** @param array<string, int|string> $metadata */
    public static function createdAtTimestamp(array $metadata): int
    {
        $value = $metadata['created_at'] ?? null;
        if (!is_string($value)) {
            throw self::invalidMetadata();
        }
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))
            || $date->format('Y-m-d\TH:i:s\Z') !== $value
            || $date->getTimestamp() > time() + self::MAX_FUTURE_SKEW_SECONDS) {
            throw self::invalidMetadata();
        }
        return $date->getTimestamp();
    }

    /** @param array<string, mixed> $metadata
     *  @return array<string, int|string>
     */
    private static function validate(array $metadata): array
    {
        $expectedKeys = [
            'archive_sha256',
            'created_at',
            'driver',
            'engine_version',
            'institution_public_id',
            'owner_contract_version',
            'owner_kind',
            'schema',
        ];
        $keys = array_keys($metadata);
        sort($keys);
        if ($keys !== $expectedKeys
            || ($metadata['schema'] ?? null) !== self::SCHEMA
            || !in_array($metadata['driver'] ?? null, ['sqlite', 'pgsql'], true)
            || ($metadata['owner_kind'] ?? null) !== DatabaseOwnership::OWNER_ENGINE_INSTITUTION
            || !is_int($metadata['owner_contract_version'] ?? null)
            || $metadata['owner_contract_version'] !== DatabaseOwnership::CONTRACT_VERSION
            || !is_string($metadata['institution_public_id'] ?? null)
            || preg_match('/\A(?:inst|tenant)_[a-f0-9]{32}\z/D', $metadata['institution_public_id']) !== 1
            || !is_string($metadata['engine_version'] ?? null)
            || preg_match('/\A[0-9A-Za-z][0-9A-Za-z._+-]{0,63}\z/D', $metadata['engine_version']) !== 1
            || !is_string($metadata['archive_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $metadata['archive_sha256']) !== 1
            || !is_string($metadata['created_at'] ?? null)
            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $metadata['created_at']) !== 1) {
            throw self::invalidMetadata();
        }
        self::createdAtTimestamp($metadata);
        /** @var array<string, int|string> $metadata */
        return $metadata;
    }

    private static function invalidMetadata(): UserVisibleException
    {
        return new UserVisibleException(
            'DATABASE_BACKUP_METADATA_INVALID',
            'The backup metadata file is invalid.',
        );
    }
}
