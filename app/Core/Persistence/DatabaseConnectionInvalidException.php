<?php

declare(strict_types=1);

namespace App\Core\Persistence;

use RuntimeException;
use Throwable;

/**
 * A primary database failure whose rollback or savepoint cleanup also failed.
 * The cached connection must be discarded after any enclosing lock release
 * attempt has completed.
 */
final class DatabaseConnectionInvalidException extends RuntimeException
{
    private function __construct(
        private readonly string $failureCode,
        Throwable $primary,
        private readonly Throwable $cleanup,
    ) {
        parent::__construct(
            $failureCode . ': database cleanup failed; discard this database connection.',
            0,
            $primary,
        );
    }

    public static function cleanupFailed(
        string $failureCode,
        Throwable $primary,
        Throwable $cleanup,
    ): self {
        $safeCode = preg_match('/\ACPE_[A-Z0-9_]{3,59}\z/D', $failureCode) === 1
            ? $failureCode
            : 'CPE_DATABASE_CLEANUP_FAILED';
        return new self($safeCode, $primary, $cleanup);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }

    public function cleanupCause(): Throwable
    {
        return $this->cleanup;
    }

    public function requiresConnectionReset(): bool
    {
        return true;
    }

    public static function find(Throwable $failure): ?self
    {
        do {
            if ($failure instanceof self) {
                return $failure;
            }
            $failure = $failure->getPrevious();
        } while ($failure !== null);

        return null;
    }
}
