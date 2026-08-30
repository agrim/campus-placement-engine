<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Core\Http\UserVisibleException;
use App\Core\Institution\InstitutionRepository;
use App\Core\Persistence\WriteTransaction;
use App\Support\Auth;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/** Institution-local service-account and verifier-only token lifecycle. */
final class ApiServiceAccountService
{
    public const DEFAULT_EXPIRY_DAYS = 90;
    public const MAX_EXPIRY_DAYS = 365;
    public const ROTATION_GRACE_SECONDS = 86400;

    public function __construct(
        private readonly ?PDO $connection = null,
        private readonly ?ApiKeyring $configuredKeyring = null,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @return array{service_account_id: string, token_lookup_id: string, token: string, expires_at: string}
     */
    public function create(
        string $name,
        array $scopes,
        int $actorUserId,
        int $expiryDays = self::DEFAULT_EXPIRY_DAYS,
    ): array {
        $pdo = $this->pdo();
        $name = $this->normalizeName($name);
        $scopes = $this->normalizeScopes($scopes);
        $expiryDays = $this->normalizeExpiryDays($expiryDays);
        return WriteTransaction::run($pdo, function () use ($pdo, $name, $scopes, $actorUserId, $expiryDays): array {
            ApiManagementAuthorization::requireActor($pdo, $actorUserId);
            (new ApiScopePolicy($pdo))->assertProvisionable($scopes);
            $institution = (new InstitutionRepository($pdo))->current();
            $now = cpe_now();
            $publicId = 'apisa_' . bin2hex(random_bytes(16));
            $insert = $pdo->prepare(
                'INSERT INTO api_service_accounts
                 (public_id, institution_id, name, status, disabled_at, revoked_at,
                  created_by_user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?)',
            );
            $insert->execute([$publicId, $institution->id(), $name, 'enabled', $actorUserId, $now, $now]);
            $accountId = Database::lastInsertId($pdo);

            $scopeInsert = $pdo->prepare(
                'INSERT INTO api_service_account_scopes
                 (service_account_id, scope, created_by_user_id, created_at) VALUES (?, ?, ?, ?)',
            );
            foreach ($scopes as $scope) {
                $scopeInsert->execute([$accountId, $scope, $actorUserId, $now]);
            }
            $token = $this->issueToken($pdo, $accountId, $institution->publicId(), $actorUserId, $expiryDays, $now);
            Auth::audit(
                $actorUserId,
                'api.service_account.create',
                'api_service_account',
                $accountId,
                '',
                $pdo,
            );
            return [
                'service_account_id' => $publicId,
                ...$token,
            ];
        });
    }

    /** @return array{service_account_id: string, token_lookup_id: string, token: string, expires_at: string} */
    public function rotateToken(
        string $serviceAccountPublicId,
        int $actorUserId,
        int $expiryDays = self::DEFAULT_EXPIRY_DAYS,
    ): array {
        $pdo = $this->pdo();
        $serviceAccountPublicId = $this->normalizeServiceAccountPublicId($serviceAccountPublicId);
        $expiryDays = $this->normalizeExpiryDays($expiryDays);
        return WriteTransaction::run($pdo, function () use ($pdo, $serviceAccountPublicId, $actorUserId, $expiryDays): array {
            ApiManagementAuthorization::requireActor($pdo, $actorUserId);
            $account = $this->lockedAccount($pdo, $serviceAccountPublicId);
            if ((string) $account['status'] === 'revoked') {
                throw new UserVisibleException('API_SERVICE_ACCOUNT_REVOKED', 'A revoked service account cannot receive a new token.');
            }
            $scopes = $this->scopesForAccount($pdo, (int) $account['id']);
            (new ApiScopePolicy($pdo))->assertProvisionable($scopes);
            $now = cpe_now();
            $this->retireTokensForRotation($pdo, (int) $account['id'], $now);
            $token = $this->issueToken(
                $pdo,
                (int) $account['id'],
                (string) $account['institution_public_id'],
                $actorUserId,
                $expiryDays,
                $now,
            );
            Auth::audit(
                $actorUserId,
                'api.token.rotate',
                'api_service_account',
                (int) $account['id'],
                '',
                $pdo,
            );
            return ['service_account_id' => $serviceAccountPublicId, ...$token];
        });
    }

    public function revokeToken(string $lookupId, int $actorUserId): void
    {
        $pdo = $this->pdo();
        if (preg_match('/^[a-f0-9]{32}$/D', $lookupId) !== 1) {
            throw new UserVisibleException('API_TOKEN_ID_INVALID', 'The API token lookup ID is invalid.');
        }
        WriteTransaction::run($pdo, function () use ($pdo, $lookupId, $actorUserId): void {
            ApiManagementAuthorization::requireActor($pdo, $actorUserId);
            $institution = (new InstitutionRepository($pdo))->current();
            $suffix = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE OF account' : '';
            $query = $pdo->prepare(
                'SELECT token.id, token.revoked_at, account.id AS account_id
                 FROM api_access_tokens token
                 JOIN api_service_accounts account ON account.id = token.service_account_id
                 WHERE token.lookup_id = ? AND account.institution_id = ?' . $suffix,
            );
            $query->execute([$lookupId, $institution->id()]);
            $token = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($token)) {
                throw new UserVisibleException('API_TOKEN_NOT_FOUND', 'API token not found.');
            }
            if ($token['revoked_at'] === null) {
                $now = cpe_now();
                $update = $pdo->prepare('UPDATE api_access_tokens SET revoked_at = ?, updated_at = ? WHERE id = ? AND revoked_at IS NULL');
                $update->execute([$now, $now, (int) $token['id']]);
            }
            Auth::audit($actorUserId, 'api.token.revoke', 'api_access_token', (int) $token['id'], '', $pdo);
        });
    }

