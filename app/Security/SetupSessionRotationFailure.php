<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class SetupSessionRotationFailure extends RuntimeException
{
    public const SESSION_NOT_ACTIVE = 'session_not_active';
    public const RESPONSE_STARTED = 'session_response_started';
    public const SESSION_WRITE = 'session_write';
    public const HANDLER_REOPEN = 'session_reopen';
    public const ID_CREATE = 'session_id_create';
    public const ID_READ = 'session_id_read';
    public const COOKIE_RESET = 'session_cookie_reset';
    public const WARNING_STORAGE = 'session_warning_storage';
    public const WARNING_RESPONSE = 'session_warning_response';
    public const WARNING_OTHER = 'session_warning_other';
    public const RETURNED_FALSE = 'session_returned_false';
    public const THREW = 'session_threw';

    private function __construct(private readonly string $phase)
    {
        parent::__construct('Setup session rotation failed.');
    }

    public static function fromPhpWarning(string $diagnostic): self
    {
        $phase = match (true) {
            str_contains($diagnostic, 'Session write failed.') => self::SESSION_WRITE,
            str_contains($diagnostic, 'Failed to open session:') => self::HANDLER_REOPEN,
            str_contains($diagnostic, 'Failed to create new session ID:'),
            str_contains($diagnostic, 'Failed to create session ID by collision:') => self::ID_CREATE,
            str_contains($diagnostic, 'Failed to create(read) session ID:') => self::ID_READ,
            str_contains($diagnostic, 'Cannot set session ID - session ID is not initialized') => self::COOKIE_RESET,
            self::containsAny($diagnostic, [
                'failed to open', 'cannot open', 'unable to open', 'open failed',
                'failed to write', 'unable to write', 'write failed',
                'failed to read', 'unable to read', 'read failed',
                'permission denied', 'not permitted',
                'failed to lock', 'unable to lock', 'lock failed', 'session storage',
            ])
                => self::WARNING_STORAGE,
            self::containsAny($diagnostic, [
                'headers already sent', 'cannot modify header',
                'failed to send session cookie', 'session cookie',
            ]) => self::WARNING_RESPONSE,
            default => self::WARNING_OTHER,
        };
        return new self($phase);
    }

    public static function returnedFalse(): self
    {
        return new self(self::RETURNED_FALSE);
    }

    public static function sessionNotActive(): self
    {
        return new self(self::SESSION_NOT_ACTIVE);
    }

    public static function responseStarted(): self
    {
        return new self(self::RESPONSE_STARTED);
    }

    public static function threw(): self
    {
        return new self(self::THREW);
    }

    public function phase(): string
    {
        return $this->phase;
    }

    /** @param list<string> $needles */
    private static function containsAny(string $diagnostic, array $needles): bool
    {
        $normalized = strtolower($diagnostic);
        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }
        return false;
    }
}
