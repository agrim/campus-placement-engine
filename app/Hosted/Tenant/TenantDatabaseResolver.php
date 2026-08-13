<?php

declare(strict_types=1);

namespace App\Hosted\Tenant;

use App\Hosted\ControlPlane\HostedControlPlane;
use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Infrastructure\Persistence\SqliteConnectionProvider;

final class TenantDatabaseResolver
{
    public function __construct(private readonly HostedControlPlane $controlPlane)
    {
    }

    public function resolveHost(string $host): ResolvedTenant
    {
        try {
            $metadata = $this->controlPlane->resolveHostname($host);
        } catch (\Throwable $e) {
            throw new HostedResolutionException('Hosted tenant lookup is unavailable.', 503);
        }
        if ($metadata === null) {
            throw new HostedResolutionException('No hosted tenant is configured for this domain.', 404);
        }
        return $this->resolveMetadata($metadata);
    }

    public function resolveTenant(string $publicIdOrSlug, bool $requireActive = true): ResolvedTenant
    {
        $metadata = $this->controlPlane->tenant($publicIdOrSlug);
        if ($metadata === null) {
            throw new HostedResolutionException('Unknown hosted tenant.', 404);
        }
        if ($requireActive && ($metadata['tenant_status'] !== 'active' || !in_array($metadata['deployment_status'], ['active', 'upgrading'], true))) {
            throw new HostedResolutionException('Hosted tenant is not active.', 503);
        }
        $metadata['entitlements'] = $this->controlPlane->moduleEntitlements((int) $metadata['tenant_id'], (string) $metadata['plan_key']);
        return $this->resolveMetadata($metadata);
    }

    public function resolveDeployment(int $deploymentId): ResolvedTenant
    {
        $metadata = $this->controlPlane->tenantByDeploymentId($deploymentId);
        if ($metadata === null) {
            throw new HostedResolutionException('Unknown hosted deployment.', 404);
        }
        $metadata['entitlements'] = $this->controlPlane->moduleEntitlements((int) $metadata['tenant_id'], (string) $metadata['plan_key']);
        return $this->resolveMetadata($metadata);
    }

    private function resolveMetadata(array $metadata): ResolvedTenant
    {
        $reference = HostedControlPlane::normalizeDatabaseReference((string) $metadata['database_reference']);
        $urlKey = 'CPE_TENANT_DATABASE_URL_' . $reference;
        $pathKey = 'CPE_TENANT_DB_PATH_' . $reference;
        $url = trim((string) (getenv($urlKey) ?: ''));
        $path = trim((string) (getenv($pathKey) ?: ''));
        if ($url !== '' && $path !== '') {
            throw new HostedResolutionException('Tenant database reference is ambiguous.', 503);
        }
        if ($url !== '') {
            try {
                return new ResolvedTenant(
                    $metadata,
                    PostgresConnectionProvider::fromUrl($url, $urlKey),
                    $url,
                );
            } catch (\Throwable $e) {
                throw new HostedResolutionException('Tenant database configuration is invalid.', 503);
            }
        }
        if ($path !== '') {
            if (!self::truthy(getenv('CPE_HOSTED_ALLOW_SQLITE_TENANTS'))) {
                throw new HostedResolutionException('Hosted SQLite tenants are disabled.', 503);
            }
            if (!str_starts_with($path, '/')) {
                throw new HostedResolutionException('Hosted SQLite tenant paths must be absolute.', 503);
            }
            return new ResolvedTenant($metadata, new SqliteConnectionProvider($path), null);
        }
        throw new HostedResolutionException('Tenant database secret is not configured.', 503);
    }

    private static function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
