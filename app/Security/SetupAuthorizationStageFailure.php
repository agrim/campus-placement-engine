<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class SetupAuthorizationStageFailure extends RuntimeException
{
    public const STATE_PREPARE = 'state_prepare';
    public const STATE_PERMISSIONS = 'state_permissions';
    public const SESSION_FINGERPRINT = 'session_fingerprint';
    public const STATE_WRITE_PREPARE = 'state_write_prepare';
    public const STATE_WRITE_IO = 'state_write_io';
    public const STATE_SYNC = 'state_sync';

    private function __construct(private readonly string $phase)
    {
        parent::__construct('Setup authorization stage failed.');
    }

    public static function statePrepare(): self
    {
        return new self(self::STATE_PREPARE);
    }

    public static function statePermissions(): self
    {
        return new self(self::STATE_PERMISSIONS);
    }

    public static function sessionFingerprint(): self
    {
        return new self(self::SESSION_FINGERPRINT);
    }

    public static function stateWritePrepare(): self
    {
        return new self(self::STATE_WRITE_PREPARE);
    }

    public static function stateWriteIo(): self
    {
        return new self(self::STATE_WRITE_IO);
    }

    public static function stateSync(): self
    {
        return new self(self::STATE_SYNC);
    }

    public function phase(): string
    {
        return $this->phase;
    }
}
