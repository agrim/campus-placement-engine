<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Http\UserVisibleException;
use App\Core\Persistence\WriteTransaction;
use App\Support\Auth;
use App\Support\Database;
use PDO;

/** Audited, lease-fenced recovery for one exact public dead-letter event. */
final class PublicEventDeadLetterReplayService
{
    public function __construct(private readonly ?PDO $connection = null)
    {
    }

    /** @return array{status: 'replayed'|'already-replayed', event_id: string} */
    public function replay(string $eventPublicId, int $actorUserId): array
    {
        if (preg_match('/^event_[a-f0-9]{32}$/D', $eventPublicId) !== 1) {
            throw new UserVisibleException(
                'PUBLIC_EVENT_REPLAY_IDENTITY_INVALID',
                'Use the exact public event ID.',
            );
        }
        $pdo = $this->connection ?? Database::connection();
        return WriteTransaction::run($pdo, function () use ($pdo, $eventPublicId, $actorUserId): array {
            ReplayOperatorAuthorization::requireActiveAdministrator(
                $pdo,
                $actorUserId,
                ReplayOperatorAuthorization::PUBLIC_EVENT,
            );

            $locking = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
                ? ' FOR UPDATE'
                : '';
            $event = $pdo->prepare(
                'SELECT id, public_id, public_event_type, public_schema_version, occurred_at,
                        public_instance_id, public_aggregate_type, public_aggregate_id,
                        public_aggregate_version, public_payload_json, public_correlation_id,
                        attempts, available_at, processed_at, failed_at, locked_at, lock_token
                 FROM domain_event_outbox
                 WHERE public_id = ? AND public_event_type IS NOT NULL' . $locking,
            );
            $event->execute([$eventPublicId]);
            $rows = $event->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                throw new UserVisibleException(
                    'PUBLIC_EVENT_REPLAY_NOT_FOUND',
                    'No explicit public event matches that exact ID.',
                );
            }
            $row = $rows[0];
            PublicEventEnvelope::fromOutboxRow($row);
            $outboxId = (int) $row['id'];

            $priorReplay = $pdo->prepare(
                "SELECT COUNT(*) FROM audit_logs
                 WHERE action = 'public_event.dead_letter_replay'
                   AND subject_type = 'public_event' AND subject_id = ?",
            );
            $priorReplay->execute([$outboxId]);
            $wasReplayed = (int) $priorReplay->fetchColumn() > 0;
            if (($row['failed_at'] ?? null) === null) {
                if ($wasReplayed) {
                    return ['status' => 'already-replayed', 'event_id' => $eventPublicId];
                }
                throw new UserVisibleException(
                    'PUBLIC_EVENT_REPLAY_NOT_ELIGIBLE',
                    'Only an explicit public event in dead-letter state can be replayed.',
                );
            }
            if (($row['processed_at'] ?? null) !== null) {
                throw new UserVisibleException(
                    'PUBLIC_EVENT_REPLAY_NOT_ELIGIBLE',
                    'Only an unresolved public dead-letter event can be replayed.',
                );
            }
            if (($row['locked_at'] ?? null) !== null || ($row['lock_token'] ?? null) !== null) {
                throw new UserVisibleException(
                    'PUBLIC_EVENT_REPLAY_LEASE_CONFLICT',
                    'The public event retains delivery lease state and cannot be replayed safely.',
                );
            }

            $now = cpe_now();
            $reset = $pdo->prepare(
                "UPDATE domain_event_outbox
                 SET attempts = 0, available_at = ?, processed_at = NULL, failed_at = NULL,
                     locked_at = NULL, lock_token = NULL, delivered_to = '', last_error = ''
                 WHERE id = ? AND public_id = ? AND public_event_type IS NOT NULL
                   AND processed_at IS NULL AND failed_at = ?
                   AND locked_at IS NULL AND lock_token IS NULL",
            );
            $reset->execute([$now, $outboxId, $eventPublicId, (string) $row['failed_at']]);
            if ($reset->rowCount() !== 1) {
                throw new UserVisibleException(
                    'PUBLIC_EVENT_REPLAY_LEASE_CONFLICT',
                    'The public event delivery state changed before replay could be fenced.',
                );
            }
            Auth::audit(
                $actorUserId,
                'public_event.dead_letter_replay',
                'public_event',
                $outboxId,
                '',
                $pdo,
            );
            return ['status' => 'replayed', 'event_id' => $eventPublicId];
        });
    }
}
