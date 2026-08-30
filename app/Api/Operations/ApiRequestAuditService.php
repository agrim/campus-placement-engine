<?php

declare(strict_types=1);

namespace App\Api\Operations;

use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiPrincipal;
use App\Api\Security\ApiScopePolicy;
use App\Core\Institution\InstitutionRepository;
use App\Support\Database;
use PDO;
use RuntimeException;

/** Redacted request audit: no token, body, query, path, IP, or user agent is stored. */
final class ApiRequestAuditService
{
    public const RETENTION_DAYS = 90;

    private const OUTCOMES = ['authenticated', 'authorized', 'denied', 'rate_limited', 'succeeded', 'failed'];

    public function __construct(
        private readonly ?PDO $connection = null,
        private readonly ?ApiKeyring $configuredKeyring = null,
    ) {
    }

    public function record(
        ?ApiPrincipal $principal,
        string $routeClass,
        string $requiredScope,
        string $outcome,
        int $statusCode,
        string $detailCode = '',
        string $source = '',
        ?string $requestId = null,
    ): string {
        if (preg_match('/^[a-z0-9_.-]{1,80}$/D', $routeClass) !== 1
            || ($requiredScope !== '' && !in_array($requiredScope, ApiScopePolicy::supportedScopes(), true))
            || !in_array($outcome, self::OUTCOMES, true)
            || $statusCode < 100 || $statusCode > 599
            || preg_match('/^[A-Z0-9_]{0,80}$/D', $detailCode) !== 1) {
            throw new RuntimeException('API request audit classification is invalid.');
        }
        $requestId ??= 'req_' . bin2hex(random_bytes(16));
        if (preg_match('/^req_[a-f0-9]{32}$/D', $requestId) !== 1) {
            throw new RuntimeException('API request audit request ID is invalid.');
        }
        $pdo = $this->connection ?? Database::connection();
        $institution = (new InstitutionRepository($pdo))->current();
        if ($principal !== null
            && ($principal->institutionId() !== $institution->id()
                || !hash_equals($principal->institutionPublicId(), $institution->publicId()))) {
            throw new RuntimeException('API request audit principal belongs to a different institution.');
        }
        $fingerprint = '';
        if (trim($source) !== '') {
            $keyring = $this->configuredKeyring ?? ApiKeyring::fromEnvironment();
            $fingerprint = $keyring->sourceFingerprint(substr($source, 0, 512), $institution->publicId());
        }
        $now = cpe_now();
        $publicId = 'apiaud_' . bin2hex(random_bytes(16));
        $retention = gmdate('Y-m-d H:i:s', time() + self::RETENTION_DAYS * 86400);
        $insert = $pdo->prepare(
            'INSERT INTO api_request_audit_events
             (public_id, institution_id, service_account_id, token_id, request_id,
              route_class, required_scope, outcome, status_code, detail_code,
              source_fingerprint, retention_until, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $insert->execute([
            $publicId,
            $institution->id(),
            $principal?->serviceAccountId(),
            $principal?->tokenId(),
            $requestId,
            $routeClass,
            $requiredScope,
            $outcome,
            $statusCode,
            $detailCode,
            $fingerprint,
            $retention,
            $now,
        ]);
        return $publicId;
    }
}
