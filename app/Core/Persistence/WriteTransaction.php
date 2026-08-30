<?php

declare(strict_types=1);

namespace App\Core\Persistence;

use PDO;
use Throwable;
use WeakMap;

/**
 * Connection-scoped write transaction boundary.
 *
 * SQLite uses BEGIN IMMEDIATE so competing writers serialize before reading
 * mutable state. PHP 8.2 does not expose that SQL-level transaction through
 * PDO::inTransaction(), so a WeakMap records ownership for nested services on
 * the same connection. PostgreSQL retains ordinary PDO transaction behavior.
 */
final class WriteTransaction
{
    public const ERROR_CLEANUP = 'CPE_WRITE_TRANSACTION_CLEANUP_FAILED';

    /** @var null|WeakMap<PDO, true> */
    private static ?WeakMap $sqliteOwners = null;

    /** @template T @param callable(): T $operation @return T */
    public static function run(PDO $pdo, callable $operation): mixed
    {
        if (self::isActive($pdo)) {
            return $operation();
        }

        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? self::runSqlite($pdo, $operation)
            : self::runPdo($pdo, $operation);
    }

    /**
     * Includes SQLite BEGIN IMMEDIATE ownership, which PDO::inTransaction()
     * does not expose when the transaction was opened with SQL.
     */
    public static function isActive(PDO $pdo): bool
    {
        return self::sqliteOwned($pdo) || $pdo->inTransaction();
    }

    /** @template T @param callable(): T $operation @return T */
    private static function runSqlite(PDO $pdo, callable $operation): mixed
    {
        $owners = self::sqliteOwners();
        $owners[$pdo] = true;
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $result = $operation();
            } catch (Throwable $failure) {
                self::rollbackSqlite($pdo, $failure);
            }

            try {
                $pdo->exec('COMMIT');
            } catch (Throwable $failure) {
                self::rollbackSqlite($pdo, $failure);
            }
            return $result;
        } finally {
            unset($owners[$pdo]);
        }
    }

    /** @template T @param callable(): T $operation @return T */
    private static function runPdo(PDO $pdo, callable $operation): mixed
    {
        $pdo->beginTransaction();
        try {
            $result = $operation();
            $pdo->commit();
            return $result;
        } catch (Throwable $failure) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $cleanup) {
                    throw DatabaseConnectionInvalidException::cleanupFailed(
                        self::ERROR_CLEANUP,
                        $failure,
                        $cleanup,
                    );
                }
            }
            throw $failure;
        }
    }

    private static function rollbackSqlite(PDO $pdo, Throwable $primary): never
    {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable $cleanup) {
            throw DatabaseConnectionInvalidException::cleanupFailed(
                self::ERROR_CLEANUP,
                $primary,
                $cleanup,
            );
        }
        throw $primary;
    }

    /** @return WeakMap<PDO, true> */
    private static function sqliteOwners(): WeakMap
    {
        return self::$sqliteOwners ??= new WeakMap();
    }

    private static function sqliteOwned(PDO $pdo): bool
    {
        return isset(self::sqliteOwners()[$pdo]);
    }
}
