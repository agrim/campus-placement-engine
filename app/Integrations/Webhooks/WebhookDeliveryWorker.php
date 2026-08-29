<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Core\Events\PublicEventEnvelope;
use App\Core\Persistence\WriteTransaction;
use App\Support\Database;
use App\Support\IncidentReporter;
use PDO;
use RuntimeException;

final class WebhookDeliveryWorker
{
    private const RETRY_SCHEDULE = [60, 300, 900, 3600, 14400, 43200, 86400];
    private const SECRET_EXPIRY_BATCH_SIZE = 100;

    public function __construct(
        private readonly ?PDO $connection = null,
        private readonly ?WebhookHttpTransport $transport = null,
        private readonly ?WebhookSecretCipher $cipher = null,
        private readonly mixed $clock = null,
        private readonly mixed $jitter = null,
        private readonly mixed $failureStateObserver = null,
    ) {
        if ($clock !== null && !is_callable($clock)) {
            throw new RuntimeException('Webhook worker clock must be callable.');
        }
        if ($jitter !== null && !is_callable($jitter)) {
            throw new RuntimeException('Webhook worker jitter must be callable.');
        }
        if ($failureStateObserver !== null && !is_callable($failureStateObserver)) {
            throw new RuntimeException('Webhook worker failure-state observer must be callable.');
        }
    }

    /** @return array<string, mixed> */
    public function work(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $pdo = $this->pdo();
        $workerId = 'worker_' . bin2hex(random_bytes(16));
        $this->heartbeat($workerId, 'running', 0, 0, 0, false);
        $this->expireSecretOverlap();
        $this->pruneExpiredDeliveries();
        $rows = $this->claim($limit);
        $result = [
            'claimed' => count($rows),
            'succeeded' => 0,
            'retrying' => 0,
            'dead_lettered' => 0,
            'claim_lost' => 0,
            'rows' => [],
        ];

        foreach ($rows as $claim) {
            $row = $this->claimedRow(
                (int) $claim['id'],
                (string) $claim['lock_token'],
                (int) $claim['lease_generation'],
            );
            if ($row === null) {
                $result['claim_lost']++;
                $result['rows'][] = [
                    'delivery_id' => (string) $claim['public_id'],
                    'status' => 'claim-lost',
                    'failure_reference' => '',
                ];
                continue;
            }

            $httpStatus = null;
            $failure = null;
            try {
                $body = PublicEventEnvelope::fromOutboxRow($row)->toJson();
                $timestamp = $this->nowEpoch();
                $secrets = [$this->decrypt($row, 'current')];
                if (($row['previous_secret_expires_at'] ?? null) !== null
                    && (string) $row['previous_secret_expires_at'] >= $this->nowSql()
                    && ($row['previous_secret_ciphertext'] ?? null) !== null) {
                    $secrets[] = $this->decrypt($row, 'previous');
                }
                $signature = WebhookSigner::signatureHeader(
                    (string) $row['event_public_id'],
                    $timestamp,
                    $body,
                    $secrets,
                );
                $response = $this->httpTransport()->send(
                    (string) $row['endpoint_url'],
                    $body,
                    $this->headers($row, $timestamp, $signature),
                    (int) $row['allow_private_network'] === 1,
                );
                $httpStatus = $response->statusCode;
            } catch (\Throwable $caught) {
                $failure = $caught;
            }

            $outcome = $this->classify($httpStatus, $failure, (int) $row['attempt_count']);
            if ($outcome['status'] === 'succeeded') {
                if (!$this->markSucceeded($row, (int) $httpStatus)) {
                    $result['claim_lost']++;
                    $result['rows'][] = [
                        'delivery_id' => (string) $row['delivery_public_id'],
                        'status' => 'claim-lost',
                        'failure_reference' => '',
                    ];
                    continue;
                }
                $result['succeeded']++;
                $result['rows'][] = [
                    'delivery_id' => (string) $row['delivery_public_id'],
                    'status' => 'succeeded',
                    'failure_reference' => '',
                ];
                continue;
            }

            $reference = $this->failureReference($failure, $outcome['code']);
            if ($outcome['status'] === 'retrying') {
                $retryAt = gmdate('Y-m-d H:i:s', $this->nowEpoch() + $this->retryDelaySeconds((int) $row['attempt_count']));
                if (!$this->markFailed($row, $outcome['code'], $reference, $httpStatus, false, $retryAt)) {
                    $result['claim_lost']++;
                    $result['rows'][] = [
                        'delivery_id' => (string) $row['delivery_public_id'],
                        'status' => 'claim-lost',
                        'failure_reference' => '',
                    ];
                    continue;
                }
                $result['retrying']++;
                $result['rows'][] = [
                    'delivery_id' => (string) $row['delivery_public_id'],
                    'status' => 'retrying',
                    'failure_reference' => $reference,
                ];
                continue;
            }

            if (!$this->markFailed($row, $outcome['code'], $reference, $httpStatus, true, $this->nowSql())) {
                $result['claim_lost']++;
                $result['rows'][] = [
                    'delivery_id' => (string) $row['delivery_public_id'],
                    'status' => 'claim-lost',
                    'failure_reference' => '',
                ];
                continue;
            }
            $result['dead_lettered']++;
            $result['rows'][] = [
                'delivery_id' => (string) $row['delivery_public_id'],
                'status' => 'dead-lettered',
                'failure_reference' => $reference,
            ];
        }

        $failedCount = $result['retrying'] + $result['dead_lettered'] + $result['claim_lost'];
        $this->heartbeat(
            $workerId,
            $failedCount > 0 ? 'degraded' : 'ok',
            $result['claimed'],
            $result['succeeded'],
            $failedCount,
            true,
        );
        return $result;
    }

