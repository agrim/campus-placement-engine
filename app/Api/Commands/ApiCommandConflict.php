<?php

declare(strict_types=1);

namespace App\Api\Commands;

use RuntimeException;

final class ApiCommandConflict extends RuntimeException
{
    public const ACCOUNT = 'account';
    public const REQUEST = 'request';

    public function __construct(private readonly string $reason)
    {
        parent::__construct('The API command idempotency key conflicts with an earlier request.');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
