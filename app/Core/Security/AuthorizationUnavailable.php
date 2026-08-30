<?php

declare(strict_types=1);

namespace App\Core\Security;

use RuntimeException;

/**
 * Fixed, non-sensitive signal that installed authorization state cannot be read.
 */
final class AuthorizationUnavailable extends RuntimeException
{
    public const INSTALLATION_STATE = 'installation_state';
    public const CAPABILITY_STATE = 'capability_state';
    public const MODULE_STATE = 'module_state';

    private function __construct(private readonly string $reason)
    {
        parent::__construct('Authorization state is temporarily unavailable.');
    }

    public static function installationState(): self
    {
        return new self(self::INSTALLATION_STATE);
    }

    public static function capabilityState(): self
    {
        return new self(self::CAPABILITY_STATE);
    }

    public static function moduleState(): self
    {
        return new self(self::MODULE_STATE);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
