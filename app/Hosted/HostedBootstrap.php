<?php

declare(strict_types=1);

namespace App\Hosted;

use App\Hosted\Tenant\HostedResolutionException;
use App\Hosted\Tenant\TenantResolver;
use App\Support\Database;
use RuntimeException;

final class HostedBootstrap
{
    public const CONTRACT_VERSION = 1;

    private static ?TenantResolver $resolver = null;

    public static function enabled(): bool
    {
        return in_array(strtolower(trim((string) (getenv('CPE_HOSTED_MODE') ?: ''))), ['1', 'true', 'yes', 'on'], true);
    }

    public static function registerResolver(TenantResolver $resolver): void
    {
        if (self::$resolver !== null && self::$resolver !== $resolver) {
            throw new RuntimeException('A managed-hosting tenant resolver is already registered.');
        }
        self::$resolver = $resolver;
    }

    public static function resetResolver(): void
    {
        self::$resolver = null;
    }

    public static function resolveHttpRequest(): void
    {
        if (!self::enabled() || PHP_SAPI === 'cli') {
            return;
        }
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            throw new HostedResolutionException('Hosted requests require an exact Host header.', 400);
        }
        self::resolveHost($host);
    }

    public static function resolveHost(string $host): void
    {
        if (self::$resolver === null) {
            throw new HostedResolutionException('Managed hosting is enabled without a tenant resolver.', 503);
        }
        $resolved = self::$resolver->resolveHost($host);
        Database::useProvider($resolved->provider());
        HostedContext::activate($resolved);
        if (Database::isInstalled()) {
            self::assertDataPlaneIdentity($resolved->publicId());
        }
    }

    public static function bindSession(): void
    {
        if (!HostedContext::isActive()) {
            return;
        }
        $tenantId = HostedContext::current()->publicId();
        $bound = (string) ($_SESSION['cpe_hosted_tenant'] ?? '');
        if ($bound !== '' && !hash_equals($bound, $tenantId)) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
        }
        $_SESSION['cpe_hosted_tenant'] = $tenantId;
    }

    public static function assertDataPlaneIdentity(string $tenantPublicId): void
    {
        try {
            $row = Database::connection()->query("SELECT public_id FROM institutions WHERE slug = 'default' LIMIT 1")->fetchColumn();
        } catch (\Throwable $e) {
            throw new HostedResolutionException('Tenant database schema is unavailable.', 503);
        }
        if (!is_string($row) || !hash_equals($tenantPublicId, $row)) {
            throw new HostedResolutionException('Tenant database identity does not match the requested domain.', 503);
        }
    }

    public static function bindInstalledDataPlane(string $tenantPublicId): void
    {
        if (!Database::isInstalled()) {
            throw new RuntimeException('Cannot bind an uninstalled tenant database.');
        }
        $stmt = Database::connection()->prepare("UPDATE institutions SET public_id = ?, updated_at = ? WHERE slug = 'default'");
        $stmt->execute([$tenantPublicId, cpe_now()]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Tenant database has no default institution to bind.');
        }
    }
}
