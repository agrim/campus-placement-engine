<?php

declare(strict_types=1);

namespace App\Core\Persistence;

use PDO;
use RuntimeException;
use Throwable;

final class SqlMigrationRunner
{
    public const CONTRACT_VERSION = 1;
    public const ERROR_CALLBACK_TRANSACTION = 'CPE_SQL_MIGRATION_CALLBACK_TRANSACTION';
    public const ERROR_CLEANUP = 'CPE_SQL_MIGRATION_CLEANUP_FAILED';
    public const ERROR_DIRECTORY = 'CPE_SQL_MIGRATION_DIRECTORY_INVALID';
    public const ERROR_MIGRATION = 'CPE_SQL_MIGRATION_FAILED';
    public const ERROR_REGISTRY = 'CPE_SQL_MIGRATION_REGISTRY_INVALID';

    private readonly string $migrationDirectory;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $registry,
        string $migrationDirectory,
        private readonly string $lockNamespace,
        private readonly int $timeoutMilliseconds = 5000,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $registry) !== 1) {
            throw new RuntimeException(self::ERROR_REGISTRY . ': registry must be a strict lowercase SQL identifier.');
        }
        if ($migrationDirectory === '' || !str_starts_with($migrationDirectory, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(self::ERROR_DIRECTORY . ': migration directory must be absolute.');
        }
        $canonicalDirectory = realpath($migrationDirectory);
        if ($canonicalDirectory === false || !is_dir($canonicalDirectory) || !is_readable($canonicalDirectory)) {
            throw new RuntimeException(self::ERROR_DIRECTORY . ': migration directory must exist and be readable.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $lockNamespace) !== 1) {
            throw new RuntimeException(DatabaseLock::ERROR_UNSUPPORTED . ': invalid migration lock namespace.');
        }
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 60000) {
            throw new RuntimeException(DatabaseLock::ERROR_UNSUPPORTED . ': migration lock timeout must be between 1 and 60000 milliseconds.');
        }
        $this->migrationDirectory = $canonicalDirectory;
    }

    /** @param null|callable(PDO): void $afterMigrations */
    public function run(?callable $afterMigrations = null): void
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException(DatabaseLock::ERROR_ACTIVE_TRANSACTION . ': migrations must start outside a transaction.');
        }
        $driver = strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if (!in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(DatabaseLock::ERROR_UNSUPPORTED . ': unsupported migration database driver ' . $driver . '.');
        }

        DatabaseLock::synchronized(
            $this->pdo,
            $this->lockNamespace,
            function (?string $lockBackendPid) use ($driver, $afterMigrations): void {
                $files = $this->migrationFiles();
                if ($driver === 'sqlite' && $this->isFilelessSqlite()) {
                    $table = $this->qualifiedSqliteRegistry();
                    $this->runFilelessSqlite($table, $files, $afterMigrations);
                    return;
                }
                if ($driver === 'pgsql') {
                    [$table, $applied] = $this->initializePostgresRegistry($lockBackendPid);
                } else {
                    $table = $this->qualifiedSqliteRegistry();
                    $this->createAndVerifyRegistry($driver, $table);
                    $applied = $this->appliedSet($table);
                }
                $this->runPendingMigrations($driver, $table, $files, $lockBackendPid, $applied);
                $this->assertDiscoveredExactlyOnce($table, $files);
                $this->runCallbackWithoutTransaction($afterMigrations);
            },
            $this->timeoutMilliseconds,
        );
    }

    /**
     * @param array<int, string> $files
     * @param null|callable(PDO): void $afterMigrations
     */
    private function runFilelessSqlite(string $table, array $files, ?callable $afterMigrations): void
    {
        $started = false;
        $callbackFailure = null;
        try {
            $this->pdo->beginTransaction();
            $started = true;
            $this->createAndVerifyRegistry('sqlite', $table);
            $applied = $this->appliedSet($table);
            foreach ($files as $file) {
                $name = basename($file);
                if (!isset($applied[$name])) {
                    $this->executeMigrationInCurrentTransaction('sqlite', $table, $file);
                    $applied[$name] = 1;
                }
            }
            $this->assertDiscoveredExactlyOnce($table, $files);

            if ($afterMigrations !== null) {
                $this->pdo->exec('SAVEPOINT cpe_sql_migration_callback');
                try {
                    $afterMigrations($this->pdo);
                } catch (Throwable $e) {
                    $callbackFailure = $e;
                    $this->rollbackSavepointAfterFailure($e, 'cpe_sql_migration_callback');
                }
                if ($callbackFailure === null) {
                    try {
                        $this->pdo->exec('RELEASE SAVEPOINT cpe_sql_migration_callback');
                    } catch (Throwable $cleanup) {
                        $primary = new RuntimeException(
                            self::ERROR_CALLBACK_TRANSACTION . ': callback savepoint could not be released.',
                        );
                        throw $this->cleanupFailure($primary, $cleanup, 'fileless callback savepoint');
                    }
                }
            }
            $this->pdo->commit();
            $started = false;
        } catch (Throwable $e) {
            if ($started) {
                $this->rethrowAfterRollback($e, 'fileless SQLite migration transaction');
            }
            throw $e;
        }
        if ($callbackFailure !== null) {
            throw $callbackFailure;
        }
    }

    /** @param array<int, string> $files */
    private function runPendingMigrations(
        string $driver,
        string $table,
        array $files,
        ?string $lockBackendPid,
        array $applied,
    ): void {
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }
            $this->pdo->beginTransaction();
            try {
                if ($driver === 'pgsql') {
                    if ($lockBackendPid === null) {
                        throw DatabaseLockException::sessionChanged();
                    }
                    DatabaseLock::assertPostgresSession($this->pdo, $lockBackendPid);
                }
                $this->executeMigrationInCurrentTransaction($driver, $table, $file);
                if ($driver === 'pgsql') {
                    DatabaseLock::assertPostgresSession($this->pdo, $lockBackendPid);
                }
                $this->pdo->commit();
                $applied[$name] = 1;
            } catch (Throwable $e) {
                $failure = new RuntimeException(self::ERROR_MIGRATION . ': ' . $name . ' failed.', 0, $e);
                $this->rethrowAfterRollback($failure, 'migration ' . $name);
            }
        }
    }

    /** @return array{0: string, 1: array<string, int>} */
    private function initializePostgresRegistry(?string $lockBackendPid): array
    {
        if ($lockBackendPid === null) {
            throw DatabaseLockException::sessionChanged();
        }
        $this->pdo->beginTransaction();
        try {
            DatabaseLock::assertPostgresSession($this->pdo, $lockBackendPid);
            $schema = $this->postgresSchema();
            $table = self::quoteIdentifier($schema) . '.' . self::quoteIdentifier($this->registry);
            $this->createAndVerifyRegistry('pgsql', $table, $schema);
            $applied = $this->appliedSet($table);
            DatabaseLock::assertPostgresSession($this->pdo, $lockBackendPid);
            $this->pdo->commit();
            return [$table, $applied];
        } catch (Throwable $e) {
            $this->rethrowAfterRollback($e, 'PostgreSQL registry transaction');
        }
    }

    private function executeMigrationInCurrentTransaction(string $driver, string $table, string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException(self::ERROR_MIGRATION . ': could not read ' . basename($file) . '.');
        }
        $this->assertNoTransactionControl($driver, $sql, basename($file));
        $this->pdo->exec($sql);
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException(self::ERROR_MIGRATION . ': migration changed the runner-owned transaction.');
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO ' . $table . ' (migration, applied_at) VALUES (?, ?)',
        );
        $insert->execute([basename($file), gmdate('Y-m-d H:i:s')]);
    }

    private function assertNoTransactionControl(string $driver, string $sql, string $name): void
    {
        foreach ($this->topLevelStatements($driver, $sql, $name) as $statement) {
            $first = $statement[0] ?? '';
            $second = $statement[1] ?? '';
            $control = in_array($first, ['BEGIN', 'COMMIT', 'END', 'ABORT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE'], true)
                || ($driver === 'pgsql' && $first === 'START' && $second === 'TRANSACTION')
                || ($driver === 'pgsql' && $first === 'PREPARE' && $second === 'TRANSACTION');
            if ($control) {
                throw new RuntimeException(
                    self::ERROR_MIGRATION . ': ' . $name . ' contains top-level transaction control SQL.',
                );
            }
        }
    }

    /** @return array<int, array<int, string>> */
    private function topLevelStatements(string $driver, string $sql, string $name): array
    {
        $statements = [];
        $tokens = [];
        $length = strlen($sql);
        $trigger = false;
        $triggerBody = false;
        $triggerDepth = 0;

        $finish = static function () use (&$statements, &$tokens, &$trigger, &$triggerBody, &$triggerDepth): void {
            if ($tokens !== []) {
                $statements[] = $tokens;
            }
            $tokens = [];
            $trigger = false;
            $triggerBody = false;
            $triggerDepth = 0;
        };

        for ($index = 0; $index < $length;) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';
            if (ctype_space($character)) {
                $index++;
                continue;
            }
            if ($character === '-' && $next === '-') {
                $newline = strcspn($sql, "\r\n", $index + 2);
                $index = min($length, $index + 2 + $newline);
                continue;
            }
            if ($character === '/' && $next === '*') {
                $depth = 1;
                $index += 2;
                while ($index < $length && $depth > 0) {
                    if ($driver === 'pgsql'
                        && $sql[$index] === '/'
                        && ($sql[$index + 1] ?? '') === '*') {
                        $depth++;
                        $index += 2;
                    } elseif ($sql[$index] === '*' && ($sql[$index + 1] ?? '') === '/') {
                        $depth--;
                        $index += 2;
                    } else {
                        $index++;
                    }
                }
                if ($depth !== 0) {
                    throw new RuntimeException(self::ERROR_MIGRATION . ': ' . $name . ' contains an unterminated block comment.');
                }
                continue;
            }
            if ($driver === 'pgsql' && $character === '$') {
                $remaining = substr($sql, $index);
                if (preg_match('/^\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/', $remaining, $match) === 1) {
                    $delimiter = $match[0];
                    $end = strpos($sql, $delimiter, $index + strlen($delimiter));
                    if ($end === false) {
                        throw new RuntimeException(self::ERROR_MIGRATION . ': ' . $name . ' contains an unterminated dollar-quoted body.');
                    }
                    $index = $end + strlen($delimiter);
                    continue;
                }
            }
            if ($character === "'") {
                $escaped = $driver === 'pgsql'
                    && $index > 0
                    && in_array($sql[$index - 1], ['e', 'E'], true)
                    && ($index < 2 || !preg_match('/[A-Za-z0-9_$]/', $sql[$index - 2]));
                $index++;
                $closed = false;
                while ($index < $length) {
                    if ($escaped && $sql[$index] === '\\') {
                        $index += 2;
                        continue;
                    }
                    if ($sql[$index] === "'") {
                        if (($sql[$index + 1] ?? '') === "'") {
                            $index += 2;
                            continue;
                        }
                        $index++;
                        $closed = true;
                        break;
                    }
                    $index++;
                }
                if (!$closed) {
                    throw new RuntimeException(self::ERROR_MIGRATION . ': ' . $name . ' contains an unterminated string literal.');
                }
                continue;
            }
            if ($character === '"' || ($driver === 'sqlite' && $character === '`')) {
                $quote = $character;
                $index++;
                $closed = false;
                while ($index < $length) {
                    if ($sql[$index] === $quote) {
                        if (($sql[$index + 1] ?? '') === $quote) {
                            $index += 2;
                            continue;
                        }
                        $index++;
                        $closed = true;
                        break;
                    }
                    $index++;
                }
                if (!$closed) {
                    throw new RuntimeException(self::ERROR_MIGRATION . ': ' . $name . ' contains an unterminated identifier literal.');
                }
                continue;
            }
            if ($driver === 'sqlite' && $character === '[') {
                $end = strpos($sql, ']', $index + 1);
                if ($end === false) {
                    throw new RuntimeException(self::ERROR_MIGRATION . ': ' . $name . ' contains an unterminated bracket identifier.');
                }
                $index = $end + 1;
                continue;
            }
            if (preg_match('/[A-Za-z_]/', $character) === 1) {
                $end = $index + 1;
                while ($end < $length && preg_match('/[A-Za-z0-9_$]/', $sql[$end]) === 1) {
                    $end++;
                }
                $word = strtoupper(substr($sql, $index, $end - $index));
                $tokens[] = $word;
                $count = count($tokens);
                if ($driver === 'sqlite'
                    && (($count === 2 && $tokens === ['CREATE', 'TRIGGER'])
                        || ($count === 3
                            && $tokens[0] === 'CREATE'
                            && in_array($tokens[1], ['TEMP', 'TEMPORARY'], true)
                            && $tokens[2] === 'TRIGGER'))) {
                    $trigger = true;
                }
                if ($trigger) {
                    if (!$triggerBody && $word === 'BEGIN') {
                        $triggerBody = true;
                        $triggerDepth = 1;
                    } elseif ($triggerBody && $word === 'CASE') {
                        $triggerDepth++;
                    } elseif ($triggerBody && $word === 'END') {
                        $triggerDepth--;
                    }
                }
                $index = $end;
                continue;
            }
            if ($character === ';') {
                if (!$trigger || ($triggerBody && $triggerDepth === 0)) {
                    $finish();
                }
                $index++;
                continue;
            }
            $index++;
        }
        $finish();
        return $statements;
    }

    /** @param null|callable(PDO): void $afterMigrations */
    private function runCallbackWithoutTransaction(?callable $afterMigrations): void
    {
        if ($afterMigrations === null) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->rethrowAfterRollback(
                new RuntimeException(self::ERROR_CALLBACK_TRANSACTION . ': runner transaction remained open before callback.'),
                'pre-callback transaction',
            );
        }
        try {
            $afterMigrations($this->pdo);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->rethrowAfterRollback($e, 'failed callback transaction');
            }
            throw $e;
        }
        if ($this->pdo->inTransaction()) {
            $this->rethrowAfterRollback(
                new RuntimeException(self::ERROR_CALLBACK_TRANSACTION . ': callback left a transaction open.'),
                'callback transaction',
            );
        }
    }

    private function createAndVerifyRegistry(string $driver, string $table, ?string $postgresSchema = null): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
            . ($driver === 'pgsql' ? 'id BIGSERIAL' : 'id INTEGER') . ' PRIMARY KEY'
            . ($driver === 'sqlite' ? ' AUTOINCREMENT' : '') . ', '
            . 'migration TEXT NOT NULL UNIQUE, applied_at TEXT NOT NULL)',
        );
        $this->assertRegistryShape($driver, $postgresSchema);
    }

    private function assertRegistryShape(string $driver, ?string $postgresSchema = null): void
    {
        if ($driver === 'sqlite') {
            $columns = $this->pdo->query(
                'PRAGMA main.table_xinfo(' . self::quoteIdentifier($this->registry) . ')',
            )->fetchAll(PDO::FETCH_ASSOC);
            $expected = [
                ['id', 'INTEGER', 0, 1],
                ['migration', 'TEXT', 1, 0],
                ['applied_at', 'TEXT', 1, 0],
            ];
            if (count($columns) !== count($expected)) {
                throw new RuntimeException(self::ERROR_REGISTRY . ': registry columns are invalid.');
            }
            foreach ($expected as $index => [$name, $type, $notNull, $primaryKey]) {
                $column = $columns[$index];
                if (($column['name'] ?? null) !== $name
                    || strtoupper((string) ($column['type'] ?? '')) !== $type
                    || (int) ($column['notnull'] ?? 0) !== $notNull
                    || (int) ($column['pk'] ?? 0) !== $primaryKey
                    || (int) ($column['hidden'] ?? 0) !== 0
                    || ($column['dflt_value'] ?? null) !== null) {
                    throw new RuntimeException(self::ERROR_REGISTRY . ': registry columns are invalid.');
                }
            }
            $this->assertSqliteRegistryIndexes();
            return;
        }

        $schema = $postgresSchema ?? $this->postgresSchema();
        $query = $this->pdo->prepare(
            "SELECT a.attname AS name, pg_catalog.format_type(a.atttypid, a.atttypmod) AS type,
                    a.attnotnull AS not_null, pg_catalog.pg_get_expr(d.adbin, d.adrelid) AS default_value,
                    a.attgenerated AS generated_kind, a.attidentity AS identity_kind
             FROM pg_catalog.pg_attribute a
             JOIN pg_catalog.pg_class c ON c.oid = a.attrelid
             JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
             LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             WHERE n.nspname = CAST(? AS TEXT) AND c.relname = CAST(? AS TEXT)
               AND c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY a.attnum",
        );
        $query->execute([$schema, $this->registry]);
        $columns = $query->fetchAll(PDO::FETCH_ASSOC);
        $expected = [['id', 'bigint'], ['migration', 'text'], ['applied_at', 'text']];
        if (count($columns) !== count($expected)) {
            throw new RuntimeException(self::ERROR_REGISTRY . ': registry columns are invalid.');
        }
        foreach ($expected as $index => [$name, $type]) {
            $column = $columns[$index];
            $default = (string) ($column['default_value'] ?? '');
            if (($column['name'] ?? null) !== $name
                || ($column['type'] ?? null) !== $type
                || !self::postgresBoolean($column['not_null'] ?? false)
                || (string) ($column['generated_kind'] ?? '') !== ''
                || (string) ($column['identity_kind'] ?? '') !== ''
                || ($index === 0 ? !str_starts_with($default, 'nextval(') : $default !== '')) {
                throw new RuntimeException(self::ERROR_REGISTRY . ': registry columns are invalid.');
            }
        }
        $this->assertPostgresRegistryIndexes($schema);
    }

    private function assertSqliteRegistryIndexes(): void
    {
        $primary = false;
        $uniqueMigration = false;
        foreach ($this->pdo->query(
            'PRAGMA main.index_list(' . self::quoteIdentifier($this->registry) . ')',
        )->fetchAll(PDO::FETCH_ASSOC) as $index) {
            $name = (string) ($index['name'] ?? '');
            $columns = $this->pdo->query(
                'PRAGMA main.index_info(' . $this->pdo->quote($name) . ')',
            )->fetchAll(PDO::FETCH_COLUMN, 2);
            if (($index['origin'] ?? '') === 'pk' && $columns === ['id']) {
                $primary = true;
            }
            if ((int) ($index['unique'] ?? 0) === 1
                && (int) ($index['partial'] ?? 0) === 0
                && $columns === ['migration']) {
                $uniqueMigration = true;
            }
        }
        // INTEGER PRIMARY KEY is the rowid and may not have a separate index.
        if (!$uniqueMigration) {
            throw new RuntimeException(self::ERROR_REGISTRY . ': registry migration uniqueness is invalid.');
        }
    }

    private function assertPostgresRegistryIndexes(string $schema): void
    {
        $query = $this->pdo->prepare(
            "SELECT i.indexrelid::text AS index_id, i.indisprimary, i.indisunique,
                    i.indisvalid, i.indisready, i.indpred IS NULL AS full_index,
                    i.indexprs IS NULL AS plain_index,
                    a.attname, key.position
             FROM pg_catalog.pg_class c
             JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_catalog.pg_index i ON i.indrelid = c.oid
             CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS key(attnum, position)
             JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid AND a.attnum = key.attnum
             WHERE n.nspname = CAST(? AS TEXT) AND c.relname = CAST(? AS TEXT)
             ORDER BY i.indexrelid, key.position",
        );
        $query->execute([$schema, $this->registry]);
        $indexes = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (string) $row['index_id'];
            $indexes[$id]['primary'] = self::postgresBoolean($row['indisprimary'] ?? false);
            $indexes[$id]['unique'] = self::postgresBoolean($row['indisunique'] ?? false);
            $indexes[$id]['valid'] = self::postgresBoolean($row['indisvalid'] ?? false)
                && self::postgresBoolean($row['indisready'] ?? false)
                && self::postgresBoolean($row['full_index'] ?? false)
                && self::postgresBoolean($row['plain_index'] ?? false);
            $indexes[$id]['columns'][] = (string) $row['attname'];
        }
        $primary = false;
        $uniqueMigration = false;
        foreach ($indexes as $index) {
            $primary = $primary || ($index['valid'] && $index['primary'] && $index['columns'] === ['id']);
            $uniqueMigration = $uniqueMigration || ($index['valid'] && $index['unique'] && $index['columns'] === ['migration']);
        }
        if (!$primary || !$uniqueMigration) {
            throw new RuntimeException(self::ERROR_REGISTRY . ': registry indexes are invalid.');
        }
    }

    /** @param array<int, string> $files */
    private function assertDiscoveredExactlyOnce(string $table, array $files): void
    {
        $counts = [];
        foreach ($this->pdo->query(
            'SELECT migration, COUNT(*) AS row_count FROM ' . $table . ' GROUP BY migration',
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['migration']] = (int) $row['row_count'];
        }
        foreach ($files as $file) {
            $name = basename($file);
            if (($counts[$name] ?? 0) !== 1) {
                throw new RuntimeException(self::ERROR_REGISTRY . ': discovered migration ' . $name . ' is not recorded exactly once.');
            }
        }
    }

    /** @return array<string, int> */
    private function appliedSet(string $table): array
    {
        $applied = [];
        foreach ($this->pdo->query('SELECT migration FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN) as $migration) {
            $name = (string) $migration;
            if (isset($applied[$name])) {
                throw new RuntimeException(self::ERROR_REGISTRY . ': duplicate migration registry row.');
            }
            $applied[$name] = 1;
        }
        return $applied;
    }

    /** @return array<int, string> */
    private function migrationFiles(): array
    {
        $files = [];
        $sequences = [];
        try {
            $entries = new \FilesystemIterator(
                $this->migrationDirectory,
                \FilesystemIterator::SKIP_DOTS,
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                self::ERROR_DIRECTORY . ': migration directory enumeration failed.',
                0,
                $e,
            );
        }
        foreach ($entries as $entry) {
            $name = $entry->getFilename();
            if (!str_ends_with(strtolower($name), '.sql')) {
                continue;
            }
            if (preg_match('/^(?!000)([0-9]{3})_[a-z][a-z0-9]*(?:_[a-z0-9]+)*\.sql$/', $name, $match) !== 1) {
                throw new RuntimeException(
                    self::ERROR_DIRECTORY . ': invalid migration filename: ' . $name . '.',
                );
            }
            if ($entry->isLink()) {
                throw new RuntimeException(
                    self::ERROR_DIRECTORY . ': symlinked migration is not allowed: ' . $name . '.',
                );
            }
            if (!$entry->isFile()) {
                throw new RuntimeException(
                    self::ERROR_DIRECTORY . ': migration entry is not a regular file: ' . $name . '.',
                );
            }
            $permissions = @fileperms($entry->getPathname());
            if (!$entry->isReadable() || $permissions === false || ($permissions & 0444) === 0) {
                throw new RuntimeException(
                    self::ERROR_DIRECTORY . ': migration file is not readable: ' . $name . '.',
                );
            }
            $sequence = $match[1];
            if (isset($sequences[$sequence])) {
                throw new RuntimeException(
                    self::ERROR_DIRECTORY . ': duplicate migration sequence ' . $sequence . '.',
                );
            }
            $sequences[$sequence] = true;
            $files[] = $entry->getPathname();
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function qualifiedSqliteRegistry(): string
    {
        return 'main.' . self::quoteIdentifier($this->registry);
    }

    private function rethrowAfterRollback(Throwable $primary, string $context): never
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable $cleanup) {
            throw $this->cleanupFailure($primary, $cleanup, $context);
        }
        throw $primary;
    }

    private function rollbackSavepointAfterFailure(Throwable $primary, string $savepoint): void
    {
        try {
            $quotedSavepoint = self::quoteIdentifier($savepoint);
            $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $quotedSavepoint);
            $this->pdo->exec('RELEASE SAVEPOINT ' . $quotedSavepoint);
        } catch (Throwable $cleanup) {
            throw $this->cleanupFailure($primary, $cleanup, 'callback savepoint');
        }
    }

    private function cleanupFailure(
        Throwable $primary,
        Throwable $cleanup,
        string $context,
    ): DatabaseConnectionInvalidException
    {
        return DatabaseConnectionInvalidException::cleanupFailed(
            self::ERROR_CLEANUP,
            $primary,
            $cleanup,
        );
    }

    private function postgresSchema(): string
    {
        $schema = trim((string) $this->pdo->query('SELECT current_schema()')->fetchColumn());
        if ($schema === '' || $schema === 'information_schema' || str_starts_with($schema, 'pg_')) {
            throw new RuntimeException(self::ERROR_REGISTRY . ': PostgreSQL search_path has no application schema.');
        }
        return $schema;
    }

    private function isFilelessSqlite(): bool
    {
        foreach ($this->pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database) {
            if (($database['name'] ?? null) === 'main') {
                return (string) ($database['file'] ?? '') === '';
            }
        }
        throw new RuntimeException(self::ERROR_REGISTRY . ': SQLite main database identity is unavailable.');
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private static function postgresBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
