<?php

declare(strict_types=1);

namespace App\Core\Http;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * A reviewed failure whose stable code and message may cross a user boundary.
 *
 * Callers must supply a fixed public message. The previous exception is kept
 * only for internal causality and is never used to construct that message.
 */
final class UserVisibleException extends RuntimeException
{
    public function __construct(
        private readonly string $publicCode,
        private readonly string $publicMessage,
        ?Throwable $previous = null,
    ) {
        if (preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/D', $publicCode) !== 1) {
            throw new InvalidArgumentException('User-visible exception codes must be stable uppercase identifiers.');
        }
        if ($publicMessage === ''
            || strlen($publicMessage) > 240
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $publicMessage) === 1
            || str_contains($publicMessage, "\r")
            || str_contains($publicMessage, "\n")) {
            throw new InvalidArgumentException('User-visible exception messages must be short reviewed single-line text.');
        }
        parent::__construct($publicMessage, 0, $previous);
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }

    public function publicMessage(): string
    {
        return $this->publicMessage;
    }
}