    /** @return array{status: 'succeeded'|'retrying'|'dead_lettered', code: string} */
    public function classify(?int $statusCode, ?\Throwable $failure, int $attempt): array
    {
        if ($failure === null && $statusCode !== null && $statusCode >= 200 && $statusCode < 300) {
            return ['status' => 'succeeded', 'code' => ''];
        }
        $maxAttempts = max(1, min(20, (int) (getenv('CPE_WEBHOOK_MAX_ATTEMPTS') ?: 10)));
        if ($attempt >= $maxAttempts) {
            return ['status' => 'dead_lettered', 'code' => 'attempts_exhausted'];
        }
        if ($failure instanceof WebhookTransportException) {
            return $failure->retryable
                ? ['status' => 'retrying', 'code' => $failure->failureKind]
                : ['status' => 'dead_lettered', 'code' => $failure->failureKind];
        }
        if ($failure !== null) {
            return ['status' => 'retrying', 'code' => 'delivery_failure'];
        }
        if ($statusCode === 410) {
            return ['status' => 'dead_lettered', 'code' => 'http_gone'];
        }
        if (in_array($statusCode, [408, 425, 429, 500, 502, 503, 504], true)) {
            return ['status' => 'retrying', 'code' => 'http_' . $statusCode];
        }
        if ($statusCode !== null && $statusCode >= 400 && $statusCode < 500) {
            return $attempt < 2
                ? ['status' => 'retrying', 'code' => 'http_client_error']
                : ['status' => 'dead_lettered', 'code' => 'http_client_error'];
        }
        return ['status' => 'dead_lettered', 'code' => 'http_terminal'];
    }

    public function retryDelaySeconds(int $attempt): int
    {
        $index = max(0, min(count(self::RETRY_SCHEDULE) - 1, $attempt - 1));
        $base = self::RETRY_SCHEDULE[$index];
        $percentage = $this->jitter !== null
            ? (int) ($this->jitter)($attempt)
            : random_int(-20, 20);
        $percentage = max(-20, min(20, $percentage));
        return max(1, (int) round($base * (100 + $percentage) / 100));
    }

