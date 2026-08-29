<?php

declare(strict_types=1);

namespace App\Core\Events;

use RuntimeException;

/**
 * Immutable, explicitly governed projection from a private domain event into
 * the institution-facing public event catalog.
 *
 * @internal Serialized only through PublicEventEnvelope.
 */
final class PublicEventProjection
{
    public const APPLICATION_STATUS_CHANGED = 'application.status_changed';
    public const APPLICATION_STATUS_CHANGED_SCHEMA = 1;

    private function __construct(
        public readonly string $eventType,
        public readonly int $schemaVersion,
        public readonly string $instanceId,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly int $aggregateVersion,
        public readonly string $payloadJson,
        public readonly string $correlationId,
    ) {
        $this->validate();
    }

    public static function applicationStatusChanged(
        string $instanceId,
        string $applicationId,
        int $aggregateVersion,
        string $fromStatus,
        string $toStatus,
        string $correlationId,
    ): self {
        self::assertStatus($fromStatus, 'from_status');
        self::assertStatus($toStatus, 'to_status');
        if ($fromStatus === $toStatus) {
            throw new RuntimeException('A public application status event requires an actual status change.');
        }
        return new self(
            self::APPLICATION_STATUS_CHANGED,
            self::APPLICATION_STATUS_CHANGED_SCHEMA,
            $instanceId,
            'application',
            $applicationId,
            $aggregateVersion,
            json_encode(
                ['from_status' => $fromStatus, 'to_status' => $toStatus],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
            $correlationId,
        );
    }

    public static function fromStored(
        string $eventType,
        int $schemaVersion,
        string $instanceId,
        string $aggregateType,
        string $aggregateId,
        int $aggregateVersion,
        string $payloadJson,
        string $correlationId,
    ): self {
        return new self(
            $eventType,
            $schemaVersion,
            $instanceId,
            $aggregateType,
            $aggregateId,
            $aggregateVersion,
            $payloadJson,
            $correlationId,
        );
    }

    /** @return array{from_status: string, to_status: string} */
    public function data(): array
    {
        try {
            $data = json_decode($this->payloadJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Public event projection payload is invalid JSON.', 0, $e);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Public event projection data must be an object.');
        }
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['from_status', 'to_status']) {
            throw new RuntimeException('Public application status data has an unsupported key set.');
        }
        $fromStatus = $data['from_status'] ?? null;
        $toStatus = $data['to_status'] ?? null;
        if (!is_string($fromStatus) || !is_string($toStatus)) {
            throw new RuntimeException('Public application status data must contain string status tokens.');
        }
        self::assertStatus($fromStatus, 'from_status');
        self::assertStatus($toStatus, 'to_status');
        if ($fromStatus === $toStatus) {
            throw new RuntimeException('A public application status event requires an actual status change.');
        }
        return ['from_status' => $fromStatus, 'to_status' => $toStatus];
    }

    /** @return array<int, int|string> */
    public function persistenceValues(): array
    {
        return [
            $this->eventType,
            $this->schemaVersion,
            $this->instanceId,
            $this->aggregateType,
            $this->aggregateId,
            $this->aggregateVersion,
            $this->payloadJson,
            $this->correlationId,
        ];
    }

    private function validate(): void
    {
        if ($this->eventType !== self::APPLICATION_STATUS_CHANGED
            || $this->schemaVersion !== self::APPLICATION_STATUS_CHANGED_SCHEMA
            || $this->aggregateType !== 'application') {
            throw new RuntimeException('Public event type or schema is not in the Engine catalog.');
        }
        if (preg_match('/^(?:inst|tenant)_[a-f0-9]{32}$/D', $this->instanceId) !== 1) {
            throw new RuntimeException('Public event instance id is invalid.');
        }
        if (preg_match('/^application_[a-f0-9]{32}$/D', $this->aggregateId) !== 1) {
            throw new RuntimeException('Public event aggregate id is invalid.');
        }
        if ($this->aggregateVersion < 2) {
            throw new RuntimeException('Public event aggregate version must follow the initial version.');
        }
        if (preg_match('/^req_[a-f0-9]{24}$/D', $this->correlationId) !== 1) {
            throw new RuntimeException('Public event correlation id is invalid.');
        }
        $this->data();
    }

    private static function assertStatus(string $status, string $field): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $status) !== 1) {
            throw new RuntimeException('Public event ' . $field . ' is invalid.');
        }
    }
}
