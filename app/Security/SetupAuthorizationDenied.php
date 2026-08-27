<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;
use Throwable;

final class SetupAuthorizationDenied extends RuntimeException
{
    public const INVALID_CONFIGURATION = 'invalid_configuration';
    public const INVALID_CREDENTIAL = 'invalid_credential';
    public const ACTIVE_LEASE = 'active_lease';
    public const NOT_AUTHORIZED = 'not_authorized';
    public const CALLER_MISMATCH = 'caller_mismatch';
    public const INVALID_STATE = 'invalid_state';
    public const STATE_UNAVAILABLE = 'state_unavailable';

    public function __construct(
        private readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::messageFor($reason), 0, $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    private static function messageFor(string $reason): string
    {
        return match ($reason) {
            self::INVALID_CONFIGURATION => 'Setup authorization is not configured safely.',
            self::INVALID_CREDENTIAL => 'Setup authorization was denied.',
            self::ACTIVE_LEASE => 'Setup is already authorized in another browser session.',
            self::CALLER_MISMATCH => 'The setup authorization no longer matches this browser session.',
            self::INVALID_STATE => 'Setup authorization state is invalid.',
            self::STATE_UNAVAILABLE => 'Setup authorization state is unavailable.',
            default => 'Setup is not authorized.',
        };
    }
}
