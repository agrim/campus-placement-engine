<?php

declare(strict_types=1);

namespace App\Api\Commands;

use RuntimeException;

final class ApiCommandUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('API command idempotency state is unavailable.');
    }
}
