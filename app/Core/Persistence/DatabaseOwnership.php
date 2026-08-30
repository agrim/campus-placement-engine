<?php

declare(strict_types=1);

namespace App\Core\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseOwnership
{
    public const CONTRACT_VERSION = 1;
    public const OWNER_CLOUD_CONTROL_PLANE = 'cloud_control_plane';
    public const OWNER_ENGINE_INSTITUTION = 'engine_institution';

    public const ERROR_AMBIGUOUS = 'CPE_DATABASE_OWNERSHIP_AMBIGUOUS';
    public const ERROR_CONFLICT = 'CPE_DATABASE_OWNERSHIP_CONFLICT';
    public const ERROR_CLEANUP = 'CPE_DATABASE_OWNERSHIP_CLEANUP_FAILED';
    public const ERROR_CORRUPT = 'CPE_DATABASE_OWNERSHIP_CORRUPT';
    public const ERROR_VERSION = 'CPE_DATABASE_OWNERSHIP_VERSION_UNSUPPORTED';

    private const LOCK_NAMESPACE = 'cpe.database-ownership';
    private const TABLE = 'cpe_database_ownership';
    private const ENGINE_MARKERS = ['migrations', 'settings', 'users', 'candidates', 'companies', 'applications'];
    private const CLOUD_MARKERS = ['hosted_migrations', 'hosted_tenants', 'hosted_deployments'];

    public static function claimOrVerify(PDO $pdo, string $expectedOwner): void
    {
        self::claimOrVerifyWithInstalledIdentity($pdo, $expectedOwner, null);
    }

    public static function claimOrVerifyInstalledEngine(PDO $pdo, string $expectedInstitutionPublicId): void
    {
        if (preg_match('/\A(?:inst|tenant)_[a-f0-9]{32}\z/D', $expectedInstitutionPublicId) !== 1) {
            throw new RuntimeException(self::ERROR_CORRUPT . ': installed institution identity is invalid.');
        }
        self::claimOrVerifyWithInstalledIdentity(
            $pdo,
            self::OWNER_ENGINE_INSTITUTION,
            $expectedInstitutionPublicId,
        );
    }

    public static function strictInstalledEngineIdentity(PDO $pdo, bool $lockRows = false): string
    {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if (!in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(DatabaseLock::ERROR_UNSUPPORTED . ': unsupported database driver.');
        }
        $schema = $driver === 'sqlite'
            ? 'main'
            : trim((string) $pdo->query('SELECT current_schema()')->fetchColumn());
        if ($schema === '' || ($driver === 'pgsql' && self::isSystemPostgresSchema($schema))) {
            throw new RuntimeException(self::ERROR_AMBIGUOUS . ': application schema is unavailable.');
        }
        $qualify = static fn (string $table): string => $driver === 'sqlite'
            ? 'main.' . self::quoteIdentifier($table)
            : self::quoteIdentifier($schema) . '.' . self::quoteIdentifier($table);
        $lock = $driver === 'pgsql' && $lockRows ? ' FOR UPDATE' : '';
        $institutionRows = $pdo->query(
            'SELECT public_id FROM ' . $qualify('institutions') . " WHERE slug = 'default'" . $lock,
        )->fetchAll(PDO::FETCH_COLUMN);
        $installedRows = $pdo->query(
            'SELECT value FROM ' . $qualify('settings') . " WHERE key = 'installed_at'" . $lock,
        )->fetchAll(PDO::FETCH_COLUMN);
        $identity = count($institutionRows) === 1 ? (string) $institutionRows[0] : '';
        if (preg_match('/\A(?:inst|tenant)_[a-f0-9]{32}\z/D', $identity) !== 1
            || count($installedRows) !== 1
            || trim((string) $installedRows[0]) === '') {
            throw new RuntimeException(self::ERROR_CORRUPT . ': installed Engine identity evidence is incomplete or invalid.');
        }
        return $identity;
    }

