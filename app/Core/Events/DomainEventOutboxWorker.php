<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Security\OutboundHttpPolicy;
use App\Support\Database;
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
        $result = ['claimed' => count($rows), 'delivered' => 0, 'failed' => 0, 'dead_lettered' => 0, 'rows' => []];
        foreach ($rows as $row) {
            try {
                $destination = $this->deliver($row);
                $this->markDelivered((int) $row['id'], (string) $row['lock_token'], $destination);
                $result['delivered']++;
                $result['rows'][] = ['public_id' => $row['public_id'], 'status' => 'delivered', 'destination' => $destination, 'error' => ''];
            } catch (\Throwable $e) {
                $deadLettered = $this->markFailed($row, $e->getMessage());
                $result['failed']++;
                $result['dead_lettered'] += $deadLettered ? 1 : 0;
                $result['rows'][] = [
                    'public_id' => $row['public_id'],
                    'status' => $deadLettered ? 'dead-lettered' : 'retrying',
                    'destination' => '',
                    'error' => mb_substr($e->getMessage(), 0, 1000),
                ];
            }
        }
        return $result;
    }

    private function claim(int $limit): array
    {
        $now = cpe_now();
        $stale = gmdate('Y-m-d H:i:s', time() - max(30, min(3600, (int) (getenv('CPE_DOMAIN_EVENT_LOCK_SECONDS') ?: 300))));
        $token = 'claim_' . bin2hex(random_bytes(16));
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->pdo->beginTransaction();
        try {
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
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
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
            return 'jsonl:' . basename($path);
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
        ];
        $secret = (string) (getenv('CPE_DOMAIN_EVENT_WEBHOOK_SECRET') ?: '');
        if ($secret !== '') {
            if (strlen($secret) < 32) {
                throw new RuntimeException('CPE_DOMAIN_EVENT_WEBHOOK_SECRET must be at least 32 characters.');
            }
            $headers[] = 'X-CPE-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }
        $result = OutboundHttpPolicy::postJson(
            $url,
            $body,
            $headers,
            (int) (getenv('CPE_DOMAIN_EVENT_TIMEOUT') ?: 10),
            'Domain-event webhook',
            'CPE_DOMAIN_EVENT_ALLOW_HTTP',
        );
        return 'webhook:' . $result['host'];
    }

    private function markDelivered(int $id, string $token, string $destination): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE domain_event_outbox
             SET processed_at = ?, delivered_to = ?, last_error = '', locked_at = NULL, lock_token = NULL
             WHERE id = ? AND lock_token = ? AND processed_at IS NULL"
        );
        $stmt->execute([cpe_now(), $destination, $id, $token]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Domain-event delivery claim was lost before acknowledgement.');
        }
    }

    private function markFailed(array $row, string $error): bool
    {
        $attempts = (int) $row['attempts'];
        $maxAttempts = max(1, min(100, (int) (getenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS') ?: 10)));
        $deadLettered = $attempts >= $maxAttempts;
        $retryAt = gmdate('Y-m-d H:i:s', time() + min(3600, 30 * (2 ** min(7, max(0, $attempts - 1)))));
        $stmt = $this->pdo->prepare(
            'UPDATE domain_event_outbox
             SET last_error = ?, available_at = ?, failed_at = ?, locked_at = NULL, lock_token = NULL
             WHERE id = ? AND lock_token = ? AND processed_at IS NULL'
        );
        $stmt->execute([
            mb_substr(preg_replace('/[\r\n]+/', ' ', $error) ?? '', 0, 1000),
            $retryAt,
            $deadLettered ? cpe_now() : null,
            (int) $row['id'],
            (string) $row['lock_token'],
        ]);
        return $deadLettered;
    }
}
