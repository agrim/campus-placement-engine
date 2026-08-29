<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Install\InstallationStateUnavailable;
use App\Support\Database;

/**
 * Target-bound capability permitting recovery of one exact Engine-owned,
 * markerless installation target.
 */
final class SetupRecoveryAuthority
{
    private const PROOF_DOMAIN = "cpe.setup.recovery-authority.v1";

    private static ?string $issuerKey = null;

    private readonly string $proof;

    private function __construct(private readonly string $targetKey)
    {
        $this->proof = self::proof($targetKey);
    }

    public static function afterSetupAuthorization(SetupAuthorization $authorization): self
    {
        $authority = new self($authorization->recoveryAuthorityTargetKey());
        // The capability is returned only for the exact markerless,
        // Engine-owned state it is allowed to recover. Fresh targets never
        // mint a capability that could survive their transition to migrated
        // markerless state.
        Database::installationStateForAuthorizedSetupStrict($authority);
        return $authority;
    }

    public function assertCurrentTarget(): void
    {
        if (!hash_equals($this->proof, self::proof($this->targetKey))
            || !hash_equals($this->targetKey, self::currentTargetKey())) {
            throw InstallationStateUnavailable::state();
        }
    }

    public function __serialize(): array
    {
        throw InstallationStateUnavailable::state();
    }

    public function __unserialize(array $data): void
    {
        throw InstallationStateUnavailable::state();
    }

    private function __clone()
    {
    }

    private static function currentTargetKey(): string
    {
        $provider = Database::provider();
        return hash('sha256', $provider->driver() . "\0" . $provider->identifier());
    }

    private static function proof(string $targetKey): string
    {
        if (self::$issuerKey === null) {
            self::$issuerKey = random_bytes(32);
        }
        return hash_hmac('sha256', self::PROOF_DOMAIN . "\0" . $targetKey, self::$issuerKey);
    }
}
