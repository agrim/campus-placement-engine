<?php

declare(strict_types=1);

namespace App\Core\Persistence;

use RuntimeException;
use Throwable;

/**
 * Typed failure for a database lock whose session or checked release can no
 * longer be trusted. Callers with cached providers must discard them only
 * after DatabaseLock::synchronized() has completed its release attempt.
 */
final class DatabaseLockException extends RuntimeException
{
    private function __construct(
        private readonly string $failureCode,
        ?Throwable $previous,
    ) {
        parent::__construct(
            $failureCode . ': database lock integrity could not be confirmed; discard this database connection.',
            0,
            $previous,
        );
    }

    public static function releaseFailed(?Throwable $previous = null): self
    {
        return new self(DatabaseLock::ERROR_RELEASE, $previous);
    }

    public static function sessionChanged(?Throwable $previous = null): self
    {
        return new self(DatabaseLock::ERROR_SESSION_CHANGED, $previous);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
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
