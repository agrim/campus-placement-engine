<?php

declare(strict_types=1);

namespace App\Core;

final class Portal
{
    private static ?ApplicationContext $context = null;

    public static function context(): ApplicationContext
    {
        self::$context ??= ApplicationContext::fromCurrentInstallation();
        return self::$context;
    }

    public static function reset(): void
    {
        self::$context = null;
    }
}