    public function setAccountEnabled(string $serviceAccountPublicId, bool $enabled, int $actorUserId): void
    {
        $pdo = $this->pdo();
        $serviceAccountPublicId = $this->normalizeServiceAccountPublicId($serviceAccountPublicId);
        WriteTransaction::run($pdo, function () use ($pdo, $serviceAccountPublicId, $enabled, $actorUserId): void {
            ApiManagementAuthorization::requireActor($pdo, $actorUserId);
            $account = $this->lockedAccount($pdo, $serviceAccountPublicId);
            if ((string) $account['status'] === 'revoked') {
                throw new UserVisibleException('API_SERVICE_ACCOUNT_REVOKED', 'A revoked service account cannot be re-enabled.');
            }
            if ($enabled) {
                (new ApiScopePolicy($pdo))->assertProvisionable(
                    $this->scopesForAccount($pdo, (int) $account['id']),
                );
                $this->assertAccountKeyReferences($pdo, (int) $account['id']);
            }
            $now = cpe_now();
            $status = $enabled ? 'enabled' : 'disabled';
            $disabledAt = $enabled ? null : $now;
            $update = $pdo->prepare(
                'UPDATE api_service_accounts SET status = ?, disabled_at = ?, updated_at = ?
                 WHERE id = ? AND status <> ?',
            );
            $update->execute([$status, $disabledAt, $now, (int) $account['id'], 'revoked']);
            Auth::audit(
                $actorUserId,
                $enabled ? 'api.service_account.enable' : 'api.service_account.disable',
                'api_service_account',
                (int) $account['id'],
                '',
                $pdo,
            );
        });
    }

