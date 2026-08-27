<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Security\OutboundHttpPolicy;
use App\Support\Database;
use App\Support\IncidentReporter;
use PDO;
use RuntimeException;

final class DomainEventOutboxWorker
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function work(int $limit = 100): array
    {
        $rows = $this->claim(max(1, min(1000, $limit)));
        $result = [
            'claimed' => count($rows),
            'delivered' => 0,
            'failed' => 0,
            'retrying' => 0,
            'dead_lettered' => 0,
            'outcome_unknown' => 0,
            'claim_lost' => 0,
            'rows' => [],
        ];
        foreach ($rows as $row) {
            try {
                $destination = $this->deliver($row);
            } catch (\Throwable $e) {
                $incidentId = IncidentReporter::report(
                    $e,
                    'CPE_DOMAIN_EVENT_DELIVERY_FAILED',
                    'worker',
                    ['operation' => 'domain_event.delivery', 'status' => 'failed'],
                );
                $failureReference = IncidentReporter::reference('CPE_DOMAIN_EVENT_DELIVERY_FAILED', $incidentId);
                try {
                    $failureState = $this->markFailed($row, $failureReference);
                } catch (\Throwable $stateFailure) {
                    $unknownReference = $this->reportStateFailure(
                        $stateFailure,
                        'CPE_DOMAIN_EVENT_FAILURE_STATE_UNKNOWN',
                        'failure_state',
                    );
                    $result['outcome_unknown']++;
                    $result['rows'][] = [
                        'public_id' => $row['public_id'],
                        'status' => 'outcome-unknown',
                        'destination' => '',
                        'error' => $unknownReference,
                    ];
                    continue;
                }
                if (!$failureState['updated']) {
                    $claimReference = $this->reportStateFailure(
                        new RuntimeException('Domain-event failure state claim was lost.'),
                        'CPE_DOMAIN_EVENT_FAILURE_CLAIM_LOST',
                        'failure_state',
                    );
                    $result['outcome_unknown']++;
                    $result['claim_lost']++;
                    $result['rows'][] = [
                        'public_id' => $row['public_id'],
                        'status' => 'claim-lost',
                        'destination' => '',
                        'error' => $claimReference,
                    ];
                    continue;
                }
                $result['failed']++;
                $result[$failureState['dead_lettered'] ? 'dead_lettered' : 'retrying']++;
                $result['rows'][] = [
                    'public_id' => $row['public_id'],
                    'status' => $failureState['dead_lettered'] ? 'dead-lettered' : 'retrying',
                    'destination' => '',
                    'error' => $failureReference,
                ];
                continue;
            }

            try {
                $acknowledged = $this->markDelivered(
                    (int) $row['id'],
                    (string) $row['lock_token'],
                    $destination,
                );
            } catch (\Throwable $ackFailure) {
                $unknownReference = $this->reportStateFailure(
                    $ackFailure,
                    'CPE_DOMAIN_EVENT_ACK_STATE_UNKNOWN',
                    'acknowledgment',
                );
                $result['outcome_unknown']++;
                $result['rows'][] = [
                    'public_id' => $row['public_id'],
                    'status' => 'outcome-unknown',
                    'destination' => $destination,
                    'error' => $unknownReference,
                ];
                continue;
            }
            if (!$acknowledged) {
                $claimReference = $this->reportStateFailure(
                    new RuntimeException('Domain-event acknowledgement claim was lost.'),
                    'CPE_DOMAIN_EVENT_ACK_CLAIM_LOST',
                    'acknowledgment',
                );
                $result['outcome_unknown']++;
                $result['claim_lost']++;
                $result['rows'][] = [
                    'public_id' => $row['public_id'],
                    'status' => 'claim-lost',
                    'destination' => $destination,
                    'error' => $claimReference,
                ];
                continue;
            }
            $result['delivered']++;
            $result['rows'][] = ['public_id' => $row['public_id'], 'status' => 'delivered', 'destination' => $destination, 'error' => ''];
        }
        return $result;
    }

    private function claim(int $limit): array
    {
        $now = cpe_now();
        $stale = gmdate('Y-m-d H:i:s', time() - max(30, min(3600, (int) (getenv('CPE_DOMAIN_EVENT_LOCK_SECONDS') ?: 300))));
        $token = 'claim_' . bin2hex(random_bytes(16));
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sqliteImmediate = $driver === 'sqlite';
        $started = false;
        try {
            if ($sqliteImmediate) {
                $this->pdo->exec('BEGIN IMMEDIATE');
            } else {
                $this->pdo->beginTransaction();
            }
            $started = true;
            $locking = $driver === 'pgsql' ? ' FOR UPDATE SKIP LOCKED' : '';
            $select = $this->pdo->prepare(
                "SELECT id FROM domain_event_outbox
                 WHERE processed_at IS NULL AND failed_at IS NULL AND available_at <= ?
                   AND (locked_at IS NULL OR locked_at < ?)
                 ORDER BY id LIMIT {$limit}{$locking}"
            );
            $select->execute([$now, $stale]);
            $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $this->pdo->prepare(
                    "UPDATE domain_event_outbox
                     SET locked_at = ?, lock_token = ?, attempts = attempts + 1
                     WHERE id IN ({$placeholders}) AND processed_at IS NULL AND failed_at IS NULL
                       AND (locked_at IS NULL OR locked_at < ?)"
                );
                $update->execute([$now, $token, ...$ids, $stale]);
            }
            if ($sqliteImmediate) {
                $this->pdo->exec('COMMIT');
            } else {
                $this->pdo->commit();
            }
            $started = false;
        } catch (\Throwable $e) {
            if ($started) {
                try {
                    if ($sqliteImmediate) {
                        $this->pdo->exec('ROLLBACK');
                    } elseif ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                } catch (\Throwable) {
                    Database::reset();
                }
            }
            throw $e;
        }
        if ($ids === []) {
            return [];
        }
        $claimed = $this->pdo->prepare('SELECT * FROM domain_event_outbox WHERE lock_token = ? ORDER BY id');
        $claimed->execute([$token]);
        return $claimed->fetchAll();
    }

    private function deliver(array $row): string
    {
        $path = trim((string) (getenv('CPE_DOMAIN_EVENT_OUTBOX_PATH') ?: ''));
        $url = trim((string) (getenv('CPE_DOMAIN_EVENT_WEBHOOK_URL') ?: ''));
        if ($path !== '' && $url !== '') {
            throw new RuntimeException('Configure one domain-event sink, not both file and webhook delivery.');
        }
        $envelope = $this->envelope($row);
        if ($path !== '') {
            $directory = dirname($path);
            if (!is_dir($directory) && (file_exists($directory) || !mkdir($directory, 0775, true))) {
                throw new RuntimeException('Could not create the domain-event outbox directory.');
            }
            if (file_put_contents($path, json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Could not append to the domain-event outbox file.');
            }
            return 'file';
        }
        if ($url !== '') {
            return $this->deliverWebhook($url, $envelope);
        }
        return 'internal';
    }

    private function envelope(array $row): array
    {
        try {
            $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Domain-event payload is invalid JSON.', 0, $e);
        }
        return [
            'schema' => 'career_services.domain_event.v1',
            'id' => (string) $row['public_id'],
            'idempotency_key' => (string) $row['public_id'],
            'name' => (string) $row['event_name'],
            'aggregate' => ['type' => (string) $row['aggregate_type'], 'id' => (string) $row['aggregate_public_id']],
            'module' => (string) $row['module_key'],
            'occurred_at' => (string) $row['occurred_at'],
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    private function deliverWebhook(string $url, array $envelope): string
    {
        $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type: application/json',
            'User-Agent: CareerServicesPortal/' . (string) cpe_config('app.version', '0.0.0'),
            'X-CPE-Event-ID: ' . $envelope['id'],
            'X-CPE-Idempotency-Key: ' . $envelope['idempotency_key'],
        ];
        $secret = (string) (getenv('CPE_DOMAIN_EVENT_WEBHOOK_SECRET') ?: '');
        if ($secret !== '') {
            if (strlen($secret) < 32) {
                throw new RuntimeException('CPE_DOMAIN_EVENT_WEBHOOK_SECRET must be at least 32 characters.');
            }
            $headers[] = 'X-CPE-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }
        OutboundHttpPolicy::postJson(
            $url,
            $body,
            $headers,
            (int) (getenv('CPE_DOMAIN_EVENT_TIMEOUT') ?: 10),
            'Domain-event webhook',
            'CPE_DOMAIN_EVENT_ALLOW_HTTP',
        );
        return 'webhook';
    }

    private function markDelivered(int $id, string $token, string $destination): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE domain_event_outbox
             SET processed_at = ?, delivered_to = ?, last_error = '', locked_at = NULL, lock_token = NULL
             WHERE id = ? AND lock_token = ? AND processed_at IS NULL AND failed_at IS NULL"
        );
        $stmt->execute([cpe_now(), $destination, $id, $token]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{updated: bool, dead_lettered: bool} */
    private function markFailed(array $row, string $failureReference): array
    {
        $attempts = (int) $row['attempts'];
        $maxAttempts = max(1, min(100, (int) (getenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS') ?: 10)));
        $deadLettered = $attempts >= $maxAttempts;
        $retryAt = gmdate('Y-m-d H:i:s', time() + min(3600, 30 * (2 ** min(7, max(0, $attempts - 1)))));
        $stmt = $this->pdo->prepare(
            'UPDATE domain_event_outbox
             SET last_error = ?, available_at = ?, failed_at = ?, locked_at = NULL, lock_token = NULL
             WHERE id = ? AND lock_token = ? AND processed_at IS NULL AND failed_at IS NULL'
        );
        $stmt->execute([
            IncidentReporter::reference(
                'CPE_DOMAIN_EVENT_DELIVERY_FAILED',
                $this->incidentIdFromReference($failureReference),
            ),
            $retryAt,
            $deadLettered ? cpe_now() : null,
            (int) $row['id'],
            (string) $row['lock_token'],
        ]);
        return ['updated' => $stmt->rowCount() === 1, 'dead_lettered' => $deadLettered];
    }

    private function reportStateFailure(\Throwable $failure, string $code, string $phase): string
    {
        $incidentId = IncidentReporter::report(
            $failure,
            $code,
            'worker',
            ['operation' => 'domain_event.delivery', 'phase' => $phase],
        );
        return IncidentReporter::reference($code, $incidentId);
    }

    private function incidentIdFromReference(string $failureReference): string
    {
        return preg_match('/\b(inc_[a-f0-9]{32})\z/D', $failureReference, $match) === 1
            ? $match[1]
            : 'inc_unavailable';
    }
}
