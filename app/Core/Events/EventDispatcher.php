<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Modules\ModuleRegistry;
use App\Core\Persistence\WriteTransaction;
use App\Support\Database;
use PDO;
use RuntimeException;

final class EventDispatcher
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ModuleRegistry $modules,
        private readonly ?InternalEventSubscriberRegistry $subscribers = null,
    ) {
    }

    public function dispatch(DomainEvent $event): string
    {
        if (preg_match('/^[a-z][a-z0-9_.]{2,127}$/', $event->name) !== 1) {
            throw new RuntimeException('Invalid domain event name: ' . $event->name);
        }
        $publicId = 'event_' . bin2hex(random_bytes(16));
        $payloadJson = json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        WriteTransaction::run($this->pdo, function () use ($event, $payloadJson, $publicId): void {
            $eligibleModuleKeys = $this->eligibleModuleKeys($event->name);
            $institution = $this->pdo->query(
                "SELECT id, public_id FROM institutions WHERE slug = 'default'",
            )->fetch();
            if (!is_array($institution)) {
                throw new RuntimeException('Cannot publish an event without an institution context.');
            }
            $projection = $event->publicProjection;
            if ($projection !== null) {
                if (!hash_equals((string) $institution['public_id'], $projection->instanceId)) {
                    throw new RuntimeException('Public event instance does not match the institution context.');
                }
                if (!hash_equals($event->aggregatePublicId, $projection->aggregateId)) {
                    throw new RuntimeException('Public event aggregate does not match the private domain event.');
                }
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO domain_event_outbox
                 (public_id, event_name, aggregate_type, aggregate_public_id, institution_id, module_key,
                  payload_json, occurred_at, available_at,
                  public_event_type, public_schema_version, public_instance_id, public_aggregate_type,
                  public_aggregate_id, public_aggregate_version, public_payload_json, public_correlation_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $publicId,
                $event->name,
                $event->aggregateType,
                $event->aggregatePublicId,
                (int) $institution['id'],
                $event->moduleKey,
                $payloadJson,
                $event->occurredAt,
                $event->occurredAt,
                ...($projection?->persistenceValues() ?? array_fill(0, 8, null)),
            ]);
            $eventId = Database::lastInsertId($this->pdo);
            if ($projection !== null) {
                $this->captureWebhookDeliveries(
                    $eventId,
                    $projection->eventType,
                    $projection->schemaVersion,
                    $event->occurredAt,
                );
            }
            if ($eligibleModuleKeys === []) {
                return;
            }
            $createdAt = cpe_now();
            $fanout = $this->pdo->prepare(
                'INSERT INTO domain_event_module_fanout
                 (event_id, module_key, status, attempt_count, available_at, last_error, created_at, updated_at)
                 VALUES (?, ?, ?, 0, ?, ?, ?, ?)'
            );
            foreach ($eligibleModuleKeys as $moduleKey) {
                $fanout->execute([
                    $eventId,
                    $moduleKey,
                    'pending',
                    $event->occurredAt,
                    '',
                    $createdAt,
                    $createdAt,
                ]);
            }
        });
        return $publicId;
    }

    /** @return list<string> */
    private function eligibleModuleKeys(string $eventName): array
    {
        if ($this->subscribers !== null) {
            return $this->subscribers->moduleKeysForEvent($eventName);
        }

        $keys = [];
        foreach ($this->modules->enabled() as $moduleKey => $definition) {
            $events = $definition['internal_event_observer_events'] ?? null;
            if (!is_array($events)) {
                throw new RuntimeException('Bundled module event eligibility metadata is invalid.');
            }
            $normalized = [];
            foreach ($events as $declaredEvent) {
                if (!is_string($declaredEvent)
                    || preg_match('/^[a-z][a-z0-9_.]{2,127}$/D', $declaredEvent) !== 1) {
                    throw new RuntimeException('Bundled module event eligibility metadata is invalid.');
                }
                $normalized[$declaredEvent] = true;
            }
            if (isset($normalized[$eventName])) {
                $keys[] = (string) $moduleKey;
            }
        }
        return $keys;
    }

    /**
     * Capture endpoint eligibility inside the source event transaction. This
     * method performs database work only; delivery and secret access are
     * strictly post-commit worker concerns.
     */
    private function captureWebhookDeliveries(
        int $eventId,
        string $eventType,
        int $schemaVersion,
        string $occurredAt,
    ): void {
        $lock = strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql'
            ? ' FOR UPDATE OF subscription'
            : '';
        $eligible = $this->pdo->prepare(
            "SELECT subscription.id, subscription.endpoint_version
             FROM webhook_subscriptions subscription
             JOIN webhook_subscription_events selection
               ON selection.subscription_id = subscription.id
             JOIN domain_event_outbox event
               ON event.id = ? AND event.institution_id = subscription.institution_id
             WHERE subscription.lifecycle_state IN ('active', 'degraded')
               AND selection.event_type = ? AND selection.schema_version = ?
             ORDER BY subscription.id" . $lock,
        );
        $eligible->execute([$eventId, $eventType, $schemaVersion]);
        $subscriptions = $eligible->fetchAll(PDO::FETCH_ASSOC);
        if ($subscriptions === []) {
            return;
        }
        $createdAt = cpe_now();
        $retentionUntil = gmdate('Y-m-d H:i:s', time() + (90 * 86400));
        $insert = $this->pdo->prepare(
            "INSERT INTO webhook_deliveries
             (public_id, subscription_id, event_id, endpoint_version, status, attempt_count,
              available_at, lease_generation, last_error_code, last_failure_reference,
              retention_until, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'pending', 0, ?, 0, '', '', ?, ?, ?)",
        );
        foreach ($subscriptions as $subscription) {
            $insert->execute([
                'whdel_' . bin2hex(random_bytes(16)),
                (int) $subscription['id'],
                $eventId,
                (int) $subscription['endpoint_version'],
                $occurredAt,
                $retentionUntil,
                $createdAt,
                $createdAt,
            ]);
        }
    }

}