    public function revokeAccount(string $serviceAccountPublicId, int $actorUserId): void
    {
        $pdo = $this->pdo();
        $serviceAccountPublicId = $this->normalizeServiceAccountPublicId($serviceAccountPublicId);
        WriteTransaction::run($pdo, function () use ($pdo, $serviceAccountPublicId, $actorUserId): void {
            ApiManagementAuthorization::requireActor($pdo, $actorUserId);
            $account = $this->lockedAccount($pdo, $serviceAccountPublicId);
            $now = cpe_now();
            if ((string) $account['status'] !== 'revoked') {
                $update = $pdo->prepare(
                    'UPDATE api_service_accounts SET status = ?, revoked_at = ?, updated_at = ? WHERE id = ?',
                );
                $update->execute(['revoked', $now, $now, (int) $account['id']]);
                $tokens = $pdo->prepare(
                    'UPDATE api_access_tokens SET revoked_at = ?, updated_at = ?
                     WHERE service_account_id = ? AND revoked_at IS NULL',
                );
                $tokens->execute([$now, $now, (int) $account['id']]);
            }
            Auth::audit(
                $actorUserId,
                'api.service_account.revoke',
                'api_service_account',
                (int) $account['id'],
                '',
                $pdo,
            );
        });
    }

    public function setApiEnabled(bool $enabled, int $actorUserId): void
    {
        $pdo = $this->pdo();
        WriteTransaction::run($pdo, function () use ($pdo, $enabled, $actorUserId): void {
            ApiManagementAuthorization::requireActor($pdo, $actorUserId);
            if ($enabled) {
                (new ApiScopePolicy($pdo))->assertProvisionable(ApiScopePolicy::supportedScopes());
                $this->assertAllKeyReferences($pdo);
            }
            $setting = $pdo->prepare(
                'INSERT INTO settings (key, value) VALUES (?, ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            );
            $setting->execute(['api_enabled', $enabled ? '1' : '0']);
            Auth::audit(
                $actorUserId,
                $enabled ? 'api.enable' : 'api.disable',
                'system',
                null,
                '',
                $pdo,
            );
        });
    }

    /** @return list<array<string, mixed>> */
    public function listForAdministrator(): array
    {
        $pdo = $this->pdo();
        $accounts = $pdo->query(
            'SELECT account.id, account.public_id, account.name, account.status,
                    account.disabled_at, account.revoked_at, account.created_at, account.updated_at
             FROM api_service_accounts account
             JOIN institutions institution ON institution.id = account.institution_id
             WHERE institution.slug = \'default\'
             ORDER BY account.created_at DESC, account.id DESC',
        )->fetchAll(PDO::FETCH_ASSOC);
        $scopeQuery = $pdo->prepare(
            'SELECT scope FROM api_service_account_scopes WHERE service_account_id = ? ORDER BY scope',
        );
        $tokenQuery = $pdo->prepare(
            'SELECT lookup_id, key_version, expires_at, rotation_grace_expires_at,
                    revoked_at, last_used_at, created_at
             FROM api_access_tokens WHERE service_account_id = ? ORDER BY id DESC',
        );
        foreach ($accounts as &$account) {
            $scopeQuery->execute([(int) $account['id']]);
            $account['scopes'] = array_map('strval', $scopeQuery->fetchAll(PDO::FETCH_COLUMN));
            $tokenQuery->execute([(int) $account['id']]);
            $account['tokens'] = $tokenQuery->fetchAll(PDO::FETCH_ASSOC);
            unset($account['id']);
        }
        unset($account);
        return $accounts;
    }

    public function listForActor(int $actorUserId): array
    {
        ApiManagementAuthorization::requireActor($this->pdo(), $actorUserId);
        return $this->listForAdministrator();
    }

    private function pdo(): PDO
    {
        return $this->connection ?? Database::connection();
    }

    /** @return array{token_lookup_id: string, token: string, expires_at: string} */
    private function issueToken(
        PDO $pdo,
        int $accountId,
        string $institutionPublicId,
        int $actorUserId,
        int $expiryDays,
        string $now,
    ): array {
        $keyring = $this->keyring();
        $lookupId = bin2hex(random_bytes(16));
        $secretBytes = random_bytes(32);
        $version = $keyring->activeVersion();
        $verifier = $keyring->tokenVerifier($secretBytes, $institutionPublicId, $lookupId, $version);
        $expiresAt = $this->plusSeconds($now, $expiryDays * 86400);
        $insert = $pdo->prepare(
            'INSERT INTO api_access_tokens
             (service_account_id, lookup_id, secret_verifier, key_version, expires_at,
              rotation_grace_expires_at, revoked_at, last_used_at,
              created_by_user_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?)',
        );
        $insert->bindValue(1, $accountId, PDO::PARAM_INT);
        $insert->bindValue(2, $lookupId, PDO::PARAM_STR);
        $insert->bindValue(3, $verifier, PDO::PARAM_LOB);
        $insert->bindValue(4, $version, PDO::PARAM_STR);
        $insert->bindValue(5, $expiresAt, PDO::PARAM_STR);
        $insert->bindValue(6, $actorUserId, PDO::PARAM_INT);
        $insert->bindValue(7, $now, PDO::PARAM_STR);
        $insert->bindValue(8, $now, PDO::PARAM_STR);
        $insert->execute();
        return [
            'token_lookup_id' => $lookupId,
            'token' => 'cpe_live_apitok_' . $lookupId . '.' . ApiKeyring::base64UrlEncode($secretBytes),
            'expires_at' => $expiresAt,
        ];
    }

    private function retireTokensForRotation(PDO $pdo, int $accountId, string $now): void
    {
        $suffix = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
        $query = $pdo->prepare(
            'SELECT id, expires_at, rotation_grace_expires_at
             FROM api_access_tokens
             WHERE service_account_id = ? AND revoked_at IS NULL
             ORDER BY id DESC' . $suffix,
        );
        $query->execute([$accountId]);
        $tokens = $query->fetchAll(PDO::FETCH_ASSOC);
        $current = array_values(array_filter(
            $tokens,
            static fn (array $row): bool => $row['rotation_grace_expires_at'] === null,
        ));
        if (count($current) > 1) {
            throw new RuntimeException('API token lifecycle contains multiple current tokens.');
        }
        $currentId = $current === [] ? 0 : (int) $current[0]['id'];
        $nowTimestamp = $this->timestamp($now);
        $graceBoundary = $nowTimestamp + self::ROTATION_GRACE_SECONDS;
        $revoke = $pdo->prepare(
            'UPDATE api_access_tokens SET revoked_at = ?, updated_at = ? WHERE id = ? AND revoked_at IS NULL',
        );
        $grace = $pdo->prepare(
            'UPDATE api_access_tokens SET rotation_grace_expires_at = ?, updated_at = ?
             WHERE id = ? AND revoked_at IS NULL AND rotation_grace_expires_at IS NULL',
        );
        foreach ($tokens as $token) {
            $tokenId = (int) $token['id'];
            $expiryTimestamp = $this->timestamp((string) $token['expires_at']);
            if ($tokenId === $currentId && $expiryTimestamp > $nowTimestamp) {
                $graceExpiresAt = gmdate('Y-m-d H:i:s', min($expiryTimestamp, $graceBoundary));
                $grace->execute([$graceExpiresAt, $now, $tokenId]);
                continue;
            }
            $revoke->execute([$now, $now, $tokenId]);
        }
    }

    private function lockedAccount(PDO $pdo, string $publicId): array
    {
        $suffix = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE OF account' : '';
        $query = $pdo->prepare(
            'SELECT account.*, institution.public_id AS institution_public_id
             FROM api_service_accounts account
             JOIN institutions institution ON institution.id = account.institution_id
             WHERE account.public_id = ? AND institution.slug = \'default\'' . $suffix,
        );
        $query->execute([$publicId]);
        $account = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new UserVisibleException('API_SERVICE_ACCOUNT_NOT_FOUND', 'API service account not found.');
        }
        return $account;
    }

