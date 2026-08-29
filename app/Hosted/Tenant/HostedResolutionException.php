<?php

declare(strict_types=1);

namespace App\Hosted\Tenant;

use RuntimeException;

final class HostedResolutionException extends RuntimeException
{
    private const INSTALLATION_STATE_UNAVAILABLE = 'Tenant installation state is unavailable.';

    public function __construct(string $message, private readonly int $httpStatus = 503)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public static function installationStateUnavailable(): self
    {
        return new self(self::INSTALLATION_STATE_UNAVAILABLE, 503);
    }
}
