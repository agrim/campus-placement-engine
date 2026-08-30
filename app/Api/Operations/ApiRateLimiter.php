<?php

declare(strict_types=1);

namespace App\Api\Operations;

use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiPrincipal;
use App\Core\Institution\InstitutionRepository;
use App\Core\Persistence\WriteTransaction;
use App\Support\Database;
use Closure;
use PDO;
use RuntimeException;

/** Transactional institution/token/source rate-limit buckets for future API routes. */
final class ApiRateLimiter
{
    public const PRE_AUTH_ROUTE_CLASS = 'api.v1.preauth';

    public const PRE_AUTH_LIMITS = [
        'institution' => 1200,
        'source' => 300,
    ];

    private const DEFAULT_LIMITS = [
        'institution' => 1200,
        'token' => 600,
        'source' => 300,
    ];

    public function __construct(
        private readonly ?PDO $connection = null,
        private readonly ?ApiKeyring $configuredKeyring = null,
    ) {
    }

    /**
     * @param array{institution?: int, token?: int, source?: int} $limits
     * @return array{allowed: bool, limited_dimension: string, window_started_at: string, retry_after_seconds: int, audit_threshold_crossing: bool}
     */
    public function consume(
        ApiPrincipal $principal,
        string $routeClass,
        string $source,
        int $windowSeconds = 60,
        array $limits = [],
    ): array {
        self::assertBoundary($routeClass, $windowSeconds);
        $source = self::normalizeSource($source);
        $normalizedLimits = self::normalizeLimits(self::DEFAULT_LIMITS, $limits);
        $pdo = $this->connection ?? Database::connection();
        $institution = (new InstitutionRepository($pdo))->current();
        if ($principal->institutionId() !== $institution->id()
            || !hash_equals($principal->institutionPublicId(), $institution->publicId())) {
            throw new RuntimeException('API rate-limit principal belongs to a different institution.');
        }
        $keyring = $this->configuredKeyring ?? ApiKeyring::fromEnvironment();
        $dimensions = [
            'institution' => [
                'token_id' => null,
                'key' => $keyring->sourceFingerprint('institution', $principal->institutionPublicId()),
            ],
            'token' => [
                'token_id' => $principal->tokenId(),
                'key' => $keyring->sourceFingerprint('token|' . $principal->tokenLookupId(), $principal->institutionPublicId()),
            ],
            'source' => [
                'token_id' => null,
                'key' => $keyring->sourceFingerprint('source|' . $source, $principal->institutionPublicId()),
            ],
        ];
        return $this->consumeDimensions(
            $pdo,
            $principal->institutionId(),
            $routeClass,
            $windowSeconds,
            $normalizedLimits,
            $dimensions,
            null,
        );
    }

    /**
     * Institution-wide and keyed direct-peer gate applied before API authentication.
     * The first over-limit request writes one aggregate audit and marks the
     * sentinel in the same transaction; later requests are suppressed.
     *
     * @param array{institution?: int, source?: int} $limits
     * @return array{allowed: bool, limited_dimension: string, window_started_at: string, retry_after_seconds: int, audit_threshold_crossing: bool}
     */
    public function consumePreAuth(
        string $source,
        string $requestId,
        int $windowSeconds = 60,
        array $limits = [],
    ): array {
        self::assertBoundary(self::PRE_AUTH_ROUTE_CLASS, $windowSeconds);
        if (preg_match('/^req_[a-f0-9]{32}$/D', $requestId) !== 1) {
            throw new RuntimeException('API pre-authentication request ID is invalid.');
        }
        $source = self::normalizeSource($source);
        $normalizedLimits = self::normalizeLimits(self::PRE_AUTH_LIMITS, $limits);
        $pdo = $this->connection ?? Database::connection();
        $institution = (new InstitutionRepository($pdo))->current();
        $keyring = $this->configuredKeyring ?? ApiKeyring::fromEnvironment();
        $dimensions = [
            'institution' => [
                'token_id' => null,
                'key' => $keyring->sourceFingerprint('preauth|institution', $institution->publicId()),
            ],
            'source' => [
                'token_id' => null,
                'key' => $keyring->sourceFingerprint('preauth|source|' . $source, $institution->publicId()),
            ],
        ];
        return $this->consumeDimensions(
            $pdo,
            $institution->id(),
            self::PRE_AUTH_ROUTE_CLASS,
            $windowSeconds,
            $normalizedLimits,
            $dimensions,
            static function (string $dimension) use ($pdo, $keyring, $source, $requestId): void {
                (new ApiRequestAuditService($pdo, $keyring))->record(
                    null,
                    self::PRE_AUTH_ROUTE_CLASS,
                    '',
                    'rate_limited',
                    429,
                    'PREAUTH_RATE_LIMITED_' . strtoupper($dimension),
                    $source,
                    $requestId,
                );
            },
        );
    }