    /** @return list<array<string, mixed>> */
    private function claim(int $limit): array
    {
        $pdo = $this->pdo();
        return WriteTransaction::run($pdo, function () use ($pdo, $limit): array {
            $now = $this->nowSql();
            $leaseSeconds = max(30, min(3600, (int) (getenv('CPE_WEBHOOK_LEASE_SECONDS') ?: 300)));
            $stale = gmdate('Y-m-d H:i:s', $this->nowEpoch() - $leaseSeconds);
            $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            $claimCursor = $this->coordinateClaims($pdo, $driver);
            $pdo->prepare(
                "UPDATE webhook_deliveries
                 SET status = 'retrying', locked_at = NULL, lock_token = NULL,
                     available_at = ?, last_error_code = 'stale_lease_reclaimed', updated_at = ?
                 WHERE status = 'processing' AND locked_at < ?",
            )->execute([$now, $now, $stale]);

            $candidateLimit = min(5000, max($limit, $limit * 10));
            $select = $pdo->prepare(
                "SELECT candidate.id, candidate.public_id, candidate.subscription_id,
                        candidate.institution_id
                 FROM (
                     SELECT delivery.id, delivery.public_id, delivery.subscription_id,
                            subscription.institution_id,
                            ROW_NUMBER() OVER (
                                PARTITION BY delivery.subscription_id ORDER BY delivery.id
                            ) AS subscription_rank
                     FROM webhook_deliveries delivery
                     JOIN webhook_subscriptions subscription ON subscription.id = delivery.subscription_id
                     JOIN domain_event_outbox event ON event.id = delivery.event_id
                     WHERE delivery.status IN ('pending', 'retrying')
                       AND delivery.available_at <= ?
                       AND delivery.locked_at IS NULL AND delivery.lock_token IS NULL
                       AND subscription.lifecycle_state IN ('active', 'degraded')
                       AND subscription.endpoint_version = delivery.endpoint_version
                       AND (subscription.circuit_open_until IS NULL OR subscription.circuit_open_until <= ?)
                       AND NOT EXISTS (
                           SELECT 1
                           FROM webhook_deliveries earlier_delivery
                           JOIN domain_event_outbox earlier_event ON earlier_event.id = earlier_delivery.event_id
                           WHERE earlier_delivery.subscription_id = delivery.subscription_id
                             AND earlier_event.public_aggregate_type = event.public_aggregate_type
                             AND earlier_event.public_aggregate_id = event.public_aggregate_id
                             AND earlier_event.public_aggregate_version < event.public_aggregate_version
                             AND earlier_delivery.status <> 'succeeded'
                             AND NOT (
                                 earlier_delivery.status = 'dead_lettered'
                                 AND earlier_delivery.last_error_code = 'subscription_revoked'
                             )
                       )
                 ) candidate
                 ORDER BY candidate.subscription_rank,
                          CASE WHEN candidate.subscription_id > ? THEN 0 ELSE 1 END,
                          candidate.subscription_id,
                          candidate.id
                 LIMIT {$candidateLimit}",
            );
            $select->execute([$now, $now, $claimCursor]);
            $candidates = $select->fetchAll(PDO::FETCH_ASSOC);
            if ($candidates === []) {
                return [];
            }

            $endpointLimit = max(1, min(10, (int) (getenv('CPE_WEBHOOK_ENDPOINT_CONCURRENCY') ?: 1)));
            $institutionLimit = max(1, min(100, (int) (getenv('CPE_WEBHOOK_INSTITUTION_CONCURRENCY') ?: 5)));
            $endpointCounts = [];
            $institutionCounts = [];
            foreach ($pdo->query(
                "SELECT delivery.subscription_id, subscription.institution_id, COUNT(*) AS active_count
                 FROM webhook_deliveries delivery
                 JOIN webhook_subscriptions subscription ON subscription.id = delivery.subscription_id
                 WHERE delivery.status = 'processing' AND delivery.locked_at >= " . $pdo->quote($stale) . '
                 GROUP BY delivery.subscription_id, subscription.institution_id',
            )->fetchAll(PDO::FETCH_ASSOC) as $active) {
                $endpointCounts[(int) $active['subscription_id']] = (int) $active['active_count'];
                $institutionCounts[(int) $active['institution_id']] =
                    ($institutionCounts[(int) $active['institution_id']] ?? 0) + (int) $active['active_count'];
            }

            $claimed = [];
            $update = $pdo->prepare(
                "UPDATE webhook_deliveries
                 SET status = 'processing', attempt_count = attempt_count + 1,
                     locked_at = ?, lock_token = ?, lease_generation = lease_generation + 1, updated_at = ?
                 WHERE id = ? AND status IN ('pending', 'retrying')
                   AND locked_at IS NULL AND lock_token IS NULL",
            );
            $lastClaimedSubscriptionId = null;
            foreach ($candidates as $candidate) {
                if (count($claimed) >= $limit) {
                    break;
                }
                $subscriptionId = (int) $candidate['subscription_id'];
                $institutionId = (int) $candidate['institution_id'];
                if (($endpointCounts[$subscriptionId] ?? 0) >= $endpointLimit
                    || ($institutionCounts[$institutionId] ?? 0) >= $institutionLimit) {
                    continue;
                }
                $token = 'claim_' . bin2hex(random_bytes(16));
                $update->execute([$now, $token, $now, (int) $candidate['id']]);
                if ($update->rowCount() !== 1) {
                    continue;
                }
                $claimed[] = [
                    'id' => (int) $candidate['id'],
                    'public_id' => (string) $candidate['public_id'],
                    'lock_token' => $token,
                    'lease_generation' => $this->claimedGeneration($pdo, (int) $candidate['id'], $token),
                ];
                $endpointCounts[$subscriptionId] = ($endpointCounts[$subscriptionId] ?? 0) + 1;
                $institutionCounts[$institutionId] = ($institutionCounts[$institutionId] ?? 0) + 1;
                $lastClaimedSubscriptionId = $subscriptionId;
            }
            if ($lastClaimedSubscriptionId !== null) {
                $cursor = $pdo->prepare(
                    'UPDATE webhook_worker_heartbeat
                     SET claim_cursor_subscription_id = ? WHERE singleton_id = 1',
                );
                $cursor->execute([$lastClaimedSubscriptionId]);
                if ($cursor->rowCount() !== 1) {
                    throw new RuntimeException('Webhook claim coordination cursor could not be advanced.');
                }
            }
            return $claimed;
        });
    }

