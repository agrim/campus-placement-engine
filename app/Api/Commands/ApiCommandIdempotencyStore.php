<?php

declare(strict_types=1);

namespace App\Api\Commands;

use App\Api\Security\ApiKeyring;
use App\Core\Institution\InstitutionContext;
use App\Core\Institution\InstitutionRepository;
use App\Core\Persistence\WriteTransaction;
use App\Support\Database;
use JsonException;
use PDO;
use RuntimeException;

/**
 * Transaction-local application-transition idempotency storage.
 *
 * A caller must complete a new reservation before its outer WriteTransaction
 * commits. A committed pending or malformed record is deliberately unavailable,
 * never interpreted as permission to execute the command again.
 */
final class ApiCommandIdempotencyStore
{
    public function __construct(
        private readonly ?PDO $connection = null,
        private readonly ?ApiKeyring $configuredKeyring = null,
    ) {
    }

    public function reserve(int $serviceAccountId, ApiCommandFingerprint $fingerprint): ApiCommandReservation
    {
        $pdo = $this->pdo();
        $this->requireTransaction($pdo);
        $prior = $this->resolve($serviceAccountId, $fingerprint);
        if ($prior !== null) {
            return $prior;
        }

        $institution = $this->currentInstitution($pdo, $fingerprint);
        $this->lockAndValidateAccount($pdo, $institution, $serviceAccountId, $fingerprint);
        $now = cpe_now();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + ApiCommandHasher::RETRY_HOURS * 3600);
        $sql = 'INSERT INTO api_command_idempotency_keys
                (institution_id, service_account_id, operation, key_version, key_hash,
                 request_hash, aggregate_public_id, lifecycle_state, created_at, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $sql .= ' ON CONFLICT (institution_id, operation, key_hash) DO NOTHING';
        $insert = $pdo->prepare($sql);
        $insert->execute([
            $institution->id(),
            $serviceAccountId,
            $fingerprint->operation(),
            $fingerprint->activeKeyVersion(),
            $fingerprint->activeKeyHash(),
            $fingerprint->requestHash(),
            $fingerprint->aggregatePublicId(),
            'pending',
            $now,
            $expiresAt,
        ]);
        if ($insert->rowCount() === 1) {
            return ApiCommandReservation::pending(Database::lastInsertId($pdo));
        }

        $prior = $this->resolve($serviceAccountId, $fingerprint);
        if ($prior === null) {
            throw new ApiCommandUnavailable();
        }
        return $prior;
    }

