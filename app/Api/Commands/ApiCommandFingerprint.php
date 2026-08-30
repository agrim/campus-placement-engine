<?php

declare(strict_types=1);

namespace App\Api\Commands;

/**
 * Secret-free, institution-bound command fingerprint.
 *
 * @internal Construct through ApiCommandHasher so clear idempotency keys never
 * leave the hashing boundary.
 */
final class ApiCommandFingerprint
{
    /** @param array<string, string> $candidateKeyHashes Key version => keyed hash. */
    public function __construct(
        private readonly string $institutionPublicId,
        private readonly string $serviceAccountPublicId,
        private readonly string $operation,
        private readonly string $aggregatePublicId,
        private readonly string $requestHash,
        private readonly string $activeKeyVersion,
        private readonly string $activeKeyHash,
        private readonly array $candidateKeyHashes,
    ) {
        if (preg_match('/\A(?:inst|tenant)_[a-f0-9]{32}\z/D', $institutionPublicId) !== 1
            || preg_match('/\Aapisa_[a-f0-9]{32}\z/D', $serviceAccountPublicId) !== 1
            || $operation !== ApiCommandHasher::OPERATION
            || preg_match('/\Aapplication_[a-f0-9]{32}\z/D', $aggregatePublicId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $requestHash) !== 1
            || preg_match('/\A[A-Za-z0-9_.-]{1,32}\z/D', $activeKeyVersion) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $activeKeyHash) !== 1
            || count($candidateKeyHashes) < 1
            || count($candidateKeyHashes) > 8
            || !isset($candidateKeyHashes[$activeKeyVersion])
            || !hash_equals($activeKeyHash, (string) $candidateKeyHashes[$activeKeyVersion])) {
            throw new InvalidApiCommandInput('API command fingerprint is invalid.');
        }
        foreach ($candidateKeyHashes as $version => $hash) {
            if (!is_string($version)
                || preg_match('/\A[A-Za-z0-9_.-]{1,32}\z/D', $version) !== 1
                || !is_string($hash)
                || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
                throw new InvalidApiCommandInput('API command fingerprint key candidates are invalid.');
            }
        }
    }

    public function institutionPublicId(): string { return $this->institutionPublicId; }
    public function serviceAccountPublicId(): string { return $this->serviceAccountPublicId; }
    public function operation(): string { return $this->operation; }
    public function aggregatePublicId(): string { return $this->aggregatePublicId; }
    public function requestHash(): string { return $this->requestHash; }
    public function activeKeyVersion(): string { return $this->activeKeyVersion; }
    public function activeKeyHash(): string { return $this->activeKeyHash; }

    /** @return array<string, string> */
    public function candidateKeyHashes(): array { return $this->candidateKeyHashes; }
}