    private function coordinateClaims(PDO $pdo, string $driver): int
    {
        $lock = $driver === 'pgsql' ? ' FOR UPDATE' : '';
        $query = $pdo->query(
            'SELECT claim_cursor_subscription_id
             FROM webhook_worker_heartbeat WHERE singleton_id = 1' . $lock,
        );
        $cursor = $query->fetchColumn();
        if ($cursor === false) {
            throw new RuntimeException('Webhook claim coordination state is unavailable.');
        }
        return (int) $cursor;
    }

    private function claimedGeneration(PDO $pdo, int $id, string $token): int
    {
        $query = $pdo->prepare('SELECT lease_generation FROM webhook_deliveries WHERE id = ? AND lock_token = ?');
        $query->execute([$id, $token]);
        $generation = $query->fetchColumn();
        if ($generation === false) {
            throw new RuntimeException('Webhook delivery claim could not be fenced.');
        }
        return (int) $generation;
    }

    private function claimedRow(int $id, string $token, int $generation): ?array
    {
        $query = $this->pdo()->prepare(
            "SELECT delivery.id AS delivery_id, delivery.public_id AS delivery_public_id,
                    delivery.attempt_count, delivery.lock_token, delivery.lease_generation,
                    subscription.id AS subscription_id, subscription.public_id AS subscription_public_id,
                    subscription.endpoint_url, subscription.allow_private_network,
                    subscription.current_secret_ciphertext, subscription.current_secret_nonce,
                    subscription.current_secret_tag, subscription.current_secret_key_version,
                    subscription.previous_secret_ciphertext, subscription.previous_secret_nonce,
                    subscription.previous_secret_tag, subscription.previous_secret_key_version,
                    subscription.previous_secret_expires_at,
                    institution.public_id AS institution_public_id,
                    event.public_id AS event_public_id, event.public_id AS public_id, event.public_event_type,
                    event.public_schema_version, event.occurred_at, event.public_instance_id,
                    event.public_aggregate_type, event.public_aggregate_id,
                    event.public_aggregate_version, event.public_payload_json,
                    event.public_correlation_id
             FROM webhook_deliveries delivery
             JOIN webhook_subscriptions subscription ON subscription.id = delivery.subscription_id
             JOIN institutions institution ON institution.id = subscription.institution_id
             JOIN domain_event_outbox event ON event.id = delivery.event_id
             WHERE delivery.id = ? AND delivery.status = 'processing'
               AND delivery.lock_token = ? AND delivery.lease_generation = ?
               AND subscription.lifecycle_state IN ('active', 'degraded')
               AND subscription.endpoint_version = delivery.endpoint_version",
        );
        $query->execute([$id, $token, $generation]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : null;
    }

    private function markSucceeded(array $row, int $httpStatus): bool
    {
        $pdo = $this->pdo();
        return WriteTransaction::run($pdo, function () use ($pdo, $row, $httpStatus): bool {
            $now = $this->nowSql();
            $this->lockSubscriptionForCompletion($pdo, (int) $row['subscription_id']);
            $update = $pdo->prepare(
                "UPDATE webhook_deliveries
                 SET status = 'succeeded', processed_at = ?, dead_lettered_at = NULL,
                     locked_at = NULL, lock_token = NULL, last_http_status = ?,
                     last_error_code = '', last_failure_reference = '', updated_at = ?
                 WHERE id = ? AND status = 'processing' AND lock_token = ? AND lease_generation = ?",
            );
            $update->execute([
                $now, $httpStatus, $now, (int) $row['delivery_id'],
                (string) $row['lock_token'], (int) $row['lease_generation'],
            ]);
            if ($update->rowCount() !== 1) {
                return false;
            }
            $pdo->prepare(
                "UPDATE webhook_subscriptions
                 SET lifecycle_state = CASE WHEN lifecycle_state = 'degraded' THEN 'active' ELSE lifecycle_state END,
                     last_success_at = ?, consecutive_failures = 0, circuit_open_until = NULL,
                     last_failure_code = '', last_failure_reference = '', updated_at = ?
                 WHERE id = ? AND lifecycle_state IN ('active', 'degraded')",
            )->execute([$now, $now, (int) $row['subscription_id']]);
            return true;
        });
    }

    private function markFailed(
        array $row,
        string $code,
        string $reference,
        ?int $httpStatus,
        bool $deadLettered,
        string $availableAt,
    ): bool {
        $pdo = $this->pdo();
        return WriteTransaction::run($pdo, function () use (
            $pdo, $row, $code, $reference, $httpStatus, $deadLettered, $availableAt,
        ): bool {
            $now = $this->nowSql();
            $currentFailures = $this->lockSubscriptionForCompletion($pdo, (int) $row['subscription_id']);
            if ($this->failureStateObserver !== null) {
                ($this->failureStateObserver)((int) $row['subscription_id'], $currentFailures);
            }
            $update = $pdo->prepare(
                "UPDATE webhook_deliveries
                 SET status = ?, available_at = ?, processed_at = NULL, dead_lettered_at = ?,
                     locked_at = NULL, lock_token = NULL, last_http_status = ?,
                     last_error_code = ?, last_failure_reference = ?, updated_at = ?
                 WHERE id = ? AND status = 'processing' AND lock_token = ? AND lease_generation = ?",
            );
            $update->execute([
                $deadLettered ? 'dead_lettered' : 'retrying',
                $availableAt,
                $deadLettered ? $now : null,
                $httpStatus,
                $code,
                $reference,
                $now,
                (int) $row['delivery_id'],
                (string) $row['lock_token'],
                (int) $row['lease_generation'],
            ]);
            if ($update->rowCount() !== 1) {
                return false;
            }
            $nextFailures = $currentFailures + 1;
            $degrade = $deadLettered || $nextFailures >= 3;
            $circuitUntil = $nextFailures >= 3
                ? gmdate('Y-m-d H:i:s', $this->nowEpoch() + min(3600, 300 * (2 ** min(3, $nextFailures - 3))))
                : null;
            $lifecycleUpdate = $degrade ? "'degraded'" : 'lifecycle_state';
            $subscription = $pdo->prepare(
                "UPDATE webhook_subscriptions
                 SET lifecycle_state = {$lifecycleUpdate},
                     last_failure_at = ?, last_failure_code = ?, last_failure_reference = ?,
                     consecutive_failures = ?, circuit_open_until = ?, updated_at = ?
                 WHERE id = ? AND lifecycle_state IN ('active', 'degraded')",
            );
            $subscription->execute([
                $now, $code, $reference, $nextFailures,
                $circuitUntil, $now, (int) $row['subscription_id'],
            ]);
            return true;
        });
    }

    private function lockSubscriptionForCompletion(PDO $pdo, int $subscriptionId): int
    {
        // Revocation locks the subscription before fencing its deliveries.
        // Completion must use the same order to avoid a PostgreSQL cycle.
        $suffix = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql'
            ? ' FOR UPDATE'
            : '';
        $query = $pdo->prepare(
            'SELECT consecutive_failures FROM webhook_subscriptions WHERE id = ?' . $suffix,
        );
        $query->execute([$subscriptionId]);
        $count = $query->fetchColumn();
        if ($count === false) {
            throw new RuntimeException('Webhook subscription completion state is unavailable.');
        }
        return (int) $count;
    }

    private function decrypt(array $row, string $slot): string
    {
        return $this->secretCipher()->decrypt(
            [
                'ciphertext' => $row[$slot . '_secret_ciphertext'] ?? null,
                'nonce' => $row[$slot . '_secret_nonce'] ?? null,
                'tag' => $row[$slot . '_secret_tag'] ?? null,
                'key_version' => $row[$slot . '_secret_key_version'] ?? null,
            ],
            (string) $row['institution_public_id'],
            (string) $row['subscription_public_id'],
        );
    }

    /** @return list<string> */
    private function headers(array $row, int $timestamp, string $signature): array
    {
        return [
            'Content-Type: application/json',
            'User-Agent: CampusPlacementEngine/' . (string) cpe_config('app.version', '0.0.0'),
            'CPE-Webhook-Id: ' . (string) $row['event_public_id'],
            'CPE-Webhook-Timestamp: ' . $timestamp,
            'CPE-Webhook-Signature: ' . $signature,
            'CPE-Webhook-Schema: ' . (string) $row['public_event_type'] . ';version=' . (int) $row['public_schema_version'],
        ];
    }

    private function failureReference(?\Throwable $failure, string $code): string
    {
        $incident = $failure ?? new RuntimeException('Webhook delivery returned a classified HTTP failure.');
        $incidentId = IncidentReporter::report(
            $incident,
            'CPE_WEBHOOK_DELIVERY_FAILED',
            'worker',
            ['operation' => 'webhook.delivery', 'phase' => 'delivery', 'status' => 'failed'],
        );
        return IncidentReporter::reference('CPE_WEBHOOK_DELIVERY_FAILED', $incidentId);
    }

    private function expireSecretOverlap(): void
    {
        $pdo = $this->pdo();
        WriteTransaction::run($pdo, function () use ($pdo): void {
            $now = $this->nowSql();
            $postgres = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql';
            $expired = $pdo->prepare(
                'SELECT id FROM webhook_subscriptions
                 WHERE previous_secret_expires_at IS NOT NULL AND previous_secret_expires_at < ?
                 ORDER BY id LIMIT ' . self::SECRET_EXPIRY_BATCH_SIZE . ($postgres ? ' FOR UPDATE' : ''),
            );
            $expired->execute([$now]);
            $subscriptionIds = array_map('intval', $expired->fetchAll(PDO::FETCH_COLUMN));
            if ($subscriptionIds === []) {
                return;
            }

            $clear = $pdo->prepare(
                'UPDATE webhook_subscriptions
                 SET previous_secret_ciphertext = NULL, previous_secret_nonce = NULL,
                     previous_secret_tag = NULL, previous_secret_key_version = NULL,
                     previous_secret_expires_at = NULL, updated_at = ?
                 WHERE id = ?
                   AND previous_secret_expires_at IS NOT NULL AND previous_secret_expires_at < ?',
            );
            foreach ($subscriptionIds as $subscriptionId) {
                $clear->execute([$now, $subscriptionId, $now]);
            }
        });
    }

    private function pruneExpiredDeliveries(): void
    {
        $this->pdo()->prepare(
            "DELETE FROM webhook_deliveries
             WHERE id IN (
                 SELECT id FROM webhook_deliveries
                 WHERE status IN ('succeeded', 'dead_lettered') AND retention_until < ?
                 ORDER BY id LIMIT 100
             )",
        )->execute([$this->nowSql()]);
    }

    private function heartbeat(
        string $workerId,
        string $status,
        int $claimed,
        int $succeeded,
        int $failed,
        bool $finished,
    ): void {
        $now = $this->nowSql();
        $statement = $this->pdo()->prepare(
            'INSERT INTO webhook_worker_heartbeat
             (singleton_id, worker_public_id, started_at, finished_at, status,
              claimed_count, succeeded_count, failed_count)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(singleton_id) DO UPDATE SET
                 worker_public_id = excluded.worker_public_id,
                 started_at = CASE WHEN excluded.status = ? THEN excluded.started_at ELSE webhook_worker_heartbeat.started_at END,
                 finished_at = excluded.finished_at,
                 status = excluded.status,
                 claimed_count = excluded.claimed_count,
                 succeeded_count = excluded.succeeded_count,
                 failed_count = excluded.failed_count',
        );
        $statement->execute([
            $workerId, $now, $finished ? $now : null, $status, $claimed, $succeeded, $failed, 'running',
        ]);
    }

    private function nowEpoch(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    private function nowSql(): string
    {
        return gmdate('Y-m-d H:i:s', $this->nowEpoch());
    }

    private function pdo(): PDO
    {
        return $this->connection ?? Database::connection();
    }

    private function httpTransport(): WebhookHttpTransport
    {
        return $this->transport ?? new CurlWebhookHttpTransport();
    }

    private function secretCipher(): WebhookSecretCipher
    {
        return $this->cipher ?? WebhookSecretCipher::fromEnvironment();
    }
}