    public function resolve(int $serviceAccountId, ApiCommandFingerprint $fingerprint): ?ApiCommandReservation
    {
        $pdo = $this->pdo();
        $this->requireTransaction($pdo);
        $institution = $this->currentInstitution($pdo, $fingerprint);
        $this->lockAndValidateAccount($pdo, $institution, $serviceAccountId, $fingerprint);
        $keyring = $this->keyring();
        $now = cpe_now();
        $this->assertReferencedVersionsAvailable($pdo, $institution->id(), $keyring, $now);

        $candidateHashes = $fingerprint->candidateKeyHashes();
        if ($candidateHashes === []) {
            throw new ApiCommandUnavailable();
        }
        $hashes = array_values($candidateHashes);
        $placeholders = implode(', ', array_fill(0, count($hashes), '?'));
        $query = $pdo->prepare(
            'SELECT * FROM api_command_idempotency_keys
             WHERE institution_id = ? AND operation = ? AND key_hash IN (' . $placeholders . ')
             ORDER BY id' . ($this->isPostgres($pdo) ? ' FOR UPDATE' : ''),
        );
        $query->execute([$institution->id(), $fingerprint->operation(), ...$hashes]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new ApiCommandUnavailable();
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $version = (string) ($row['key_version'] ?? '');
        $storedKeyHash = (string) ($row['key_hash'] ?? '');
        if (!isset($candidateHashes[$version]) || !hash_equals($candidateHashes[$version], $storedKeyHash)) {
            throw new ApiCommandUnavailable();
        }
        if ((string) ($row['expires_at'] ?? '') <= $now) {
            $delete = $pdo->prepare(
                'DELETE FROM api_command_idempotency_keys
                 WHERE id = ? AND institution_id = ? AND expires_at <= ?',
            );
            $delete->execute([(int) $row['id'], $institution->id(), $now]);
            return null;
        }
        if ((int) ($row['service_account_id'] ?? 0) !== $serviceAccountId) {
            throw new ApiCommandConflict(ApiCommandConflict::ACCOUNT);
        }
        if (!hash_equals((string) ($row['request_hash'] ?? ''), $fingerprint->requestHash())
            || !hash_equals((string) ($row['aggregate_public_id'] ?? ''), $fingerprint->aggregatePublicId())) {
            throw new ApiCommandConflict(ApiCommandConflict::REQUEST);
        }
        if (($row['lifecycle_state'] ?? null) === 'pending') {
            throw new ApiCommandUnavailable();
        }
        if (($row['lifecycle_state'] ?? null) !== 'completed') {
            throw new ApiCommandUnavailable();
        }
        return $this->completedReservation($row);
    }

    /** @param array<string, mixed> $response */
    public function complete(
        int $serviceAccountId,
        ApiCommandFingerprint $fingerprint,
        ApiCommandReservation $reservation,
        array $response,
        int $statusCode,
        string $etag,
    ): ApiCommandReservation {
        $pdo = $this->pdo();
        $this->requireTransaction($pdo);
        if ($reservation->isReplay()
            || $statusCode !== 200
            || preg_match('/\A"[a-f0-9]{64}"\z/D', $etag) !== 1) {
            throw new InvalidApiCommandInput('API command completion is invalid.');
        }
        $responseJson = ApiCommandHasher::canonicalObject($response);
        $institution = $this->currentInstitution($pdo, $fingerprint);
        $this->lockAndValidateAccount($pdo, $institution, $serviceAccountId, $fingerprint);
        $now = cpe_now();
        $update = $pdo->prepare(
            "UPDATE api_command_idempotency_keys
             SET lifecycle_state = 'completed', response_json = ?, response_status = ?,
                 response_etag = ?, completed_at = ?
             WHERE id = ? AND institution_id = ? AND service_account_id = ?
               AND operation = ? AND key_version = ? AND key_hash = ?
               AND request_hash = ? AND aggregate_public_id = ?
               AND lifecycle_state = 'pending' AND expires_at > ?",
        );
        $update->execute([
            $responseJson,
            $statusCode,
            $etag,
            $now,
            $reservation->recordId(),
            $institution->id(),
            $serviceAccountId,
            $fingerprint->operation(),
            $fingerprint->activeKeyVersion(),
            $fingerprint->activeKeyHash(),
            $fingerprint->requestHash(),
            $fingerprint->aggregatePublicId(),
            $now,
        ]);
        if ($update->rowCount() !== 1) {
            throw new ApiCommandUnavailable();
        }
        return ApiCommandReservation::replay(
            $reservation->recordId(),
            $responseJson,
            $statusCode,
            $etag,
        );
    }

    public function pruneExpiredCurrentInstitution(int $limit = 1000): int
    {
        $pdo = $this->pdo();
        $this->requireTransaction($pdo);
        if ($limit < 1 || $limit > 5000) {
            throw new InvalidApiCommandInput('API command prune limit must be between 1 and 5000.');
        }
        $institutionId = (new InstitutionRepository($pdo))->current()->id();
        $delete = $pdo->prepare(
            'DELETE FROM api_command_idempotency_keys WHERE id IN (
                SELECT id FROM api_command_idempotency_keys
                WHERE institution_id = ? AND expires_at <= ? ORDER BY id LIMIT ?
             )',
        );
        $delete->bindValue(1, $institutionId, PDO::PARAM_INT);
        $delete->bindValue(2, cpe_now(), PDO::PARAM_STR);
        $delete->bindValue(3, $limit, PDO::PARAM_INT);
        $delete->execute();
        return $delete->rowCount();
    }