    public static function assertLegacyEngineSignature(PDO $pdo): void
    {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver !== 'sqlite') {
            throw new RuntimeException(self::ERROR_AMBIGUOUS . ': legacy backup conversion supports SQLite only.');
        }
        $relations = self::relations($pdo, $driver);
        if ($relations === [] || array_filter(
            $relations,
            static fn (array $relation): bool => $relation['name'] === self::TABLE,
        ) !== []) {
            throw new RuntimeException(self::ERROR_AMBIGUOUS . ': database is not an unowned legacy Engine database.');
        }
        self::assertLegacyClaimAllowed($relations, 'main', self::OWNER_ENGINE_INSTITUTION);
    }

    public static function targetIsEmptyStrict(PDO $pdo): bool
    {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if (!in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(DatabaseLock::ERROR_UNSUPPORTED . ': unsupported database driver.');
        }
        if ($driver === 'pgsql') {
            $schema = trim((string) $pdo->query('SELECT current_schema()')->fetchColumn());
            if ($schema === '' || self::isSystemPostgresSchema($schema)) {
                throw new RuntimeException(self::ERROR_AMBIGUOUS . ': application schema is unavailable.');
            }
        }
        return self::relations($pdo, $driver) === [];
    }

    /** Read-only ownership proof for request-time installation-state checks. */
    public static function assertOwnedByReadOnlyStrict(PDO $pdo, string $expectedOwner): void
    {
        if (!in_array($expectedOwner, [self::OWNER_ENGINE_INSTITUTION, self::OWNER_CLOUD_CONTROL_PLANE], true)) {
            throw new RuntimeException(self::ERROR_CORRUPT . ': unsupported expected database owner.');
        }
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if (!in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(DatabaseLock::ERROR_UNSUPPORTED . ': unsupported database driver.');
        }
        $schema = $driver === 'sqlite'
            ? 'main'
            : trim((string) $pdo->query('SELECT current_schema()')->fetchColumn());
        if ($schema === '' || ($driver === 'pgsql' && self::isSystemPostgresSchema($schema))) {
            throw new RuntimeException(self::ERROR_AMBIGUOUS . ': application schema is unavailable.');
        }
        $relations = self::relations($pdo, $driver);
        $ownership = array_values(array_filter(
            $relations,
            static fn (array $relation): bool => $relation['name'] === self::TABLE,
        ));
        if (count($ownership) !== 1 || !in_array($ownership[0]['kind'], ['r', 'p'], true)) {
            throw new RuntimeException(self::ERROR_AMBIGUOUS . ': canonical database ownership is unavailable.');
        }
        self::verifyTableAndRow(
            $pdo,
            $driver,
            $ownership[0]['schema'],
            $schema,
            $expectedOwner,
            $relations,
            false,
            false,
        );
    }

    private static function claimOrVerifyWithInstalledIdentity(
        PDO $pdo,
        string $expectedOwner,
        ?string $expectedInstitutionPublicId,
    ): void
    {
        if (!in_array($expectedOwner, [self::OWNER_ENGINE_INSTITUTION, self::OWNER_CLOUD_CONTROL_PLANE], true)) {
            throw new RuntimeException(self::ERROR_CORRUPT . ': unsupported expected database owner.');
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException(DatabaseLock::ERROR_ACTIVE_TRANSACTION . ': database ownership must be verified before starting a transaction.');
        }

        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if (!in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(DatabaseLock::ERROR_UNSUPPORTED . ': unsupported database driver ' . $driver . '.');
        }

        DatabaseLock::synchronized(
            $pdo,
            self::LOCK_NAMESPACE,
            function (?string $lockBackendPid) use (
                $pdo,
                $driver,
                $expectedOwner,
                $expectedInstitutionPublicId,
            ): void {
                self::transaction(
                    $pdo,
                    $driver,
                    $lockBackendPid,
                    function (string $currentSchema) use (
                        $pdo,
                        $driver,
                        $expectedOwner,
                        $expectedInstitutionPublicId,
                    ): void {
                        $relations = self::relations($pdo, $driver);
                        $ownershipRelations = array_values(array_filter(
                            $relations,
                            static fn (array $relation): bool => $relation['name'] === self::TABLE,
                        ));

                        if (count($ownershipRelations) > 1) {
                            throw new RuntimeException(self::ERROR_AMBIGUOUS . ': multiple database ownership relations exist across application schemas.');
                        }

                        if ($ownershipRelations === []) {
                            self::assertLegacyClaimAllowed($relations, $currentSchema, $expectedOwner);
                            self::createTable($pdo, $driver, $currentSchema);
                            self::insertClaim($pdo, $driver, $currentSchema, $expectedOwner);
                            self::verifyTableAndRow($pdo, $driver, $currentSchema, $currentSchema, $expectedOwner, self::relations($pdo, $driver));
                        } else {
                            $ownershipRelation = $ownershipRelations[0];
                            if (!in_array($ownershipRelation['kind'], ['r', 'p'], true)) {
                                throw new RuntimeException(self::ERROR_CORRUPT . ': database ownership name is not an ordinary or partitioned table.');
                            }
                            self::verifyTableAndRow(
                                $pdo,
                                $driver,
                                $ownershipRelation['schema'],
                                $currentSchema,
                                $expectedOwner,
                                $relations,
                            );
                        }
                        if ($expectedInstitutionPublicId !== null) {
                            $actual = self::strictInstalledEngineIdentity($pdo, true);
                            if (!hash_equals($expectedInstitutionPublicId, $actual)) {
                                throw new RuntimeException(self::ERROR_CORRUPT . ': installed institution identity changed during ownership verification.');
                            }
                        }
                    },
                );
            },
        );
    }

    /** @param callable(string): void $operation */
    private static function transaction(PDO $pdo, string $driver, ?string $lockBackendPid, callable $operation): void
    {
        $started = false;
        try {
            if ($driver === 'sqlite') {
                $pdo->exec('BEGIN IMMEDIATE');
                $currentSchema = 'main';
            } else {
                $pdo->beginTransaction();
                $started = true;
                if ($lockBackendPid === null) {
                    throw DatabaseLockException::sessionChanged();
                }
                DatabaseLock::assertPostgresSession($pdo, $lockBackendPid);
                $currentSchema = trim((string) $pdo->query('SELECT current_schema()')->fetchColumn());
                if ($currentSchema === '' || self::isSystemPostgresSchema($currentSchema)) {
                    throw new RuntimeException(self::ERROR_AMBIGUOUS . ': PostgreSQL search_path does not resolve to an application schema.');
                }
            }
            $started = true;
            $operation($currentSchema);
            if ($driver === 'sqlite') {
                $pdo->exec('COMMIT');
            } else {
                if ($lockBackendPid === null) {
                    throw DatabaseLockException::sessionChanged();
                }
                DatabaseLock::assertPostgresSession($pdo, $lockBackendPid);
                $pdo->commit();
            }
            $started = false;
        } catch (Throwable $e) {
            if ($started) {
                try {
                    if ($driver === 'sqlite') {
                        $pdo->exec('ROLLBACK');
                    } elseif ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable $cleanup) {
                    throw DatabaseConnectionInvalidException::cleanupFailed(
                        self::ERROR_CLEANUP,
                        $e,
                        $cleanup,
                    );
                }
            }
            throw $e;
        }
    }

    /** @param array<int, array{schema: string, name: string, kind: string}> $relations */
    private static function assertLegacyClaimAllowed(array $relations, string $currentSchema, string $expectedOwner): void
    {
        if ($relations === []) {
            return;
        }

        $allMarkers = array_merge(self::ENGINE_MARKERS, self::CLOUD_MARKERS);
        $reserved = array_values(array_filter(
            $relations,
            static fn (array $relation): bool => in_array($relation['name'], $allMarkers, true),
        ));
        foreach ($reserved as $relation) {
            if (!in_array($relation['kind'], ['r', 'p'], true)) {
                throw new RuntimeException(self::ERROR_AMBIGUOUS . ': a reserved legacy marker is not an ordinary or partitioned table.');
            }
        }

        $markerSchemas = array_values(array_unique(array_column($reserved, 'schema')));
        if (count($markerSchemas) > 1) {
            throw new RuntimeException(self::ERROR_AMBIGUOUS . ': legacy database markers span multiple application schemas.');
        }
        if ($markerSchemas !== [] && $markerSchemas[0] !== $currentSchema) {
            throw new RuntimeException(self::ERROR_AMBIGUOUS . ': legacy database markers do not belong to the resolved application schema.');
        }

        $relationSchemas = array_values(array_unique(array_column($relations, 'schema')));
        if (count($relationSchemas) > 1) {
            throw new RuntimeException(self::ERROR_AMBIGUOUS . ': legacy database contains relations across multiple application schemas.');
        }

        $markerNames = array_values(array_unique(array_column($reserved, 'name')));
        $engineCount = count(array_intersect(self::ENGINE_MARKERS, $markerNames));
        $cloudCount = count(array_intersect(self::CLOUD_MARKERS, $markerNames));
        if ($engineCount === count(self::ENGINE_MARKERS) && $cloudCount === 0) {
            if ($expectedOwner === self::OWNER_ENGINE_INSTITUTION) {
                return;
            }
            throw new RuntimeException(self::ERROR_CONFLICT . ': legacy database has the opposite complete Engine signature.');
        }
        if ($cloudCount === count(self::CLOUD_MARKERS) && $engineCount === 0) {
            if ($expectedOwner === self::OWNER_CLOUD_CONTROL_PLANE) {
                return;
            }
            throw new RuntimeException(self::ERROR_CONFLICT . ': legacy database has the opposite complete Cloud signature.');
        }

        $classification = $engineCount > 0 && $cloudCount > 0
            ? 'mixed'
            : (($engineCount > 0 || $cloudCount > 0) ? 'partial' : 'unknown');
        throw new RuntimeException(
            self::ERROR_AMBIGUOUS . ': refusing to claim a ' . $classification . ' legacy database without an ownership row.',
        );
    }

    /** @param array<int, array{schema: string, name: string, kind: string}> $relations */
    private static function verifyTableAndRow(
        PDO $pdo,
        string $driver,
        string $ownershipSchema,
        string $currentSchema,
        string $expectedOwner,
        array $relations,
        bool $lockRows = true,
        bool $assertConstraints = true,
    ): void {
        self::assertTableShape($pdo, $driver, $ownershipSchema);
        $table = self::qualifiedTable($driver, $ownershipSchema);
        $storageColumns = $driver === 'sqlite'
            ? ', typeof(singleton_id) AS singleton_storage, typeof(contract_version) AS version_storage'
            : '';
        $rows = $pdo->query(
            'SELECT singleton_id, owner_kind, contract_version, claimed_at' . $storageColumns
            . ' FROM ' . $table . ($driver === 'pgsql' && $lockRows ? ' FOR UPDATE' : ''),
        )->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new RuntimeException(self::ERROR_CORRUPT . ': database ownership table must contain exactly one row.');
        }
        self::assertOwnershipRow($rows[0], $expectedOwner, $driver);
        if ($assertConstraints) {
            self::assertConstraintInvariants(
                $pdo,
                $table,
                (string) $rows[0]['owner_kind'],
                (string) $rows[0]['claimed_at'],
            );
        }

        if ($driver === 'pgsql' && $ownershipSchema !== $currentSchema) {
            throw new RuntimeException(self::ERROR_AMBIGUOUS . ': search_path does not resolve to the canonical ownership schema.');
        }
        self::assertReservedMarkers($relations, $ownershipSchema, $expectedOwner);
    }

    private static function assertOwnershipRow(array $row, string $expectedOwner, string $driver): void
    {
        $singleton = self::exactInteger($row['singleton_id'] ?? null);
        if ($singleton !== 1
            || ($driver === 'sqlite' && ($row['singleton_storage'] ?? null) !== 'integer')) {
            throw new RuntimeException(self::ERROR_CORRUPT . ': database ownership singleton is invalid.');
        }

        $owner = (string) ($row['owner_kind'] ?? '');
        if (!in_array($owner, [self::OWNER_ENGINE_INSTITUTION, self::OWNER_CLOUD_CONTROL_PLANE], true)) {
            throw new RuntimeException(self::ERROR_CORRUPT . ': database ownership value is invalid.');
        }

        $version = self::exactInteger($row['contract_version'] ?? null);
        if ($version === null
            || ($driver === 'sqlite' && ($row['version_storage'] ?? null) !== 'integer')) {
            throw new RuntimeException(self::ERROR_CORRUPT . ': database ownership contract version storage is invalid.');
        }
        if ($version !== self::CONTRACT_VERSION) {
            throw new RuntimeException(
                self::ERROR_VERSION . ': database ownership contract version ' . $version . ' is not supported.',
            );
        }

        $claimedAt = (string) ($row['claimed_at'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $claimedAt, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $claimedAt) {
            throw new RuntimeException(self::ERROR_CORRUPT . ': database ownership claim timestamp is not canonical UTC.');
        }

        if (!hash_equals($expectedOwner, $owner)) {
            throw new RuntimeException(self::ERROR_CONFLICT . ': database is permanently owned by the other CPE plane.');
        }
    }

    /** @param array<int, array{schema: string, name: string, kind: string}> $relations */
    private static function assertReservedMarkers(array $relations, string $ownershipSchema, string $expectedOwner): void
    {
        $oppositeMarkers = $expectedOwner === self::OWNER_ENGINE_INSTITUTION
            ? self::CLOUD_MARKERS
            : self::ENGINE_MARKERS;
        $allMarkers = array_merge(self::ENGINE_MARKERS, self::CLOUD_MARKERS);
        foreach ($relations as $relation) {
            if (!in_array($relation['name'], $allMarkers, true)) {
                continue;
            }
            if (!in_array($relation['kind'], ['r', 'p'], true)) {
                throw new RuntimeException(self::ERROR_AMBIGUOUS . ': a reserved marker is not an ordinary or partitioned table.');
            }
            if (in_array($relation['name'], $oppositeMarkers, true)) {
                throw new RuntimeException(self::ERROR_CONFLICT . ': owned database contains reserved markers from the other CPE plane.');
            }
            if ($relation['schema'] !== $ownershipSchema) {
                throw new RuntimeException(self::ERROR_AMBIGUOUS . ': owned database markers span multiple application schemas.');
            }
        }
    }

    private static function createTable(PDO $pdo, string $driver, string $schema): void
    {
        $pdo->exec(
            'CREATE TABLE ' . self::qualifiedTable($driver, $schema) . ' ('
            . 'singleton_id INTEGER NOT NULL, '
            . 'owner_kind TEXT NOT NULL, '
            . 'contract_version INTEGER NOT NULL, '
            . 'claimed_at TEXT NOT NULL, '
            . 'CONSTRAINT cpe_database_ownership_pk PRIMARY KEY (singleton_id), '
            . 'CONSTRAINT cpe_database_ownership_singleton_check CHECK (singleton_id = 1), '
            . "CONSTRAINT cpe_database_ownership_owner_check CHECK (owner_kind IN ('engine_institution', 'cloud_control_plane')), "
            . 'CONSTRAINT cpe_database_ownership_version_check CHECK (contract_version >= 1)'
            . ')',
        );
        if ($driver === 'sqlite') {
            $pdo->exec(
                'CREATE TRIGGER main.cpe_database_ownership_immutable '
                . 'BEFORE UPDATE OF owner_kind, claimed_at ON cpe_database_ownership '
                . 'FOR EACH ROW WHEN NEW.owner_kind IS NOT OLD.owner_kind OR NEW.claimed_at IS NOT OLD.claimed_at '
                . "BEGIN SELECT RAISE(ABORT, 'database ownership identity is immutable'); END",
            );
            return;
        }

        $function = self::quoteIdentifier($schema) . '.' . self::quoteIdentifier('cpe_database_ownership_immutable');
        $pdo->exec(
            'CREATE FUNCTION ' . $function . '() RETURNS trigger LANGUAGE plpgsql AS $cpe$ '
            . 'BEGIN IF NEW.owner_kind IS DISTINCT FROM OLD.owner_kind '
            . "OR NEW.claimed_at IS DISTINCT FROM OLD.claimed_at THEN RAISE EXCEPTION 'database ownership identity is immutable'; "
            . 'END IF; RETURN NEW; END; $cpe$',
        );
        $pdo->exec(
            'CREATE TRIGGER cpe_database_ownership_immutable '
            . 'BEFORE UPDATE OF owner_kind, claimed_at ON ' . self::qualifiedTable($driver, $schema) . ' '
            . 'FOR EACH ROW EXECUTE FUNCTION ' . $function . '()',
        );
    }

    private static function insertClaim(PDO $pdo, string $driver, string $schema, string $expectedOwner): void
    {
        $insert = $pdo->prepare(
            'INSERT INTO ' . self::qualifiedTable($driver, $schema)
            . ' (singleton_id, owner_kind, contract_version, claimed_at) VALUES (?, ?, ?, ?)',
        );
        $insert->execute([1, $expectedOwner, self::CONTRACT_VERSION, gmdate('Y-m-d\TH:i:s\Z')]);
    }

    private static function assertTableShape(PDO $pdo, string $driver, string $schema): void
    {
        if ($driver === 'sqlite') {
            $columns = $pdo->query('PRAGMA main.table_xinfo(cpe_database_ownership)')->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $columnsQuery = $pdo->prepare(
                "SELECT a.attname AS name,
                        pg_catalog.format_type(a.atttypid, a.atttypmod) AS type,
                        a.attnotnull AS not_null,
                        pg_catalog.pg_get_expr(d.adbin, d.adrelid) AS default_value,
                        a.attgenerated AS generated_kind,
                        a.attidentity AS identity_kind
                 FROM pg_catalog.pg_attribute a
                 JOIN pg_catalog.pg_class c ON c.oid = a.attrelid
                 JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                 LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
                 WHERE n.nspname = CAST(? AS TEXT)
                   AND c.relname = 'cpe_database_ownership'
                   AND c.relkind IN ('r', 'p')
                   AND a.attnum > 0
                   AND NOT a.attisdropped
                 ORDER BY a.attnum",
            );
            $columnsQuery->execute([$schema]);
            $columns = $columnsQuery->fetchAll(PDO::FETCH_ASSOC);
        }

        $expected = [
            ['singleton_id', 'INTEGER', 1, 1],
            ['owner_kind', 'TEXT', 1, 0],
            ['contract_version', 'INTEGER', 1, 0],
            ['claimed_at', 'TEXT', 1, 0],
        ];
        if (count($columns) !== count($expected)) {
            throw new RuntimeException(self::ERROR_CORRUPT . ': database ownership table columns are invalid.');
        }
        foreach ($expected as $index => [$name, $type, $notNull, $primaryKey]) {
            $column = $columns[$index];
            $actualType = strtoupper(trim((string) ($column['type'] ?? '')));
            $actualNotNull = $driver === 'sqlite'
                ? (int) ($column['notnull'] ?? 0)
                : (self::postgresBoolean($column['not_null'] ?? false) ? 1 : 0);
            $actualPrimaryKey = $driver === 'sqlite' ? (int) ($column['pk'] ?? 0) : 0;
            $default = $driver === 'sqlite' ? ($column['dflt_value'] ?? null) : ($column['default_value'] ?? null);
            $generated = $driver === 'sqlite'
                ? (int) ($column['hidden'] ?? 0)
                : ((string) ($column['generated_kind'] ?? '') !== '' || (string) ($column['identity_kind'] ?? '') !== '' ? 1 : 0);
            if ((string) ($column['name'] ?? '') !== $name
                || $actualType !== $type
                || $actualNotNull !== $notNull
                || ($driver === 'sqlite' && $actualPrimaryKey !== $primaryKey)
                || $generated !== 0
                || $default !== null) {
                throw new RuntimeException(self::ERROR_CORRUPT . ': database ownership table columns are invalid.');
            }
        }

        if ($driver === 'pgsql') {
            $primaryKeyQuery = $pdo->prepare(
                "SELECT a.attname
                 FROM pg_catalog.pg_class c
                 JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                 JOIN pg_catalog.pg_index i ON i.indrelid = c.oid AND i.indisprimary
                 CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS key(attnum, position)
                 JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid AND a.attnum = key.attnum
                 WHERE n.nspname = CAST(? AS TEXT) AND c.relname = 'cpe_database_ownership'
                 ORDER BY key.position",
            );
            $primaryKeyQuery->execute([$schema]);
            if ($primaryKeyQuery->fetchAll(PDO::FETCH_COLUMN) !== ['singleton_id']) {
                throw new RuntimeException(self::ERROR_CORRUPT . ': database ownership primary key is invalid.');
            }
        }
    }

    private static function assertConstraintInvariants(PDO $pdo, string $table, string $owner, string $claimedAt): void
    {
        $quotedOwner = $pdo->quote($owner);
        $quotedClaimedAt = $pdo->quote($claimedAt);
        self::assertMutationAllowed(
            $pdo,
            'UPDATE ' . $table . ' SET contract_version = 2 WHERE singleton_id = 1',
            'valid contract-version update',
        );
        self::assertMutationAllowed(
            $pdo,
            'DELETE FROM ' . $table . '; INSERT INTO ' . $table
            . ' (singleton_id, owner_kind, contract_version, claimed_at) VALUES (1, '
            . $quotedOwner . ', 1, ' . $quotedClaimedAt . ')',
            'valid row replacement',
        );
        self::assertMutationRejected(
            $pdo,
            'DELETE FROM ' . $table . '; INSERT INTO ' . $table
            . ' (singleton_id, owner_kind, contract_version, claimed_at) VALUES (2, '
            . $quotedOwner . ', 1, ' . $quotedClaimedAt . ')',
            'singleton insert',
        );
        self::assertMutationRejected(
            $pdo,
            'DELETE FROM ' . $table . '; INSERT INTO ' . $table
            . " (singleton_id, owner_kind, contract_version, claimed_at) VALUES (1, 'invalid_owner', 1, "
            . $quotedClaimedAt . ')',
            'owner-kind insert',
        );
        self::assertMutationRejected(
            $pdo,
            'DELETE FROM ' . $table . '; INSERT INTO ' . $table
            . ' (singleton_id, owner_kind, contract_version, claimed_at) VALUES (1, '
            . $quotedOwner . ', 0, ' . $quotedClaimedAt . ')',
            'contract-version insert',
        );
        $oppositeOwner = $owner === self::OWNER_ENGINE_INSTITUTION
            ? self::OWNER_CLOUD_CONTROL_PLANE
            : self::OWNER_ENGINE_INSTITUTION;
        self::assertMutationRejected(
            $pdo,
            'UPDATE ' . $table . ' SET owner_kind = ' . $pdo->quote($oppositeOwner) . ' WHERE singleton_id = 1',
            'owner immutability',
        );
        self::assertMutationRejected(
            $pdo,
            "UPDATE " . $table . " SET claimed_at = '2000-01-01T00:00:00Z' WHERE singleton_id = 1",
            'claim timestamp immutability',
        );
    }

    private static function assertMutationAllowed(PDO $pdo, string $sql, string $label): void
    {
        $savepoint = 'cpe_database_ownership_positive_invariant';
        $pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            self::rollbackProbe($pdo, $savepoint);
            throw new RuntimeException(
                self::ERROR_CORRUPT . ': database ownership ' . $label . ' was unexpectedly rejected.',
                0,
                $e,
            );
        }
        self::rollbackProbe($pdo, $savepoint);
    }

    private static function assertMutationRejected(PDO $pdo, string $sql, string $label): void
    {
        $savepoint = 'cpe_database_ownership_invariant';
        $pdo->exec('SAVEPOINT ' . $savepoint);
        $rejected = false;
        try {
            $pdo->exec($sql);
        } catch (Throwable) {
            $rejected = true;
        }
        self::rollbackProbe($pdo, $savepoint);
        if (!$rejected) {
            throw new RuntimeException(self::ERROR_CORRUPT . ': database ownership ' . $label . ' constraint is invalid.');
        }
    }

    private static function rollbackProbe(PDO $pdo, string $savepoint): void
    {
        try {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        } catch (Throwable $e) {
            throw new RuntimeException(self::ERROR_CORRUPT . ': database ownership constraint validation could not recover.', 0, $e);
        }
    }

    /** @return array<int, array{schema: string, name: string, kind: string}> */
    private static function relations(PDO $pdo, string $driver): array
    {
        if ($driver === 'sqlite') {
            $tableKinds = [];
            foreach ($pdo->query('PRAGMA main.table_list')->fetchAll(PDO::FETCH_ASSOC) as $table) {
                if (($table['schema'] ?? null) === 'main') {
                    $tableKinds[(string) ($table['name'] ?? '')] = strtolower((string) ($table['type'] ?? ''));
                }
            }
            $rows = $pdo->query(
                "SELECT 'main' AS schema_name, name, type, sql
                 FROM main.sqlite_schema
                 WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'
                 ORDER BY name",
            )->fetchAll(PDO::FETCH_ASSOC);
            return array_map(
                static function (array $row) use ($tableKinds): array {
                    $name = (string) ($row['name'] ?? '');
                    $tableKind = $tableKinds[$name] ?? '';
                    $sql = ltrim((string) ($row['sql'] ?? ''));
                    $kind = ($row['type'] ?? '') === 'view' ? 'v' : 'r';
                    if ($tableKind === 'virtual' || preg_match('/^CREATE\s+VIRTUAL\s+TABLE\b/i', $sql) === 1) {
                        $kind = 'virtual';
                    } elseif ($tableKind === 'shadow') {
                        $kind = 'shadow';
                    }
                    return ['schema' => 'main', 'name' => $name, 'kind' => $kind];
                },
                $rows,
            );
        }

        $rows = $pdo->query(
            "SELECT n.nspname AS schema_name, c.relname AS name, c.relkind AS kind
             FROM pg_catalog.pg_class c
             JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname <> 'information_schema'
               AND n.nspname NOT LIKE 'pg\\_%' ESCAPE '\\'
               AND c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S')
             ORDER BY n.nspname, c.relname",
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(
            static fn (array $row): array => [
                'schema' => (string) ($row['schema_name'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'kind' => (string) ($row['kind'] ?? ''),
            ],
            $rows,
        );
    }

    private static function qualifiedTable(string $driver, string $schema): string
    {
        if ($driver === 'sqlite') {
            return 'main.cpe_database_ownership';
        }
        return self::quoteIdentifier($schema) . '.' . self::quoteIdentifier(self::TABLE);
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private static function isSystemPostgresSchema(string $schema): bool
    {
        return $schema === 'information_schema' || str_starts_with($schema, 'pg_');
    }

    private static function exactInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?(0|[1-9][0-9]*)$/', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($integer) ? $integer : null;
    }

    private static function postgresBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
