<?php

declare(strict_types=1);

namespace App\Core\Persistence;

use PDO;
use RuntimeException;
use Throwable;

final class DatabaseLock
{
    public const CONTRACT_VERSION = 1;
    public const ERROR_ACTIVE_TRANSACTION = 'CPE_DATABASE_LOCK_ACTIVE_TRANSACTION';
    public const ERROR_RELEASE = 'CPE_DATABASE_LOCK_RELEASE_FAILED';
    public const ERROR_SESSION_CHANGED = 'CPE_DATABASE_LOCK_SESSION_CHANGED';
    public const ERROR_TIMEOUT = 'CPE_DATABASE_LOCK_TIMEOUT';
    public const ERROR_UNSUPPORTED = 'CPE_DATABASE_LOCK_UNSUPPORTED';

    private const KEY_PREFIX = "campus-placement-engine\0database-lock-v1\0";

    /**
     * @template T
     * @param callable(?string): T $criticalSection PostgreSQL receives the acquiring backend PID.
     * @return T
     */
    public static function synchronized(
        PDO $pdo,
        string $namespace,
        callable $criticalSection,
        int $timeoutMilliseconds = 5000,
    ): mixed {
        if ($pdo->inTransaction()) {
            throw new RuntimeException(self::ERROR_ACTIVE_TRANSACTION . ': acquire the database lock before starting a transaction.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $namespace) !== 1) {
            throw new RuntimeException(self::ERROR_UNSUPPORTED . ': invalid database lock namespace.');
        }
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 60000) {
            throw new RuntimeException(self::ERROR_UNSUPPORTED . ': database lock timeout must be between 1 and 60000 milliseconds.');
        }

        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        return match ($driver) {
            'pgsql' => self::withPostgresLock($pdo, $namespace, $criticalSection, $timeoutMilliseconds),
            'sqlite' => self::withSqliteLock($pdo, $namespace, $criticalSection, $timeoutMilliseconds),
            default => throw new RuntimeException(self::ERROR_UNSUPPORTED . ': unsupported database driver ' . $driver . '.'),
        };
    }

    /**
     * @template T
     * @param callable(?string): T $criticalSection
     * @return T
     */
    private static function withPostgresLock(
        PDO $pdo,
        string $namespace,
        callable $criticalSection,
        int $timeoutMilliseconds,
    ): mixed {
        if (PHP_INT_SIZE !== 8) {
            throw new RuntimeException(self::ERROR_UNSUPPORTED . ': PostgreSQL database locks require 64-bit PHP.');
        }

        $identity = trim((string) $pdo->query(
            'SELECT oid::text FROM pg_catalog.pg_database WHERE datname = current_database()',
        )->fetchColumn());
        if (preg_match('/^[0-9]+$/', $identity) !== 1) {
            throw new RuntimeException(self::ERROR_UNSUPPORTED . ': PostgreSQL database identity is unavailable.');
        }

        $digest = hash('sha256', self::KEY_PREFIX . $namespace . "\0" . $identity, true);
        $words = unpack('Nfirst/Nsecond', substr($digest, 0, 8));
        if (!is_array($words) || !isset($words['first'], $words['second'])) {
            throw new RuntimeException(self::ERROR_UNSUPPORTED . ': PostgreSQL database lock key derivation failed.');
        }
        $first = self::signedInt32((int) $words['first']);
        $second = self::signedInt32((int) $words['second']);
        $tryLock = $pdo->prepare(
            'SELECT pg_backend_pid()::text AS backend_pid,
                    pg_try_advisory_lock(CAST(? AS INTEGER), CAST(? AS INTEGER)) AS acquired',
        );
        $backendPid = '';
        self::waitForLock(function () use ($tryLock, $first, $second, &$backendPid): bool {
            $tryLock->execute([$first, $second]);
            $row = $tryLock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !self::postgresBoolean($row['acquired'] ?? false)) {
                return false;
            }
            $candidatePid = trim((string) ($row['backend_pid'] ?? ''));
            $backendPid = $candidatePid;
            return true;
        }, $timeoutMilliseconds, $namespace);

        $result = null;
        $failure = null;
        try {
            if (preg_match('/^[0-9]+$/', $backendPid) !== 1) {
                throw DatabaseLockException::sessionChanged();
            }
            self::assertPostgresSession($pdo, $backendPid);
            $result = $criticalSection($backendPid);
        } catch (Throwable $e) {
            $failure = $e;
        }