    private function pdo(): PDO
    {
        return $this->connection ?? Database::connection();
    }

    private function keyring(): ApiKeyring
    {
        return $this->configuredKeyring ?? ApiKeyring::fromEnvironment();
    }

    private function requireTransaction(PDO $pdo): void
    {
        if (!WriteTransaction::isActive($pdo)) {
            throw new RuntimeException('API command idempotency storage requires an active write transaction.');
        }
    }

    private function currentInstitution(PDO $pdo, ApiCommandFingerprint $fingerprint): InstitutionContext
    {
        $institution = (new InstitutionRepository($pdo))->current();
        if ($institution->id() <= 0
            || !hash_equals($institution->publicId(), $fingerprint->institutionPublicId())) {
            throw new ApiCommandUnavailable();
        }
        if ($this->isPostgres($pdo)) {
            // Canonical PostgreSQL command lock order is institution, service
            // account, then idempotency row. The institution row is the
            // version-independent gate for cross-account/key-rotation races.
            $lock = $pdo->prepare(
                "SELECT id, public_id FROM institutions
                 WHERE id = ? AND slug = 'default' FOR UPDATE",
            );
            $lock->execute([$institution->id()]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($locked)
                || (int) ($locked['id'] ?? 0) !== $institution->id()
                || !hash_equals((string) ($locked['public_id'] ?? ''), $fingerprint->institutionPublicId())) {
                throw new ApiCommandUnavailable();
            }
        }
        return $institution;
    }

    private function lockAndValidateAccount(
        PDO $pdo,
        InstitutionContext $institution,
        int $serviceAccountId,
        ApiCommandFingerprint $fingerprint,
    ): void {
        if ($serviceAccountId <= 0) {
            throw new InvalidApiCommandInput('API command service account is invalid.');
        }
        $account = $pdo->prepare(
            'SELECT id, public_id, institution_id FROM api_service_accounts
             WHERE id = ?' . ($this->isPostgres($pdo) ? ' FOR UPDATE' : ''),
        );
        $account->execute([$serviceAccountId]);
        $row = $account->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || (int) ($row['institution_id'] ?? 0) !== $institution->id()
            || !hash_equals((string) ($row['public_id'] ?? ''), $fingerprint->serviceAccountPublicId())) {
            throw new ApiCommandConflict(ApiCommandConflict::ACCOUNT);
        }
    }

    private function assertReferencedVersionsAvailable(
        PDO $pdo,
        int $institutionId,
        ApiKeyring $keyring,
        string $now,
    ): void {
        $versions = $pdo->prepare(
            'SELECT DISTINCT key_version FROM api_command_idempotency_keys
             WHERE institution_id = ? AND expires_at > ?',
        );
        $versions->execute([$institutionId, $now]);
        foreach ($versions->fetchAll(PDO::FETCH_COLUMN) as $version) {
            if (!is_string($version) || !$keyring->hasVersion($version)) {
                throw new ApiCommandUnavailable();
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function completedReservation(array $row): ApiCommandReservation
    {
        $responseJson = $row['response_json'] ?? null;
        $status = $row['response_status'] ?? null;
        $etag = $row['response_etag'] ?? null;
        if (!is_string($responseJson)
            || !in_array($status, [200, '200'], true)
            || !is_string($etag)
            || preg_match('/\A"[a-f0-9]{64}"\z/D', $etag) !== 1) {
            throw new ApiCommandUnavailable();
        }
        try {
            $decoded = json_decode($responseJson, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !hash_equals(ApiCommandHasher::canonicalObject($decoded), $responseJson)) {
                throw new ApiCommandUnavailable();
            }
        } catch (JsonException|InvalidApiCommandInput) {
            throw new ApiCommandUnavailable();
        }
        return ApiCommandReservation::replay((int) $row['id'], $responseJson, 200, $etag);
    }

    private function isPostgres(PDO $pdo): bool
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
