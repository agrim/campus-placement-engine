<?php

declare(strict_types=1);

namespace App\Api\Security;

use RuntimeException;

final class InvalidApiCredential extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The API credential is invalid.');
    }
}
