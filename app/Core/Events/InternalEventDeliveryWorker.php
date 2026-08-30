<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleRegistry;
use App\Support\Database;
use App\Support\IncidentReporter;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Delivers source-bundled observers after the source transaction has committed.
 *
 * Claims are durable and callbacks run after the claim transaction closes. All
 * terminal and retry updates are fenced by the claim's unguessable lease token.
 *
 * @internal Source-bundled observer runtime, not a public integration surface.
 */
final class InternalEventDeliveryWorker
{
    private PDO $pdo;
    private ?InternalEventSubscriberRegistry $subscribers;
    private ?ModuleManager $modules;
    private ?ModuleRegistry $moduleRegistry;

    public function __construct(
        ?PDO $pdo = null,
        ?InternalEventSubscriberRegistry $subscribers = null,
        ?ModuleManager $modules = null,
        ?ModuleRegistry $moduleRegistry = null,
    ) {
        $this->pdo = $pdo ?? Database::connection();
        $this->subscribers = $subscribers;
        if ($subscribers !== null) {
            if ($modules !== null || $moduleRegistry !== null) {
                throw new RuntimeException('Injected internal subscribers cannot be combined with module catalogs.');
            }
            $this->modules = null;
            $this->moduleRegistry = null;
            return;
        }
        if (($modules === null) !== ($moduleRegistry === null)) {
            throw new RuntimeException('Internal delivery requires both module catalogs.');
        }
        if ($modules !== null && $moduleRegistry !== null) {
            $this->modules = $modules;
            $this->moduleRegistry = $moduleRegistry;
            return;
        }
        $context = cpe_context();
        $this->modules = $context->moduleManager();
        $this->moduleRegistry = $context->modules();
    }

    public function work(int $limit = 100): array
    {
        $rows = $this->claim(max(1, min(1000, $limit)));
        $result = [
            'claimed' => count($rows),
            'delivered' => 0,
            'skipped' => 0,
            'failed' => 0,
            'retrying' => 0,
            'dead_lettered' => 0,
            'outcome_unknown' => 0,
            'claim_lost' => 0,
            'rows' => [],
        ];

        foreach ($rows as $row) {
            try {
                $event = $this->reconstructEvent($row);
                $subscription = $this->subscriptionForDelivery($row, $event);
                if ($subscription === null) {
                    try {
                        $skipped = $this->markSkipped(
                            (int) $row['delivery_id'],
                            (string) $row['lock_token'],
                        );
                    } catch (Throwable $skipFailure) {
                        $unknownReference = $this->report(
                            $skipFailure,
                            'CPE_INTERNAL_EVENT_SKIP_STATE_UNKNOWN',
                            'skip_state',
                        );
                        $result['outcome_unknown']++;
                        $result['rows'][] = $this->resultRow($row, 'outcome-unknown', $unknownReference);
                        continue;
                    }
                    if (!$skipped) {
                        $claimReference = $this->report(
                            new RuntimeException('Internal-event skip claim was lost.'),
                            'CPE_INTERNAL_EVENT_SKIP_CLAIM_LOST',
                            'skip_state',
                        );
                        $result['outcome_unknown']++;
                        $result['claim_lost']++;
                        $result['rows'][] = $this->resultRow($row, 'claim-lost', $claimReference);
                        continue;
                    }
                    $result['skipped']++;
                    $result['rows'][] = $this->resultRow($row, 'skipped', '');
                    continue;
                }
                $subscription->invoke($event);
            } catch (Throwable $failure) {
                $failureReference = $this->report(
                    $failure,
                    'CPE_INTERNAL_EVENT_OBSERVER_FAILED',
                    'callback',
                );
                try {
                    $failureState = $this->markFailed($row, $failureReference);
                } catch (Throwable $stateFailure) {
                    $unknownReference = $this->report(
                        $stateFailure,
                        'CPE_INTERNAL_EVENT_FAILURE_STATE_UNKNOWN',
                        'failure_state',
                    );
                    $result['outcome_unknown']++;
                    $result['rows'][] = $this->resultRow($row, 'outcome-unknown', $unknownReference);
                    continue;
                }
                if (!$failureState['updated']) {
                    $claimReference = $this->report(
                        new RuntimeException('Internal-event failure state claim was lost.'),
                        'CPE_INTERNAL_EVENT_FAILURE_CLAIM_LOST',
                        'failure_state',
                    );
                    $result['outcome_unknown']++;
                    $result['claim_lost']++;
                    $result['rows'][] = $this->resultRow($row, 'claim-lost', $claimReference);
                    continue;
                }
                $result['failed']++;
                $result[$failureState['dead_lettered'] ? 'dead_lettered' : 'retrying']++;
                $result['rows'][] = $this->resultRow(
                    $row,
                    $failureState['dead_lettered'] ? 'dead-lettered' : 'retrying',
                    $failureReference,
                );
                continue;
            }

            try {
                $acknowledged = $this->markDelivered(
                    (int) $row['delivery_id'],
                    (string) $row['lock_token'],
                );
            } catch (Throwable $ackFailure) {
                $unknownReference = $this->report(
                    $ackFailure,
                    'CPE_INTERNAL_EVENT_ACK_STATE_UNKNOWN',
                    'acknowledgment',
                );
                $result['outcome_unknown']++;
                $result['rows'][] = $this->resultRow($row, 'outcome-unknown', $unknownReference);
                continue;
            }
            if (!$acknowledged) {
                $claimReference = $this->report(
                    new RuntimeException('Internal-event acknowledgement claim was lost.'),
                    'CPE_INTERNAL_EVENT_ACK_CLAIM_LOST',
                    'acknowledgment',
                );
                $result['outcome_unknown']++;
                $result['claim_lost']++;
                $result['rows'][] = $this->resultRow($row, 'claim-lost', $claimReference);
                continue;
            }
            $result['delivered']++;
            $result['rows'][] = $this->resultRow($row, 'delivered', '');
        }

        return $result;
    }

