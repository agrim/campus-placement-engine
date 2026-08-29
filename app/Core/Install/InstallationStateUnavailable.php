<?php

declare(strict_types=1);

namespace App\Core\Install;

use RuntimeException;

final class InstallationStateUnavailable extends RuntimeException
{
    private const MESSAGE = 'Installation state is temporarily unavailable.';

    public static function state(): self
    {
        return new self(self::MESSAGE);
    }
}
