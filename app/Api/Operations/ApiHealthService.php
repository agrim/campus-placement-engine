<?php

declare(strict_types=1);

namespace App\Api\Operations;

use App\Api\Security\ApiAuthorizationUnavailable;
use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiScopePolicy;
use App\Core\Institution\InstitutionRepository;
use App\Support\Database;
use PDO;
use RuntimeException;

/** Aggregate-only operational state for the disabled-by-default API foundation. */
final class ApiHealthService
{
    private const REQUIRED_RELATIONS = [
        'api_service_accounts',
        'api_service_account_scopes',
        'api_access_tokens',
        'api_rate_limit_buckets',
        'api_request_audit_events',
    ];

    public function __construct(private readonly ?PDO $connection = null)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $pdo = $this->connection ?? Database::connection();
        $present = 0;
        foreach (self::REQUIRED_RELATIONS as $relation) {
            if ($this->tableExists($pdo, $relation)) {
                $present++;
            }
        }
        if ($present === 0) {
            return $this->emptySnapshot();
        }
        if ($present !== count(self::REQUIRED_RELATIONS)) {
            throw new RuntimeException('API identity storage is only partially installed.');
        }
        foreach (self::REQUIRED_RELATIONS as $relation) {
            $pdo->query('SELECT 1 FROM ' . $relation . ' WHERE 1 = 0')->fetchColumn();
        }
        $setting = $pdo->query("SELECT value FROM settings WHERE key = 'api_enabled'")->fetchAll(PDO::FETCH_COLUMN);
        if (count($setting) !== 1 || !in_array($setting[0], ['0', '1'], true)) {
            throw new RuntimeException('The local API enabled setting is unavailable or invalid.');
        }
        $enabled = $setting[0] === '1';
        $institution = (new InstitutionRepository($pdo))->current();
        $states = ['enabled' => 0, 'disabled' => 0, 'revoked' => 0];
        $stateQuery = $pdo->prepare(
            'SELECT status, COUNT(*) AS state_count
             FROM api_service_accounts WHERE institution_id = ? GROUP BY status',
        );
        $stateQuery->execute([$institution->id()]);
        foreach ($stateQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) ($row['status'] ?? '');
            if (!array_key_exists($status, $states)) {
                throw new RuntimeException('API service-account lifecycle state is invalid.');
            }
            $states[$status] = (int) $row['state_count'];
        }
        $now = cpe_now();
        $tokenQuery = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN token.revoked_at IS NULL AND token.expires_at > ?
                          AND (token.rotation_grace_expires_at IS NULL OR token.rotation_grace_expires_at > ?)
                          AND account.status = 'enabled' THEN 1 ELSE 0 END) AS usable_count,
                SUM(CASE WHEN token.revoked_at IS NOT NULL THEN 1 ELSE 0 END) AS revoked_count,
                SUM(CASE WHEN token.revoked_at IS NULL AND token.expires_at <= ? THEN 1 ELSE 0 END) AS expired_count
             FROM api_access_tokens token
             JOIN api_service_accounts account ON account.id = token.service_account_id
             WHERE account.institution_id = ?",
        );
        $tokenQuery->execute([$now, $now, $now, $institution->id()]);
        $tokens = $tokenQuery->fetch(PDO::FETCH_ASSOC) ?: [];
        $versions = $pdo->prepare(
            "SELECT DISTINCT token.key_version
             FROM api_access_tokens token
             JOIN api_service_accounts account ON account.id = token.service_account_id
             WHERE token.revoked_at IS NULL AND token.expires_at > ?
               AND (token.rotation_grace_expires_at IS NULL OR token.rotation_grace_expires_at > ?)
               AND account.status <> 'revoked' AND account.institution_id = ?",
        );
        $versions->execute([$now, $now, $institution->id()]);
        $referencedVersions = array_map('strval', $versions->fetchAll(PDO::FETCH_COLUMN));
        $keyring = ApiKeyring::environmentStatus();
        $missingVersions = array_diff($referencedVersions, $keyring['versions']);
        $scopeCatalogReady = false;
        try {
            (new ApiScopePolicy($pdo))->assertProvisionable(ApiScopePolicy::supportedScopes());
            $scopeCatalogReady = true;
        } catch (ApiAuthorizationUnavailable) {
            $scopeCatalogReady = false;
        } catch (\App\Core\Http\UserVisibleException) {
            $scopeCatalogReady = false;
        }
        $status = 'ok';
        if ($missingVersions !== [] || ($enabled && (!$keyring['present'] || !$scopeCatalogReady))) {
            $status = 'fail';
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
        $denied = $pdo->prepare(
            "SELECT COUNT(*) FROM api_request_audit_events
             WHERE institution_id = ? AND created_at >= ?
               AND outcome IN ('denied', 'rate_limited')",
        );
        $denied->execute([$institution->id(), $cutoff]);
        $bucketCount = $pdo->prepare(
            'SELECT COUNT(*) FROM api_rate_limit_buckets WHERE institution_id = ?',
        );
        $bucketCount->execute([$institution->id()]);
        return [
            'status' => $status,
            'enabled' => $enabled,
            'service_accounts' => array_sum($states),
            'states' => $states,
            'usable_tokens' => (int) ($tokens['usable_count'] ?? 0),
            'revoked_tokens' => (int) ($tokens['revoked_count'] ?? 0),
            'expired_tokens' => (int) ($tokens['expired_count'] ?? 0),
            'keyring_present' => (bool) $keyring['present'],
            'key_references_ready' => $missingVersions === [],
            'missing_key_versions' => count($missingVersions),
            'scope_catalog_ready' => $scopeCatalogReady,
            'rate_limit_buckets' => (int) $bucketCount->fetchColumn(),
            'denied_requests_24h' => (int) $denied->fetchColumn(),
            'message' => $this->message(
                $enabled,
                (bool) $keyring['present'],
                count($missingVersions),
                $scopeCatalogReady,
                (int) ($tokens['usable_count'] ?? 0),
            ),
        ];
    }

    private function emptySnapshot(): array
    {
        return [
            'status' => 'ok',
            'enabled' => false,
            'service_accounts' => 0,
            'states' => ['enabled' => 0, 'disabled' => 0, 'revoked' => 0],
            'usable_tokens' => 0,
            'revoked_tokens' => 0,
            'expired_tokens' => 0,
            'keyring_present' => ApiKeyring::environmentStatus()['present'],
            'key_references_ready' => true,
            'missing_key_versions' => 0,
            'scope_catalog_ready' => false,
            'rate_limit_buckets' => 0,
            'denied_requests_24h' => 0,
            'message' => 'The API identity foundation is not installed.',
        ];
    }

    private function message(
        bool $enabled,
        bool $keyringPresent,
        int $missingVersions,
        bool $scopeCatalogReady,
        int $usableTokens,
    ): string {
        if ($missingVersions > 0) {
            return $missingVersions . ' referenced API key version(s) are unavailable.';
        }
        if ($enabled && !$keyringPresent) {
            return 'The API is enabled but its external keyring is unavailable.';
        }
        if ($enabled && !$scopeCatalogReady) {
            return 'The API is enabled but its Placement scope capability state is unavailable.';
        }
        if (!$enabled) {
            return 'The institution-local API is disabled; no public API resource is exposed.';
        }
        return 'The API identity controls are ready with ' . $usableTokens . ' usable token(s).';
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $table) !== 1) {
            throw new RuntimeException('API health table identifier is invalid.');
        }
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $query = $pdo->prepare('SELECT type FROM sqlite_master WHERE name = ? ORDER BY type');
            $query->execute([$table]);
            $objects = $query->fetchAll(PDO::FETCH_COLUMN);
            if ($objects === []) {
                return false;
            }
            if ($objects !== ['table']) {
                throw new RuntimeException('API health storage has an unexpected SQLite object type.');
            }
            return true;
        }
        if ($driver === 'pgsql') {
            $schema = $pdo->query('SELECT current_schema()')->fetchColumn();
            if (!is_string($schema) || $schema === '') {
                throw new RuntimeException('API health PostgreSQL schema is unavailable.');
            }
            $query = $pdo->prepare(
                'SELECT class.relkind
                 FROM pg_catalog.pg_class class
                 JOIN pg_catalog.pg_namespace namespace ON namespace.oid = class.relnamespace
                 WHERE namespace.nspname = ? AND class.relname = ?',
            );
            $query->execute([$schema, $table]);
            $objects = $query->fetchAll(PDO::FETCH_COLUMN);
            if ($objects === []) {
                return false;
            }
            if (count($objects) !== 1 || !in_array((string) $objects[0], ['r', 'p'], true)) {
                throw new RuntimeException('API health storage has an unexpected PostgreSQL object type.');
            }
            return true;
        }
        throw new RuntimeException('API health storage driver is unsupported.');
    }
}
