<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class SetupSessionRotationFailure extends RuntimeException
{
    public const SESSION_WRITE = 'session_write';
    public const HANDLER_REOPEN = 'session_reopen';
    public const ID_CREATE = 'session_id_create';
    public const ID_READ = 'session_id_read';
    public const COOKIE_RESET = 'session_cookie_reset';
    public const UNKNOWN = 'session_rotation_unknown';

    private function __construct(private readonly string $phase)
    {
        parent::__construct('Setup session rotation failed.');
    }

    public static function fromPhpDiagnostic(string $diagnostic): self
    {
        $phase = match (true) {
            str_contains($diagnostic, 'Session write failed.') => self::SESSION_WRITE,
            str_contains($diagnostic, 'Failed to open session:') => self::HANDLER_REOPEN,
            str_contains($diagnostic, 'Failed to create new session ID:'),
            str_contains($diagnostic, 'Failed to create session ID by collision:') => self::ID_CREATE,
            str_contains($diagnostic, 'Failed to create(read) session ID:') => self::ID_READ,
            str_contains($diagnostic, 'Cannot set session ID - session ID is not initialized') => self::COOKIE_RESET,
            default => self::UNKNOWN,
        };
        return new self($phase);
    }

    public static function withoutPhpDiagnostic(): self
    {
        return new self(self::UNKNOWN);
    }

    public function phase(): string
    {
        return $this->phase;
    }
}
