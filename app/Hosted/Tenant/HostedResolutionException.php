<?php

declare(strict_types=1);

namespace App\Hosted\Tenant;

use RuntimeException;

final class HostedResolutionException extends RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatus = 503)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
