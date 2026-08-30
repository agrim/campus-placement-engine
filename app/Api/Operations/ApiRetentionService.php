<?php

declare(strict_types=1);

namespace App\Api\Operations;

use App\Api\Commands\ApiCommandIdempotencyStore;
use App\Api\Security\ApiManagementAuthorization;
use App\Core\Http\UserVisibleException;
use App\Core\Institution\InstitutionRepository;
use App\Core\Persistence\WriteTransaction;
use App\Support\Auth;
use App\Support\Database;
use PDO;

final class ApiRetentionService
{
    public function __construct(private readonly ?PDO $connection = null)
    {
    }

    /** @return array{rate_limit_buckets: int, request_audit_events: int, command_idempotency_keys: int} */
    public function prune(int $actorUserId, int $limit = 1000): array
    {
        if ($limit < 1 || $limit > 5000) {
            throw new UserVisibleException('API_PRUNE_LIMIT_INVALID', 'API prune limit must be between 1 and 5000.');
        }
        $pdo = $this->connection ?? Database::connection();
        return WriteTransaction::run($pdo, function () use ($pdo, $actorUserId, $limit): array {
            ApiManagementAuthorization::requireActor($pdo, $actorUserId);
            $institutionId = (new InstitutionRepository($pdo))->current()->id();
            $now = cpe_now();
            $bucketDelete = $pdo->prepare(
                'DELETE FROM api_rate_limit_buckets WHERE id IN (
                    SELECT id FROM api_rate_limit_buckets
                    WHERE institution_id = ? AND expires_at <= ? ORDER BY id LIMIT ?
                 )',
            );
            $bucketDelete->bindValue(1, $institutionId, PDO::PARAM_INT);
            $bucketDelete->bindValue(2, $now, PDO::PARAM_STR);
            $bucketDelete->bindValue(3, $limit, PDO::PARAM_INT);
            $bucketDelete->execute();
            $auditDelete = $pdo->prepare(
                'DELETE FROM api_request_audit_events WHERE id IN (
                    SELECT id FROM api_request_audit_events
                    WHERE institution_id = ? AND retention_until <= ? ORDER BY id LIMIT ?
                 )',
            );
            $auditDelete->bindValue(1, $institutionId, PDO::PARAM_INT);
            $auditDelete->bindValue(2, $now, PDO::PARAM_STR);
            $auditDelete->bindValue(3, $limit, PDO::PARAM_INT);
            $auditDelete->execute();
            $commandKeys = (new ApiCommandIdempotencyStore($pdo))->pruneExpiredCurrentInstitution($limit);
            Auth::audit($actorUserId, 'api.retention.prune', 'system', null, '', $pdo);
            return [
                'rate_limit_buckets' => $bucketDelete->rowCount(),
                'request_audit_events' => $auditDelete->rowCount(),
                'command_idempotency_keys' => $commandKeys,
            ];
        });
    }
}
