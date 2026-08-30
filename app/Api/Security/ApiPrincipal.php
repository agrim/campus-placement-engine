<?php

declare(strict_types=1);

namespace App\Api\Security;

/** @internal Phase 3A principal; no public resource route consumes it yet. */
final class ApiPrincipal
{
    /** @param list<string> $scopes */
    public function __construct(
        private readonly int $institutionId,
        private readonly string $institutionPublicId,
        private readonly int $serviceAccountId,
        private readonly string $serviceAccountPublicId,
        private readonly int $tokenId,
        private readonly string $tokenLookupId,
        private readonly array $scopes,
    ) {
    }

    public function institutionId(): int { return $this->institutionId; }
    public function institutionPublicId(): string { return $this->institutionPublicId; }
    public function serviceAccountId(): int { return $this->serviceAccountId; }
    public function serviceAccountPublicId(): string { return $this->serviceAccountPublicId; }
    public function tokenId(): int { return $this->tokenId; }
    public function tokenLookupId(): string { return $this->tokenLookupId; }
    /** @return list<string> */
    public function scopes(): array { return $this->scopes; }
}