    /** @return list<string> */
    private function scopesForAccount(PDO $pdo, int $accountId): array
    {
        $query = $pdo->prepare(
            'SELECT scope FROM api_service_account_scopes WHERE service_account_id = ? ORDER BY scope',
        );
        $query->execute([$accountId]);
        return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assertAllKeyReferences(PDO $pdo): void
    {
        $keyring = $this->keyring();
        $now = cpe_now();
        $query = $pdo->prepare(
            'SELECT DISTINCT token.key_version
             FROM api_access_tokens token
             JOIN api_service_accounts account ON account.id = token.service_account_id
             WHERE token.revoked_at IS NULL AND token.expires_at > ?
               AND (token.rotation_grace_expires_at IS NULL OR token.rotation_grace_expires_at > ?)
               AND account.status <> ?',
        );
        $query->execute([$now, $now, 'revoked']);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $version) {
            if (!$keyring->hasVersion((string) $version)) {
                throw new UserVisibleException(
                    'API_KEY_VERSION_MISSING',
                    'A usable API token references a key version missing from the external keyring.',
                );
            }
        }
    }

    private function assertAccountKeyReferences(PDO $pdo, int $accountId): void
    {
        $keyring = $this->keyring();
        $now = cpe_now();
        $query = $pdo->prepare(
            'SELECT DISTINCT key_version FROM api_access_tokens
             WHERE service_account_id = ? AND revoked_at IS NULL AND expires_at > ?
               AND (rotation_grace_expires_at IS NULL OR rotation_grace_expires_at > ?)',
        );
        $query->execute([$accountId, $now, $now]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $version) {
            if (!$keyring->hasVersion((string) $version)) {
                throw new UserVisibleException(
                    'API_KEY_VERSION_MISSING',
                    'A usable API token references a key version missing from the external keyring.',
                );
            }
        }
    }

