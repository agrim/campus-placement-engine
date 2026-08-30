<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Http\UserVisibleException;
use App\Core\Persistence\WriteTransaction;
use App\Support\Auth;
use App\Support\Database;
use PDO;

/** Audited operator recovery for one exact dead-lettered bundled observer. */
final class InternalEventDeliveryReplayService
{
    public function __construct(private readonly ?PDO $connection = null)
    {
    }

    /** @return array{status: 'replayed'|'already-replayed', delivery_id: int} */
    public function replay(string $eventPublicId, string $subscriptionId, int $actorUserId): array
    {
        if (preg_match('/^event_[a-f0-9]{32}$/D', $eventPublicId) !== 1
            || !InternalEventSubscription::isValidId($subscriptionId)) {
            throw new UserVisibleException(
                'INTERNAL_EVENT_REPLAY_IDENTITY_INVALID',
                'Use the exact event public ID and stable internal subscription ID.',
            );
        }
        $pdo = $this->connection ?? Database::connection();
        return WriteTransaction::run($pdo, function () use (
            $pdo,
            $eventPublicId,
            $subscriptionId,
            $actorUserId,
        ): array {
            ReplayOperatorAuthorization::requireActiveAdministrator(
                $pdo,
                $actorUserId,
                ReplayOperatorAuthorization::INTERNAL_DELIVERY,
            );

            $locking = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
                ? ' FOR UPDATE'
                : '';
            $delivery = $pdo->prepare(
                'SELECT d.id, d.status, d.attempt_count, d.locked_at, d.lock_token,
                        d.replayed_at, d.replayed_by_user_id
                 FROM domain_event_deliveries d
                 JOIN domain_event_outbox e ON e.id = d.event_id
                 WHERE e.public_id = ? AND d.subscription_id = ?' . $locking,
            );
            $delivery->execute([$eventPublicId, $subscriptionId]);
            $rows = $delivery->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                throw new UserVisibleException(
                    'INTERNAL_EVENT_REPLAY_NOT_FOUND',
                    'No internal observer delivery matches that exact identity.',
                );
            }
            $row = $rows[0];
            $deliveryId = (int) $row['id'];
            if ((string) $row['status'] === 'pending'
                && (string) ($row['replayed_at'] ?? '') !== ''
                && (int) ($row['replayed_by_user_id'] ?? 0) === $actorUserId
                && (int) $row['attempt_count'] === 0
                && ($row['locked_at'] ?? null) === null
                && ($row['lock_token'] ?? null) === null) {
                return ['status' => 'already-replayed', 'delivery_id' => $deliveryId];
            }
            if ((string) $row['status'] !== 'dead_lettered') {
                throw new UserVisibleException(
                    'INTERNAL_EVENT_REPLAY_NOT_ELIGIBLE',
                    'Only a dead-lettered internal observer delivery can be replayed.',
                );
            }

            $now = cpe_now();
            $reset = $pdo->prepare(
                "UPDATE domain_event_deliveries
                 SET status = 'pending', attempt_count = 0, available_at = ?,
                     locked_at = NULL, lock_token = NULL, delivered_at = NULL,
                     skipped_at = NULL, failed_at = NULL, last_error = '',
                     replayed_at = ?, replayed_by_user_id = ?, updated_at = ?
                 WHERE id = ? AND status = 'dead_lettered'",
            );
            $reset->execute([$now, $now, $actorUserId, $now, $deliveryId]);
            if ($reset->rowCount() !== 1) {
                throw new UserVisibleException(
                    'INTERNAL_EVENT_REPLAY_NOT_ELIGIBLE',
                    'Only a dead-lettered internal observer delivery can be replayed.',
                );
            }
            Auth::audit(
                $actorUserId,
                'internal_event_delivery.replay',
                'internal_event_delivery',
                $deliveryId,
                '',
                $pdo,
            );
            return ['status' => 'replayed', 'delivery_id' => $deliveryId];
        });
    }
}
