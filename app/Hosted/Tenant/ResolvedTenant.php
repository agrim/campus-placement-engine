<?php

declare(strict_types=1);

namespace App\Hosted\Tenant;

use App\Core\Persistence\ConnectionProvider;

final class ResolvedTenant
{
    public function __construct(
        private readonly array $metadata,
        private readonly ConnectionProvider $provider,
        private readonly ?string $postgresUrl,
    ) {
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function provider(): ConnectionProvider
    {
        return $this->provider;
    }

    public function postgresUrl(): ?string
    {
        return $this->postgresUrl;
    }

    public function tenantId(): int
    {
        return (int) $this->metadata['tenant_id'];
    }

    public function publicId(): string
    {
        return (string) $this->metadata['tenant_public_id'];
    }

    public function slug(): string
    {
        return (string) $this->metadata['slug'];
    }

    public function allowsModule(string $moduleKey): bool
    {
        return (bool) (($this->metadata['entitlements'][$moduleKey] ?? false));
    }
}
