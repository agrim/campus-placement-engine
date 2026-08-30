<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Core\Events\ReplayOperatorAuthorization;
use App\Core\Http\UserVisibleException;
use App\Core\Persistence\WriteTransaction;
use App\Support\Auth;
use App\Support\Database;
use PDO;

final class WebhookDeliveryReplayService
{
    public function __construct(private readonly ?PDO $connection = null)
    {
    }

    /** @return array{status: 'replayed'|'already-replayed', delivery_id: string} */
    public function replay(string $deliveryPublicId, int $actorUserId): array
    {
        if (preg_match('/^whdel_[a-f0-9]{32}$/D', $deliveryPublicId) !== 1) {
            throw new UserVisibleException('WEBHOOK_REPLAY_ID_INVALID', 'Use the exact webhook delivery ID.');
        }
        $pdo = $this->connection ?? Database::connection();
        return WriteTransaction::run($pdo, function () use ($pdo, $deliveryPublicId, $actorUserId): array {
            ReplayOperatorAuthorization::requireActiveAdministrator(
                $pdo,
                $actorUserId,
                ReplayOperatorAuthorization::PUBLIC_EVENT,
            );
            $postgres = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
            $subscriptionQuery = $pdo->prepare(
                'SELECT subscription.lifecycle_state
                 FROM webhook_deliveries delivery
                 JOIN webhook_subscriptions subscription ON subscription.id = delivery.subscription_id
                 WHERE delivery.public_id = ?' . ($postgres ? ' FOR UPDATE OF subscription' : ''),
            );
            $subscriptionQuery->execute([$deliveryPublicId]);
            $subscriptionRows = $subscriptionQuery->fetchAll(PDO::FETCH_ASSOC);
            if (count($subscriptionRows) !== 1) {
                throw new UserVisibleException('WEBHOOK_REPLAY_NOT_FOUND', 'No webhook delivery matches that exact ID.');
            }
            $deliveryQuery = $pdo->prepare(
                'SELECT * FROM webhook_deliveries WHERE public_id = ?' . ($postgres ? ' FOR UPDATE' : ''),
            );
            $deliveryQuery->execute([$deliveryPublicId]);
            $deliveryRows = $deliveryQuery->fetchAll(PDO::FETCH_ASSOC);
            if (count($deliveryRows) !== 1) {
                throw new UserVisibleException('WEBHOOK_REPLAY_NOT_FOUND', 'No webhook delivery matches that exact ID.');
            }
            $row = $deliveryRows[0];
            $row['lifecycle_state'] = $subscriptionRows[0]['lifecycle_state'];
            $prior = $pdo->prepare(
                "SELECT COUNT(*) FROM audit_logs
                 WHERE action = 'webhook.delivery.replay'
                   AND subject_type = 'webhook_delivery' AND subject_id = ?",
            );
            $prior->execute([(int) $row['id']]);
            $wasReplayed = (int) $prior->fetchColumn() > 0;
            if ((string) $row['status'] !== 'dead_lettered') {
                if ($wasReplayed) {
                    return ['status' => 'already-replayed', 'delivery_id' => $deliveryPublicId];
                }
                throw new UserVisibleException('WEBHOOK_REPLAY_NOT_ELIGIBLE', 'Only an exact dead-lettered delivery can be replayed.');
            }
            if ((string) $row['last_error_code'] === 'subscription_revoked') {
                throw new UserVisibleException(
                    'WEBHOOK_REPLAY_REVOKED',
                    'A delivery terminated by subscription revocation cannot be replayed.',
                );
            }
            if (!in_array((string) $row['lifecycle_state'], ['active', 'degraded'], true)) {
                throw new UserVisibleException('WEBHOOK_REPLAY_DISABLED', 'Activate the integration before replaying its delivery.');
            }
            if (($row['locked_at'] ?? null) !== null || ($row['lock_token'] ?? null) !== null) {
                throw new UserVisibleException('WEBHOOK_REPLAY_LEASE_CONFLICT', 'This delivery still has an active worker lease.');
            }
            $now = cpe_now();
            $update = $pdo->prepare(
                "UPDATE webhook_deliveries
                 SET status = 'pending', attempt_count = 0, available_at = ?, processed_at = NULL,
                     dead_lettered_at = NULL, locked_at = NULL, lock_token = NULL,
                     lease_generation = lease_generation + 1, last_http_status = NULL,
                     last_error_code = '', last_failure_reference = '', replayed_at = ?,
                     replayed_by_user_id = ?, updated_at = ?
                 WHERE id = ? AND public_id = ? AND status = 'dead_lettered'
                   AND locked_at IS NULL AND lock_token IS NULL",
            );
            $update->execute([$now, $now, $actorUserId, $now, (int) $row['id'], $deliveryPublicId]);
            if ($update->rowCount() !== 1) {
                throw new UserVisibleException('WEBHOOK_REPLAY_LEASE_CONFLICT', 'The delivery changed before replay could be fenced.');
            }
            Auth::audit(
                $actorUserId,
                'webhook.delivery.replay',
                'webhook_delivery',
                (int) $row['id'],
                'Dead-lettered webhook delivery replayed.',
                $pdo,
            );
            return ['status' => 'replayed', 'delivery_id' => $deliveryPublicId];
        });
    }
}
