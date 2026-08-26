<?php

declare(strict_types=1);

namespace App\Hosted\Tenant;

interface TenantResolver
{
    public function resolveHost(string $host): ResolvedTenant;
}