        $releaseFailure = null;
        try {
            $unlock = $pdo->prepare(
                'SELECT pg_backend_pid()::text AS backend_pid,
                        pg_advisory_unlock(CAST(? AS INTEGER), CAST(? AS INTEGER)) AS released',
            );
            $unlock->execute([$first, $second]);
            $unlockRow = $unlock->fetch(PDO::FETCH_ASSOC);
            $releasePid = is_array($unlockRow) ? trim((string) ($unlockRow['backend_pid'] ?? '')) : '';
            $released = is_array($unlockRow) && self::postgresBoolean($unlockRow['released'] ?? false);
            if ($releasePid !== $backendPid) {
                throw DatabaseLockException::sessionChanged();
            }
            if (!$released) {
                throw DatabaseLockException::releaseFailed();
            }
        } catch (Throwable $e) {
            $releaseFailure = $e;
        }

        if ($releaseFailure !== null) {
            throw DatabaseLockException::releaseFailed($failure ?? $releaseFailure);
        }

        if ($failure !== null) {
            throw $failure;
        }
        return $result;
    }

    /**
     * @template T
     * @param callable(?string): T $criticalSection
     * @return T
     */
    private static function withSqliteLock(
        PDO $pdo,
        string $namespace,
        callable $criticalSection,
        int $timeoutMilliseconds,
    ): mixed {
        $databases = $pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
        $mainFile = null;
        foreach ($databases as $database) {
            $name = strtolower(trim((string) ($database['name'] ?? '')));
            if ($name === 'main') {
                if ($mainFile !== null) {
                    throw new RuntimeException(self::ERROR_UNSUPPORTED . ': SQLite reported more than one main database.');
                }
                $mainFile = (string) ($database['file'] ?? '');
                continue;
            }
            if ($name !== 'temp') {
                throw new RuntimeException(self::ERROR_UNSUPPORTED . ': attached non-temporary SQLite databases are not supported.');
            }
        }
        if ($mainFile === null) {
            throw new RuntimeException(self::ERROR_UNSUPPORTED . ': SQLite main database identity is unavailable.');
        }

        if ($mainFile === '') {
            return $criticalSection(null);
        }

        $canonicalPath = realpath($mainFile);
        if ($canonicalPath === false) {
            $parent = realpath(dirname($mainFile));
            if ($parent === false) {
                throw new RuntimeException(self::ERROR_UNSUPPORTED . ': SQLite database parent path cannot be canonicalized.');
            }
            $canonicalPath = $parent . DIRECTORY_SEPARATOR . basename($mainFile);
        }
        $lockPath = $canonicalPath . '.cpe-lock.' . substr(hash('sha256', $namespace), 0, 16);
        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException(self::ERROR_UNSUPPORTED . ': SQLite database lock file cannot be opened.');
        }

        try {
            self::waitForLock(
                static fn (): bool => flock($handle, LOCK_EX | LOCK_NB),
                $timeoutMilliseconds,
                $namespace,
            );

            $result = null;
            $failure = null;
            try {
                $result = $criticalSection(null);
            } catch (Throwable $e) {
                $failure = $e;
            }

            if (!flock($handle, LOCK_UN)) {
                throw DatabaseLockException::releaseFailed($failure);
            }
            if ($failure !== null) {
                throw $failure;
            }
            return $result;
        } finally {
            fclose($handle);
        }
    }

    /** @param callable(): bool $attempt */
    private static function waitForLock(callable $attempt, int $timeoutMilliseconds, string $namespace): void
    {
        $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
        do {
            if ($attempt()) {
                return;
            }
            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds <= 0) {
                break;
            }
            usleep((int) min(10_000, max(1, intdiv($remainingNanoseconds, 1000))));
        } while (true);

        throw new RuntimeException(self::ERROR_TIMEOUT . ': timed out waiting for database lock ' . $namespace . '.');
    }

    public static function assertPostgresSession(PDO $pdo, string $expectedBackendPid): void
    {
        $pid = trim((string) $pdo->query('SELECT pg_backend_pid()::text')->fetchColumn());
        if (preg_match('/^[0-9]+$/', $expectedBackendPid) !== 1
            || preg_match('/^[0-9]+$/', $pid) !== 1
            || !hash_equals($expectedBackendPid, $pid)) {
            throw DatabaseLockException::sessionChanged();
        }
    }

    private static function postgresBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private static function signedInt32(int $value): int
    {
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
