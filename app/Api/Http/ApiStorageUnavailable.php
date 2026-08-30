<?php

declare(strict_types=1);

namespace App\Api\Http;

use RuntimeException;
use Throwable;

final class ApiStorageUnavailable extends RuntimeException
{
    public function __construct(string $message = 'API storage is unavailable.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
