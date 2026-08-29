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

}
