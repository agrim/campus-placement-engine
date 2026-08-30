<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Core\Institution\InstitutionRepository;
use App\Support\Database;
use PDO;
use Throwable;

/** Verifier-only API token authentication; no browser/session authentication is accepted. */
final class ApiTokenAuthenticator
{
    private const LAST_USED_WRITE_INTERVAL_SECONDS = 900;

    public function __construct(
        private readonly ?PDO $connection = null,
        private readonly ?ApiKeyring $configuredKeyring = null,
    ) {
    }

    public function authenticate(string $presentedToken): ApiPrincipal
    {
        $pdo = $this->connection ?? Database::connection();
        try {
            $institution = (new InstitutionRepository($pdo))->current();
            $enabled = $this->apiEnabled($pdo);
        } catch (Throwable $failure) {
            throw new ApiAuthenticationUnavailable('API availability state is unavailable.', 0, $failure);
        }
        if (!$enabled) {
            throw new InvalidApiCredential();
        }
        try {
            $keyring = $this->configuredKeyring ?? ApiKeyring::fromEnvironment();
        } catch (ApiAuthenticationUnavailable $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new ApiAuthenticationUnavailable('The external API keyring is unavailable.', 0, $failure);
        }
        [$parsed, $lookupId, $secretBytes] = $this->parseForVerification($presentedToken);

        try {
            $query = $pdo->prepare(
                'SELECT token.id AS token_id, token.lookup_id, token.secret_verifier, token.key_version,
                        token.expires_at, token.rotation_grace_expires_at, token.revoked_at,
                        account.id AS account_id, account.public_id AS account_public_id,
                        account.institution_id, account.status AS account_status
                 FROM api_access_tokens token
                 JOIN api_service_accounts account ON account.id = token.service_account_id
                 WHERE token.lookup_id = ?',
            );
            $query->execute([$lookupId]);
            $record = $query->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $failure) {
            throw new ApiAuthenticationUnavailable('API credential storage is unavailable.', 0, $failure);
        }

        $version = is_array($record) ? (string) ($record['key_version'] ?? '') : $keyring->activeVersion();
        if (!$keyring->hasVersion($version)) {
            throw new ApiAuthenticationUnavailable('A referenced API key version is unavailable.');
        }
        $candidate = $keyring->tokenVerifier(
            $secretBytes,
            $institution->publicId(),
            $lookupId,
            $version,
        );
        $dummyExpected = $keyring->tokenVerifier(
            str_repeat("\0", 32),
            $institution->publicId(),
            $lookupId,
            $version,
        );
        $storedVerifier = is_array($record) ? $this->binary($record['secret_verifier'] ?? '') : null;
        $expected = is_string($storedVerifier) && strlen($storedVerifier) === 32
            ? $storedVerifier
            : $dummyExpected;
        $matches = hash_equals($expected, $candidate);
        $now = cpe_now();
        $valid = $parsed
            && is_array($record)
            && $matches
            && $enabled
            && (int) ($record['institution_id'] ?? 0) === $institution->id()
            && (string) ($record['account_status'] ?? '') === 'enabled'
            && ($record['revoked_at'] ?? null) === null
            && (string) ($record['expires_at'] ?? '') > $now
            && (($record['rotation_grace_expires_at'] ?? null) === null
                || (string) $record['rotation_grace_expires_at'] > $now);
        if (!$valid) {
            throw new InvalidApiCredential();
        }

        try {
            $scopeQuery = $pdo->prepare(
                'SELECT scope FROM api_service_account_scopes WHERE service_account_id = ? ORDER BY scope',
            );
            $scopeQuery->execute([(int) $record['account_id']]);
            $scopes = array_map('strval', $scopeQuery->fetchAll(PDO::FETCH_COLUMN));
            foreach ($scopes as $scope) {
                if (!in_array($scope, ApiScopePolicy::supportedScopes(), true)) {
                    throw new ApiAuthorizationUnavailable('API scope storage contains an unsupported grant.');
                }
            }
            $this->touchLastUsed($pdo, (int) $record['token_id'], $now);
        } catch (ApiAuthorizationUnavailable $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new ApiAuthenticationUnavailable('API credential usage state is unavailable.', 0, $failure);
        }

        return new ApiPrincipal(
            $institution->id(),
            $institution->publicId(),
            (int) $record['account_id'],
            (string) $record['account_public_id'],
            (int) $record['token_id'],
            (string) $record['lookup_id'],
            $scopes,
        );
    }

    /** @return array{0: bool, 1: string, 2: string} */
    private function parseForVerification(string $token): array
    {
        if (preg_match('/^cpe_live_apitok_([a-f0-9]{32})\.([A-Za-z0-9_-]{43})$/D', $token, $match) === 1) {
            $secret = ApiKeyring::base64UrlDecode($match[2]);
            if (is_string($secret)
                && strlen($secret) === 32
                && hash_equals(ApiKeyring::base64UrlEncode($secret), $match[2])) {
                return [true, $match[1], $secret];
            }
        }
        return [false, str_repeat('0', 32), str_repeat("\0", 32)];
    }

    private function apiEnabled(PDO $pdo): bool
    {
        $query = $pdo->query("SELECT value FROM settings WHERE key = 'api_enabled'");
        $values = $query->fetchAll(PDO::FETCH_COLUMN);
        return count($values) === 1 && $values[0] === '1';
    }

    private function touchLastUsed(PDO $pdo, int $tokenId, string $now): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::LAST_USED_WRITE_INTERVAL_SECONDS);
        $update = $pdo->prepare(
            'UPDATE api_access_tokens SET last_used_at = ?, updated_at = ?
             WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)',
        );
        $update->execute([$now, $now, $tokenId, $cutoff]);
    }

    private function binary(mixed $value): ?string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            return is_string($contents) ? $contents : null;
        }
        return is_string($value) ? $value : null;
    }
}
