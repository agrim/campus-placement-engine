<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Modules\ModuleManager;
use App\Core\Persistence\WriteTransaction;
use App\Support\Database;
use App\Support\IncidentReporter;
use Closure;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Expands event/module eligibility into stable observer deliveries only after
 * the source transaction has committed. Each module is leased independently,
 * so declaration faults cannot roll back domain state or poison a neighbor.
 */
final class InternalEventFanoutWorker
{
    private PDO $pdo;
    private ?InternalEventSubscriberRegistry $subscribers;
    private ?ModuleManager $modules;
    private ?Closure $registryResolver;

    public function __construct(
        ?PDO $pdo = null,
        ?InternalEventSubscriberRegistry $subscribers = null,
        ?callable $registryResolver = null,
    ) {
        if ($subscribers !== null && $registryResolver !== null) {
            throw new RuntimeException('Internal fanout accepts one injected registry source.');
        }
        $this->pdo = $pdo ?? Database::connection();
        $this->subscribers = $subscribers;
        $this->registryResolver = $registryResolver === null
            ? null
            : Closure::fromCallable($registryResolver);
        $this->modules = $subscribers === null && $registryResolver === null
            ? cpe_context()->moduleManager()
            : null;
    }

    public function work(int $limit = 100): array
    {
        $rows = $this->claim(max(1, min(1000, $limit)));
        $result = [
            'claimed' => count($rows),
            'expanded' => 0,
            'deliveries_created' => 0,
            'failed' => 0,
            'retrying' => 0,
            'dead_lettered' => 0,
            'outcome_unknown' => 0,
            'claim_lost' => 0,
            'rows' => [],
        ];

        foreach ($rows as $row) {
            try {
                [$eventName, $moduleKey] = $this->validateIdentity($row);
                $registry = $this->registryFor($moduleKey);
                if ($this->modules !== null
                    && $registry->eventNamesForModule($moduleKey)
                        !== $this->modules->internalObserverEventsForKey($moduleKey)) {
                    throw new RuntimeException(
                        'Bundled module observer declarations do not match immutable event eligibility.',
                    );
                }
                $subscriptions = $registry->forModuleEvent($moduleKey, $eventName);
                if ($subscriptions === []) {
                    throw new RuntimeException(
                        'Bundled module observer declarations do not match durable event eligibility.',
                    );
                }
            } catch (Throwable $failure) {
                $failureReference = $this->report(
                    $failure,
                    'CPE_INTERNAL_EVENT_FANOUT_DECLARATION_FAILED',
                    'declaration',
                );
                try {
                    $failureState = $this->markFailed($row, $failureReference);
                } catch (Throwable $stateFailure) {
                    $unknownReference = $this->report(
                        $stateFailure,
                        'CPE_INTERNAL_EVENT_FANOUT_FAILURE_STATE_UNKNOWN',
                        'failure_state',
                    );
                    $result['outcome_unknown']++;
                    $result['rows'][] = $this->resultRow($row, 'outcome-unknown', $unknownReference);
                    continue;
                }
                if (!$failureState['updated']) {
                    $claimReference = $this->report(
                        new RuntimeException('Internal-event fanout failure claim was lost.'),
                        'CPE_INTERNAL_EVENT_FANOUT_FAILURE_CLAIM_LOST',
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
                $created = $this->expand($row, $subscriptions);
            } catch (InternalEventFanoutClaimLost $claimLost) {
                $claimReference = $this->report(
                    $claimLost,
                    'CPE_INTERNAL_EVENT_FANOUT_CLAIM_LOST',
                    'expansion',
                );
                $result['outcome_unknown']++;
                $result['claim_lost']++;
                $result['rows'][] = $this->resultRow($row, 'claim-lost', $claimReference);
                continue;
            } catch (Throwable $expansionFailure) {
                $failureReference = $this->report(
                    $expansionFailure,
                    'CPE_INTERNAL_EVENT_FANOUT_EXPANSION_FAILED',
                    'expansion',
                );
                try {
                    $failureState = $this->markFailed($row, $failureReference);
                } catch (Throwable $stateFailure) {
                    $unknownReference = $this->report(
                        $stateFailure,
                        'CPE_INTERNAL_EVENT_FANOUT_FAILURE_STATE_UNKNOWN',
                        'failure_state',
                    );
                    $result['outcome_unknown']++;
                    $result['rows'][] = $this->resultRow($row, 'outcome-unknown', $unknownReference);
                    continue;
                }
                if (!$failureState['updated']) {
                    $claimReference = $this->report(
                        new RuntimeException('Internal-event fanout failure claim was lost.'),
                        'CPE_INTERNAL_EVENT_FANOUT_FAILURE_CLAIM_LOST',
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
            $result['expanded']++;
            $result['deliveries_created'] += $created;
            $result['rows'][] = $this->resultRow($row, 'expanded', '');
        }

        return $result;
    }

    private function registryFor(string $moduleKey): InternalEventSubscriberRegistry
    {
        if ($this->registryResolver !== null) {
            $registry = ($this->registryResolver)($moduleKey);
            if (!$registry instanceof InternalEventSubscriberRegistry) {
                throw new RuntimeException('Internal fanout registry resolver returned an invalid declaration set.');
            }
            return $registry;
        }
        if ($this->subscribers !== null) {
            return $this->subscribers;
        }
        if ($this->modules === null) {
            throw new RuntimeException('Internal fanout module catalog is unavailable.');
        }
        return InternalEventSubscriberRegistry::fromModuleInstances(
            $this->modules->modulesForKeys([$moduleKey]),
        );
    }

    /** @return array{0: string, 1: string} */
    private function validateIdentity(array $row): array
    {
        $publicId = (string) ($row['event_public_id'] ?? '');
        $eventName = (string) ($row['event_name'] ?? '');
        $moduleKey = (string) ($row['fanout_module_key'] ?? '');
        if (preg_match('/^event_[a-f0-9]{32}$/D', $publicId) !== 1
            || preg_match('/^[a-z][a-z0-9_.]{2,127}$/D', $eventName) !== 1
            || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $moduleKey) !== 1) {
            throw new RuntimeException('Persisted internal event fanout identity is invalid.');
        }
        return [$eventName, $moduleKey];
    }

    /** @param list<InternalEventSubscription> $subscriptions */
    private function expand(array $row, array $subscriptions): int
    {
        return WriteTransaction::run($this->pdo, function () use ($row, $subscriptions): int {
            $created = 0;
            $insert = $this->pdo->prepare(
                'INSERT INTO domain_event_deliveries
                 (event_id, subscription_id, module_key, status, attempt_count, available_at, last_error, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)
                 ON CONFLICT(event_id, subscription_id) DO NOTHING',
            );
            $existing = $this->pdo->prepare(
                'SELECT module_key FROM domain_event_deliveries WHERE event_id = ? AND subscription_id = ?',
            );
            $now = cpe_now();
            foreach ($subscriptions as $subscription) {
                $insert->execute([
                    (int) $row['event_id'],
                    $subscription->id(),
                    $subscription->moduleKey(),
                    'pending',
                    (string) $row['occurred_at'],
                    '',
                    $now,
                    $now,
                ]);
                $created += $insert->rowCount();
                if ($insert->rowCount() === 0) {
                    $existing->execute([(int) $row['event_id'], $subscription->id()]);
                    $moduleKeys = $existing->fetchAll(PDO::FETCH_COLUMN);
                    if ($moduleKeys !== [$subscription->moduleKey()]) {
                        throw new RuntimeException('Existing internal observer delivery identity does not match fanout.');
                    }
                }
            }
            $mark = $this->pdo->prepare(
                "UPDATE domain_event_module_fanout
                 SET status = 'expanded', expanded_at = ?, failed_at = NULL, last_error = '',
                     locked_at = NULL, lock_token = NULL, updated_at = ?
                 WHERE id = ? AND lock_token = ? AND status IN ('pending', 'retrying')",
            );
            $mark->execute([
                $now,
                $now,
                (int) $row['fanout_id'],
                (string) $row['lock_token'],
            ]);
            if ($mark->rowCount() !== 1) {
                throw new InternalEventFanoutClaimLost(
                    'Internal-event fanout expansion claim was lost.',
                );
            }
            return $created;
        });
    }

    private function claim(int $limit): array
    {
        $now = cpe_now();
        $stale = gmdate('Y-m-d H:i:s', time() - $this->lockSeconds());
        $token = 'fanout_' . bin2hex(random_bytes(16));
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
                "SELECT id FROM domain_event_module_fanout
                 WHERE status IN ('pending', 'retrying') AND available_at <= ?
                   AND (locked_at IS NULL OR locked_at < ?)
                 ORDER BY id LIMIT {$limit}{$locking}",
            );
            $select->execute([$now, $stale]);
            $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $this->pdo->prepare(
                    "UPDATE domain_event_module_fanout
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
            'SELECT f.id AS fanout_id, f.event_id, f.module_key AS fanout_module_key,
                    f.status, f.attempt_count, f.available_at, f.locked_at, f.lock_token,
                    e.public_id AS event_public_id, e.event_name, e.occurred_at
             FROM domain_event_module_fanout f
             JOIN domain_event_outbox e ON e.id = f.event_id
             WHERE f.lock_token = ? ORDER BY f.id',
        );
        $claimed->execute([$token]);
        return $claimed->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{updated: bool, dead_lettered: bool} */
    private function markFailed(array $row, string $failureReference): array
    {
        $attempts = (int) $row['attempt_count'];
        $deadLettered = $attempts >= $this->maxAttempts();
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            "UPDATE domain_event_module_fanout
             SET status = ?, last_error = ?, available_at = ?, failed_at = ?,
                 locked_at = NULL, lock_token = NULL, updated_at = ?
             WHERE id = ? AND lock_token = ? AND status IN ('pending', 'retrying')",
        );
        $stmt->execute([
            $deadLettered ? 'dead_lettered' : 'retrying',
            $failureReference,
            $deadLettered ? $now : $this->retryAt($attempts),
            $deadLettered ? $now : null,
            $now,
            (int) $row['fanout_id'],
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
        $configured = getenv('CPE_DOMAIN_EVENT_FANOUT_MAX_ATTEMPTS');
        if ($configured === false || trim($configured) === '') {
            $configured = getenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS');
        }
        return max(1, min(100, (int) ($configured ?: 10)));
    }

    private function report(Throwable $failure, string $code, string $phase): string
    {
        $incidentId = IncidentReporter::report(
            $failure,
            $code,
            'worker',
            ['operation' => 'internal_event.fanout', 'phase' => $phase],
        );
        return IncidentReporter::reference($code, $incidentId);
    }

    private function resultRow(array $row, string $status, string $error): array
    {
        $publicId = (string) ($row['event_public_id'] ?? '');
        if (preg_match('/^event_[a-f0-9]{32}$/D', $publicId) !== 1) {
            $publicId = 'event_unavailable';
        }
        $moduleKey = (string) ($row['fanout_module_key'] ?? '');
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $moduleKey) !== 1) {
            $moduleKey = 'unavailable';
        }
        return [
            'public_id' => $publicId,
            'module_key' => $moduleKey,
            'status' => $status,
            'error' => $error,
        ];
    }
}
