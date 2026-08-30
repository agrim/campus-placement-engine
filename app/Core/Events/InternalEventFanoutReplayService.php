<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Http\UserVisibleException;
use App\Core\Persistence\WriteTransaction;
use App\Support\Auth;
use App\Support\Database;
use PDO;

/** Audited operator recovery for one exact dead-lettered module declaration fanout. */
final class InternalEventFanoutReplayService
{
    public function __construct(private readonly ?PDO $connection = null)
    {
    }

    /** @return array{status: 'replayed'|'already-replayed', fanout_id: int} */
    public function replay(string $eventPublicId, string $moduleKey, int $actorUserId): array
    {
        if (preg_match('/^event_[a-f0-9]{32}$/D', $eventPublicId) !== 1
            || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $moduleKey) !== 1) {
            throw new UserVisibleException(
                'INTERNAL_EVENT_FANOUT_REPLAY_IDENTITY_INVALID',
                'Use the exact event public ID and bundled module key.',
            );
        }
        $pdo = $this->connection ?? Database::connection();
        return WriteTransaction::run($pdo, function () use ($pdo, $eventPublicId, $moduleKey, $actorUserId): array {
            ReplayOperatorAuthorization::requireActiveAdministrator(
                $pdo,
                $actorUserId,
                ReplayOperatorAuthorization::INTERNAL_FANOUT,
            );

            $locking = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
                ? ' FOR UPDATE'
                : '';
            $fanout = $pdo->prepare(
                'SELECT f.id, f.status, f.attempt_count, f.locked_at, f.lock_token,
                        f.replayed_at, f.replayed_by_user_id
                 FROM domain_event_module_fanout f
                 JOIN domain_event_outbox e ON e.id = f.event_id
                 WHERE e.public_id = ? AND f.module_key = ?' . $locking,
            );
            $fanout->execute([$eventPublicId, $moduleKey]);
            $rows = $fanout->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                throw new UserVisibleException(
                    'INTERNAL_EVENT_FANOUT_REPLAY_NOT_FOUND',
                    'No internal observer fanout matches that exact identity.',
                );
            }
            $row = $rows[0];
            $fanoutId = (int) $row['id'];
            if ((string) $row['status'] === 'pending'
                && (string) ($row['replayed_at'] ?? '') !== ''
                && (int) ($row['replayed_by_user_id'] ?? 0) === $actorUserId
                && (int) $row['attempt_count'] === 0
                && ($row['locked_at'] ?? null) === null
                && ($row['lock_token'] ?? null) === null) {
                return ['status' => 'already-replayed', 'fanout_id' => $fanoutId];
            }
            if ((string) $row['status'] !== 'dead_lettered') {
                throw new UserVisibleException(
                    'INTERNAL_EVENT_FANOUT_REPLAY_NOT_ELIGIBLE',
                    'Only a dead-lettered internal observer fanout can be replayed.',
                );
            }

            $now = cpe_now();
            $reset = $pdo->prepare(
                "UPDATE domain_event_module_fanout
                 SET status = 'pending', attempt_count = 0, available_at = ?,
                     locked_at = NULL, lock_token = NULL, expanded_at = NULL,
                     failed_at = NULL, last_error = '', replayed_at = ?,
                     replayed_by_user_id = ?, updated_at = ?
                 WHERE id = ? AND status = 'dead_lettered'",
            );
            $reset->execute([$now, $now, $actorUserId, $now, $fanoutId]);
            if ($reset->rowCount() !== 1) {
                throw new UserVisibleException(
                    'INTERNAL_EVENT_FANOUT_REPLAY_NOT_ELIGIBLE',
                    'Only a dead-lettered internal observer fanout can be replayed.',
                );
            }
            Auth::audit(
                $actorUserId,
                'internal_event_fanout.replay',
                'internal_event_fanout',
                $fanoutId,
                '',
                $pdo,
            );
            return ['status' => 'replayed', 'fanout_id' => $fanoutId];
        });
    }
}