    private function claim(int $limit): array
    {
        $now = cpe_now();
        $stale = gmdate('Y-m-d H:i:s', time() - $this->lockSeconds());
        $token = 'observer_' . bin2hex(random_bytes(16));
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sqliteImmediate = $driver === 'sqlite';
        $started = false;
        $ids = [];

        try {
            if ($sqliteImmediate) {
                $this->pdo->exec('BEGIN IMMEDIATE');
            } else {
                $this->pdo->beginTransaction();
            }
            $started = true;
            $locking = $driver === 'pgsql' ? ' FOR UPDATE SKIP LOCKED' : '';
            $select = $this->pdo->prepare(
                "SELECT id FROM domain_event_deliveries
                 WHERE status IN ('pending', 'retrying') AND available_at <= ?
                   AND (locked_at IS NULL OR locked_at < ?)
                 ORDER BY id LIMIT {$limit}{$locking}",
            );
            $select->execute([$now, $stale]);
            $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $this->pdo->prepare(
                    "UPDATE domain_event_deliveries
                     SET locked_at = ?, lock_token = ?, attempt_count = attempt_count + 1, updated_at = ?
                     WHERE id IN ({$placeholders}) AND status IN ('pending', 'retrying')
                       AND (locked_at IS NULL OR locked_at < ?)",
                );
                $update->execute([$now, $token, $now, ...$ids, $stale]);
            }
            if ($sqliteImmediate) {
                $this->pdo->exec('COMMIT');
            } else {
                $this->pdo->commit();
            }
            $started = false;
        } catch (Throwable $failure) {
            if ($started) {
                try {
                    if ($sqliteImmediate) {
                        $this->pdo->exec('ROLLBACK');
                    } elseif ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                } catch (Throwable) {
                    Database::reset();
                }
            }
            throw $failure;
        }

