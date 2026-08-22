<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Modules\ModuleManager;
use App\Core\Modules\ProvidesEventSubscribers;
use PDO;
use RuntimeException;

final class EventDispatcher
{
    private array $subscribers = [];

    public function __construct(private readonly PDO $pdo, ModuleManager $modules)
    {
        foreach ($modules->modules() as $module) {
            if (!$module instanceof ProvidesEventSubscribers) {
                continue;
            }
            foreach ($module->eventSubscribers() as $eventName => $listeners) {
                foreach ($listeners as $listener) {
                    if (!is_callable($listener)) {
                        throw new RuntimeException('Module event subscriber is not callable: ' . $eventName);
                    }
                    $this->subscribers[$eventName][] = $listener;
                }
            }
        }
    }

    public function dispatch(DomainEvent $event): string
    {
        if (preg_match('/^[a-z][a-z0-9_.]{2,127}$/', $event->name) !== 1) {
            throw new RuntimeException('Invalid domain event name: ' . $event->name);
        }
        $publicId = 'event_' . bin2hex(random_bytes(16));
        $institutionId = $this->pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn();
        if ($institutionId === false) {
            throw new RuntimeException('Cannot publish an event without an institution context.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO domain_event_outbox
             (public_id, event_name, aggregate_type, aggregate_public_id, institution_id, module_key,
              payload_json, occurred_at, available_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $publicId,
            $event->name,
            $event->aggregateType,
            $event->aggregatePublicId,
            (int) $institutionId,
            $event->moduleKey,
            json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $event->occurredAt,
            $event->occurredAt,
        ]);
        foreach ($this->subscribers[$event->name] ?? [] as $listener) {
            $listener($event);
        }
        return $publicId;
    }

    public function pending(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM domain_event_outbox
             WHERE processed_at IS NULL AND available_at <= ?
             ORDER BY id LIMIT {$limit}"
        );
        $stmt->execute([cpe_now()]);
        return $stmt->fetchAll();
    }

    public function markProcessed(int $eventId): void
    {
        $stmt = $this->pdo->prepare('UPDATE domain_event_outbox SET processed_at = ?, attempts = attempts + 1, last_error = ? WHERE id = ?');
        $stmt->execute([cpe_now(), '', $eventId]);
    }

    public function markFailed(int $eventId, string $error): void
    {
        $stmt = $this->pdo->prepare('UPDATE domain_event_outbox SET attempts = attempts + 1, last_error = ? WHERE id = ?');
        $stmt->execute([mb_substr($error, 0, 1000), $eventId]);
    }
}
