<?php

declare(strict_types=1);

namespace App\Core\Events;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Exact public wire envelope. Private domain-event fields never enter it.
 *
 * @internal This producer type is not an institution-facing PHP API.
 */
final class PublicEventEnvelope
{
    private function __construct(
        private readonly string $eventId,
        private readonly string $occurredAt,
        private readonly PublicEventProjection $projection,
    ) {
        if (preg_match('/^event_[a-f0-9]{32}$/D', $eventId) !== 1) {
            throw new RuntimeException('Public event id is invalid.');
        }
    }

    /** @param array<string, mixed> $row */
    public static function fromOutboxRow(array $row): self
    {
        foreach ([
            'public_id',
            'public_event_type',
            'public_schema_version',
            'occurred_at',
            'public_instance_id',
            'public_aggregate_type',
            'public_aggregate_id',
            'public_aggregate_version',
            'public_payload_json',
            'public_correlation_id',
        ] as $required) {
            if (!array_key_exists($required, $row) || $row[$required] === null) {
                throw new RuntimeException('Public outbox row is missing an explicit projection field.');
            }
        }
        $projection = PublicEventProjection::fromStored(
            (string) $row['public_event_type'],
            self::strictInteger($row['public_schema_version'], 'schema version'),
            (string) $row['public_instance_id'],
            (string) $row['public_aggregate_type'],
            (string) $row['public_aggregate_id'],
            self::strictInteger($row['public_aggregate_version'], 'aggregate version'),
            (string) $row['public_payload_json'],
            (string) $row['public_correlation_id'],
        );
        return new self(
            (string) $row['public_id'],
            self::rfc3339Utc((string) $row['occurred_at']),
            $projection,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->projection->eventType,
            'schema_version' => $this->projection->schemaVersion,
            'occurred_at' => $this->occurredAt,
            'instance_id' => $this->projection->instanceId,
            'aggregate' => [
                'type' => $this->projection->aggregateType,
                'id' => $this->projection->aggregateId,
                'version' => $this->projection->aggregateVersion,
            ],
            'data' => $this->projection->data(),
            'trace' => ['correlation_id' => $this->projection->correlationId],
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function strictInteger(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            return (int) $value;
        }
        throw new RuntimeException('Public event ' . $label . ' is invalid.');
    }

    private static function rfc3339Utc(string $value): string
    {
        $timezone = new DateTimeZone('UTC');
        $format = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1
            ? '!Y-m-d H:i:s'
            : (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) === 1
                ? '!Y-m-d\TH:i:s\Z'
                : '');
        if ($format === '') {
            throw new RuntimeException('Public event occurrence time is invalid.');
        }
        $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException('Public event occurrence time is invalid.');
        }
        return $date->setTimezone($timezone)->format('Y-m-d\TH:i:s\Z');
    }
}