    /**
     * @param array<string, int> $limits
     * @param array<string, array{token_id: ?int, key: string}> $dimensions
     * @return array{allowed: bool, limited_dimension: string, window_started_at: string, retry_after_seconds: int, audit_threshold_crossing: bool}
     */
    private function consumeDimensions(
        PDO $pdo,
        int $institutionId,
        string $routeClass,
        int $windowSeconds,
        array $limits,
        array $dimensions,
        ?Closure $thresholdAudit,
    ): array {
        $epoch = time();
        $windowEpoch = intdiv($epoch, $windowSeconds) * $windowSeconds;
        $windowStartedAt = gmdate('Y-m-d H:i:s', $windowEpoch);
        $expiresAt = gmdate('Y-m-d H:i:s', $windowEpoch + ($windowSeconds * 2));
        $now = cpe_now();
        return WriteTransaction::run($pdo, function () use (
            $pdo,
            $institutionId,
            $routeClass,
            $windowSeconds,
            $limits,
            $dimensions,
            $thresholdAudit,
            $windowStartedAt,
            $expiresAt,
            $now,
            $windowEpoch,
            $epoch,
        ): array {
            $rows = [];
            foreach ($dimensions as $dimension => $metadata) {
                $insert = $pdo->prepare(
                    'INSERT INTO api_rate_limit_buckets
                     (institution_id, token_id, dimension, bucket_key, route_class,
                      window_started_at, window_seconds, request_count, expires_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
                     ON CONFLICT(institution_id, dimension, bucket_key, route_class, window_started_at, window_seconds)
                     DO NOTHING',
                );
                $insert->execute([
                    $institutionId,
                    $metadata['token_id'],
                    $dimension,
                    $metadata['key'],
                    $routeClass,
                    $windowStartedAt,
                    $windowSeconds,
                    $expiresAt,
                    $now,
                    $now,
                ]);
                $created = $insert->rowCount() === 1;
                $suffix = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
                $select = $pdo->prepare(
                    'SELECT id, request_count FROM api_rate_limit_buckets
                     WHERE institution_id = ? AND dimension = ? AND bucket_key = ?
                       AND route_class = ? AND window_started_at = ? AND window_seconds = ?' . $suffix,
                );
                $select->execute([
                    $institutionId,
                    $dimension,
                    $metadata['key'],
                    $routeClass,
                    $windowStartedAt,
                    $windowSeconds,
                ]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    throw new RuntimeException('API rate-limit bucket could not be read after creation.');
                }
                $row = ['id' => (int) $row['id'], 'count' => (int) $row['request_count'], 'created' => $created];
                $rows[$dimension] = $row;
                if (!$row['created'] && $row['count'] >= $limits[$dimension]) {
                    $thresholdCrossing = $thresholdAudit !== null && $row['count'] === $limits[$dimension];
                    if ($thresholdCrossing) {
                        $thresholdAudit($dimension);
                        $mark = $pdo->prepare(
                            'UPDATE api_rate_limit_buckets
                             SET request_count = request_count + 1, updated_at = ? WHERE id = ?',
                        );
                        $mark->execute([$now, $row['id']]);
                    }
                    return [
                        'allowed' => false,
                        'limited_dimension' => $dimension,
                        'window_started_at' => $windowStartedAt,
                        'retry_after_seconds' => max(1, ($windowEpoch + $windowSeconds) - $epoch),
                        'audit_threshold_crossing' => $thresholdCrossing,
                    ];
                }
            }
            $update = $pdo->prepare(
                'UPDATE api_rate_limit_buckets
                 SET request_count = request_count + 1, updated_at = ? WHERE id = ?',
            );
            foreach ($rows as $row) {
                if (!$row['created']) {
                    $update->execute([$now, $row['id']]);
                }
            }
            return [
                'allowed' => true,
                'limited_dimension' => '',
                'window_started_at' => $windowStartedAt,
                'retry_after_seconds' => 0,
                'audit_threshold_crossing' => false,
            ];
        });
    }

    private static function assertBoundary(string $routeClass, int $windowSeconds): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,80}$/D', $routeClass) !== 1) {
            throw new RuntimeException('API rate-limit route class is invalid.');
        }
        if ($windowSeconds < 1 || $windowSeconds > 86400) {
            throw new RuntimeException('API rate-limit window is invalid.');
        }
    }

    private static function normalizeSource(string $source): string
    {
        $source = trim($source);
        return $source === '' || strlen($source) > 512 ? 'unknown' : $source;
    }

    /**
     * @param array<string, int> $defaults
     * @param array<string, int> $overrides
     * @return array<string, int>
     */
    private static function normalizeLimits(array $defaults, array $overrides): array
    {
        foreach ($overrides as $dimension => $limit) {
            if (!array_key_exists($dimension, $defaults)
                || !is_int($limit)
                || $limit < 1
                || $limit > 1000000) {
                throw new RuntimeException('API rate-limit threshold is invalid.');
            }
            $defaults[$dimension] = $limit;
        }
        return $defaults;
    }
}