        if ($ids === []) {
            return [];
        }
        $claimed = $this->pdo->prepare(
            'SELECT d.id AS delivery_id, d.subscription_id, d.module_key AS delivery_module_key,
                    d.status, d.attempt_count,
                    d.available_at, d.locked_at, d.lock_token,
                    e.public_id AS event_public_id, e.event_name, e.aggregate_type,
                    e.aggregate_public_id, e.module_key, e.payload_json, e.occurred_at
             FROM domain_event_deliveries d
             JOIN domain_event_outbox e ON e.id = d.event_id
             WHERE d.lock_token = ?
             ORDER BY d.id',
        );
        $claimed->execute([$token]);
        return $claimed->fetchAll();
    }

    private function subscriptionForDelivery(array $row, DomainEvent $event): ?InternalEventSubscription
    {
        $subscriptionId = (string) ($row['subscription_id'] ?? '');
        $moduleKey = (string) ($row['delivery_module_key'] ?? '');
        if (!InternalEventSubscription::isValidId($subscriptionId)
            || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $moduleKey) !== 1) {
            throw new RuntimeException('Persisted internal event subscription identity is invalid.');
        }

        $subscribers = $this->subscribers;
        if ($subscribers === null) {
            if ($this->modules === null || $this->moduleRegistry === null) {
                throw new RuntimeException('Internal event module registry is unavailable.');
            }
            $state = $this->moduleRegistry->all()[$moduleKey] ?? null;
            if (!is_array($state) || ($state['installed'] ?? false) !== true) {
                throw new RuntimeException('Internal event subscription references an unknown module.');
            }
            if (($state['enabled'] ?? false) !== true) {
                return null;
            }
            $subscribers = InternalEventSubscriberRegistry::fromModuleInstances(
                $this->modules->modulesForKeys([$moduleKey]),
            );
            if ($subscribers->eventNamesForModule($moduleKey)
                !== $this->modules->internalObserverEventsForKey($moduleKey)) {
                throw new RuntimeException(
                    'Bundled module observer declarations do not match immutable event eligibility.',
                );
            }
        }

        $subscription = $subscribers->find($subscriptionId);
        if ($subscription === null
            || $subscription->moduleKey() !== $moduleKey
            || $subscription->eventName() !== $event->name) {
            throw new RuntimeException('Internal event subscription is unavailable or does not match the delivery.');
        }
        return $subscription;
    }

    private function reconstructEvent(array $row): DomainEvent
    {
        $publicId = (string) ($row['event_public_id'] ?? '');
        $eventName = (string) ($row['event_name'] ?? '');
        $aggregateType = (string) ($row['aggregate_type'] ?? '');
        $aggregatePublicId = (string) ($row['aggregate_public_id'] ?? '');
        $moduleKey = (string) ($row['module_key'] ?? '');
        $occurredAt = (string) ($row['occurred_at'] ?? '');
        if (preg_match('/^event_[a-f0-9]{32}$/D', $publicId) !== 1
            || preg_match('/^[a-z][a-z0-9_.]{2,127}$/D', $eventName) !== 1
            || $aggregateType === '' || strlen($aggregateType) > 127
            || $aggregatePublicId === '' || strlen($aggregatePublicId) > 191
            || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $moduleKey) !== 1) {
            throw new RuntimeException('Persisted domain-event identity is invalid.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $occurredAt, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $occurredAt) {
            throw new RuntimeException('Persisted domain-event occurrence time is invalid.');
        }
        try {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new RuntimeException('Persisted domain-event payload is invalid JSON.', 0, $failure);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('Persisted domain-event payload must be an object or array.');
        }
        return new DomainEvent(
            $eventName,
            $aggregateType,
            $aggregatePublicId,
            $moduleKey,
            $payload,
            $occurredAt,
        );
    }

    private function markDelivered(int $deliveryId, string $token): bool
    {
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            "UPDATE domain_event_deliveries
             SET status = 'delivered', delivered_at = ?, failed_at = NULL, last_error = '',
                 skipped_at = NULL, locked_at = NULL, lock_token = NULL, updated_at = ?
             WHERE id = ? AND lock_token = ? AND status IN ('pending', 'retrying')",
        );
        $stmt->execute([$now, $now, $deliveryId, $token]);
        return $stmt->rowCount() === 1;
    }

    private function markSkipped(int $deliveryId, string $token): bool
    {
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            "UPDATE domain_event_deliveries
             SET status = 'skipped', skipped_at = ?, delivered_at = NULL, failed_at = NULL,
                 last_error = '', locked_at = NULL, lock_token = NULL, updated_at = ?
             WHERE id = ? AND lock_token = ? AND status IN ('pending', 'retrying')",
        );
        $stmt->execute([$now, $now, $deliveryId, $token]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{updated: bool, dead_lettered: bool} */
    private function markFailed(array $row, string $failureReference): array
    {
        $attempts = (int) $row['attempt_count'];
        $deadLettered = $attempts >= $this->maxAttempts();
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            "UPDATE domain_event_deliveries
             SET status = ?, last_error = ?, available_at = ?, failed_at = ?,
                 skipped_at = NULL, locked_at = NULL, lock_token = NULL, updated_at = ?
             WHERE id = ? AND lock_token = ? AND status IN ('pending', 'retrying')",
        );
        $stmt->execute([
            $deadLettered ? 'dead_lettered' : 'retrying',
            $failureReference,
            $deadLettered ? $now : $this->retryAt($attempts),
            $deadLettered ? $now : null,
            $now,
            (int) $row['delivery_id'],
            (string) $row['lock_token'],
        ]);
        return ['updated' => $stmt->rowCount() === 1, 'dead_lettered' => $deadLettered];
    }

    private function retryAt(int $attempts): string
    {
        $base = min(3600, 30 * (2 ** min(7, max(0, $attempts - 1))));
        try {
            $jitter = random_int(0, max(1, intdiv($base, 4)));
        } catch (Throwable) {
            $jitter = 0;
        }
        return gmdate('Y-m-d H:i:s', time() + min(3600, $base + $jitter));
    }

    private function lockSeconds(): int
    {
        return max(30, min(3600, (int) (getenv('CPE_DOMAIN_EVENT_LOCK_SECONDS') ?: 300)));
    }

    private function maxAttempts(): int
    {
        return max(1, min(100, (int) (getenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS') ?: 10)));
    }

    private function report(Throwable $failure, string $code, string $phase): string
    {
        $incidentId = IncidentReporter::report(
            $failure,
            $code,
            'worker',
            ['operation' => 'internal_event.observer', 'phase' => $phase],
        );
        return IncidentReporter::reference($code, $incidentId);
    }

    private function resultRow(array $row, string $status, string $error): array
    {
        $publicId = (string) ($row['event_public_id'] ?? '');
        if (preg_match('/^event_[a-f0-9]{32}$/D', $publicId) !== 1) {
            $publicId = 'event_unavailable';
        }
        $subscriptionId = (string) ($row['subscription_id'] ?? '');
        if (!InternalEventSubscription::isValidId($subscriptionId)) {
            $subscriptionId = 'internal.unavailable.observer.v1';
        }
        return [
            'public_id' => $publicId,
            'subscription_id' => $subscriptionId,
            'status' => $status,
            'error' => $error,
        ];
    }
}