    private function keyring(): ApiKeyring
    {
        return $this->configuredKeyring ?? ApiKeyring::fromEnvironment();
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace('/\s+/', ' ', trim(strip_tags($name))) ?? '';
        if ($name === '' || mb_strlen($name) > 120) {
            throw new UserVisibleException('API_SERVICE_ACCOUNT_NAME_INVALID', 'Service-account name must be 1 to 120 characters.');
        }
        return $name;
    }

    /** @param list<string> $scopes @return list<string> */
    private function normalizeScopes(array $scopes): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (mixed $scope): string => trim((string) $scope),
            $scopes,
        )));
        sort($normalized);
        if ($normalized === []) {
            throw new UserVisibleException('API_SCOPES_INVALID', 'Choose at least one supported API scope.');
        }
        foreach ($normalized as $scope) {
            if (!in_array($scope, ApiScopePolicy::supportedScopes(), true)) {
                throw new UserVisibleException('API_SCOPE_UNSUPPORTED', 'The requested API scope is not supported.');
            }
        }
        return $normalized;
    }

    private function normalizeExpiryDays(int $days): int
    {
        if ($days < 1 || $days > self::MAX_EXPIRY_DAYS) {
            throw new UserVisibleException(
                'API_TOKEN_EXPIRY_INVALID',
                'API token expiry must be between 1 and 365 days.',
            );
        }
        return $days;
    }

    private function normalizeServiceAccountPublicId(string $publicId): string
    {
        $publicId = trim($publicId);
        if (preg_match('/^apisa_[a-f0-9]{32}$/D', $publicId) !== 1) {
            throw new UserVisibleException('API_SERVICE_ACCOUNT_ID_INVALID', 'The API service-account ID is invalid.');
        }
        return $publicId;
    }

    private function plusSeconds(string $timestamp, int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', $this->timestamp($timestamp) + $seconds);
    }

    private function timestamp(string $value): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new RuntimeException('API lifecycle timestamp is invalid.');
        }
        return $date->getTimestamp();
    }
}
