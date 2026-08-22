<?php

declare(strict_types=1);

namespace App\Hosted;

use App\Hosted\Tenant\ResolvedTenant;
use RuntimeException;

final class HostedContext
{
    private static ?ResolvedTenant $tenant = null;

    public static function activate(ResolvedTenant $tenant): void
    {
        self::$tenant = $tenant;
    }

    public static function reset(): void
    {
        self::$tenant = null;
    }

    public static function isActive(): bool
    {
        return self::$tenant !== null;
    }

    public static function current(): ResolvedTenant
    {
        return self::$tenant ?? throw new RuntimeException('No hosted tenant is active.');
    }

    public static function allowsModule(string $moduleKey): bool
    {
        return self::$tenant === null || self::$tenant->allowsModule($moduleKey);
    }
}
