<?php

declare(strict_types=1);

namespace App\Api\Operations;

use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiPrincipal;
use App\Core\Institution\InstitutionRepository;
use App\Core\Persistence\WriteTransaction;
use App\Support\Database;
use PDO;
use RuntimeException;

/** Transactional institution/token/source rate-limit buckets for future API routes. */
final class ApiRateLimiter
{
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
     * @return array{allowed: bool, limited_dimension: string, window_started_at: string, retry_after_seconds: int}
     */
    public function consume(
        ApiPrincipal $principal,
        string $routeClass,
        string $source,
        int $windowSeconds = 60,
        array $limits = [],
    ): array {
        if (preg_match('/^[a-z0-9_.-]{1,80}$/D', $routeClass) !== 1) {
            throw new RuntimeException('API rate-limit route class is invalid.');
        }
        if ($windowSeconds < 1 || $windowSeconds > 86400) {
            throw new RuntimeException('API rate-limit window is invalid.');
        }
        $source = trim($source);
        if ($source === '' || strlen($source) > 512) {
            $source = 'unknown';
        }
        $normalizedLimits = self::DEFAULT_LIMITS;
        foreach ($limits as $dimension => $limit) {
            if (!array_key_exists($dimension, $normalizedLimits) || $limit < 1 || $limit > 1000000) {
                throw new RuntimeException('API rate-limit threshold is invalid.');
            }
            $normalizedLimits[$dimension] = $limit;
        }
        $pdo = $this->connection ?? Database::connection();
        $institution = (new InstitutionRepository($pdo))->current();
        if ($principal->institutionId() !== $institution->id()
            || !hash_equals($principal->institutionPublicId(), $institution->publicId())) {
            throw new RuntimeException('API rate-limit principal belongs to a different institution.');
        }
        $keyring = $this->configuredKeyring ?? ApiKeyring::fromEnvironment();
        $epoch = time();
        $windowEpoch = intdiv($epoch, $windowSeconds) * $windowSeconds;
        $windowStartedAt = gmdate('Y-m-d H:i:s', $windowEpoch);
        $expiresAt = gmdate('Y-m-d H:i:s', $windowEpoch + ($windowSeconds * 2));
        $now = cpe_now();
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
        return WriteTransaction::run($pdo, function () use (
            $pdo,
            $principal,
            $routeClass,
            $windowSeconds,
            $normalizedLimits,
            $dimensions,
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
                    $principal->institutionId(),
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
                    $principal->institutionId(),
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
                $rows[$dimension] = ['id' => (int) $row['id'], 'count' => (int) $row['request_count'], 'created' => $created];
            }
            foreach (array_keys($dimensions) as $dimension) {
                $row = $rows[$dimension];
                if (!$row['created'] && $row['count'] >= $normalizedLimits[$dimension]) {
                    return [
                        'allowed' => false,
                        'limited_dimension' => $dimension,
                        'window_started_at' => $windowStartedAt,
                        'retry_after_seconds' => max(1, ($windowEpoch + $windowSeconds) - $epoch),
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
            ];
        });
    }
}
