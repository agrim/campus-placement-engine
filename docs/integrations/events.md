# Public integration events

Status: public integration contract v1

The Engine currently publishes one institution-facing event:
`application.status_changed`, schema version `1`. The catalog is frozen in
`contracts/public-integration.v1.json`; the producer schemas, example, and
consumer fixtures live beside it under `contracts/`.

This surface is deliberately narrower than the Engine's internal event system.
`DomainEvent`, module observer declarations, and their payloads are private
in-process implementation details. They are not integration contracts and must
not be read from the database or forwarded by a connector. A public event exists
only when the source transaction writes all eight explicit `public_*` projection
columns. Rows created before that projection existed, ordinary private events,
and partially populated rows are never eligible by inference.

## Envelope v1

The wire object has exactly these fields:

```json
{
  "event_id": "event_11111111111111111111111111111111",
  "event_type": "application.status_changed",
  "schema_version": 1,
  "occurred_at": "2026-08-29T12:00:00Z",
  "instance_id": "inst_22222222222222222222222222222222",
  "aggregate": {
    "type": "application",
    "id": "application_33333333333333333333333333333333",
    "version": 2
  },
  "data": {
    "from_status": "idle",
    "to_status": "scheduled"
  },
  "trace": {
    "correlation_id": "req_444444444444444444444444"
  }
}
```

`occurred_at` is RFC 3339 UTC. `instance_id` is the installed institution's
canonical public ID. The aggregate ID is the application's canonical public ID,
never its database key. Aggregate version `1` is the initial state and emits no
event; every actual status change advances the version once and publishes that
new version. A same-status edit, a workflow-version-only migration, seed data,
or a new application emits nothing.

The data object contains only the old and new status tokens. The envelope never
contains candidate or company identity, names, contact data, notes, actor role,
transition keys, confidential fields, or internal numeric IDs.

## Delivery and consumer rules

Run `php placement work-outbox` from one or more schedulers. Configure at most
one external destination with `CPE_DOMAIN_EVENT_OUTBOX_PATH` or
`CPE_DOMAIN_EVENT_WEBHOOK_URL`; the environment and network controls are in
`docs/environment.md` and `docs/security-operations.md`. If neither is set, the
worker records `delivered_to=internal`, meaning no out-of-process copy was made.

Delivery is at least once. A connector must make side effects idempotent and
deduplicate by `event_id`. Retry serialization preserves the immutable event ID,
occurrence time, public projection, and content. A lost acknowledgement can
therefore repeat an already completed external side effect.

Ordering is per application aggregate, not global:

- a later public aggregate version is not claimed while an earlier version for
  that same application is unresolved;
- retrying or dead-lettered earlier work blocks later versions for that
  application until an operator resolves it;
- unrelated applications may progress independently;
- no ordering relationship is promised between different applications.

Consumers must accept the fields they understand, ignore unknown optional
fields, reject an unsupported `schema_version`, and fail closed on an event type
not granted by their compatibility declaration. The Engine v1 producer remains
strict and emits only the frozen schema. See `docs/compatibility.md`.

## Dead-letter recovery

Investigate the sink failure and preserve incident evidence before requeueing an
event. Recovery targets one exact governed public event and one active local
administrator:

```text
php placement replay-public-event --event=event_ID --actor-user-id=USER_ID
```

The command rejects private, legacy, pending, delivered, unknown, and actively
leased rows. It also rejects a missing, inactive, nonexistent, or non-admin
actor: local shell access does not authorize attributing recovery to another
user. It locks the matching dead letter, validates its stored public projection
without reading the private payload, resets only delivery state, and writes one
fixed payload-free audit row with the administrator actor. Repeating the same
unchanged request is idempotent. The immutable `event_id`, occurrence time,
aggregate version, and envelope content do not change.

A later version for the same application remains blocked while the replay is
pending and becomes eligible only after the replayed version succeeds. A later
delivery failure may dead-letter the exact event again and requires a new
investigated operator recovery. No replay-specific schema migration is needed:
the outbox delivery state remains authoritative and the existing durable audit
log is the replay marker.

## Backup, restore, and stream continuity

Logical portability bundles preserve each application's positive
`aggregate_version`; older bundles without that field restore it as `1`.
Portability import inserts state directly and never manufactures status events.

A point-in-time database restore or logical portability restore is a stream
continuity break. It may return aggregate versions and delivery state to an
earlier snapshot. After any restore, stop the connector, reconcile current
institution state, reset its checkpoint or deduplication state as appropriate,
and perform a connector resynchronization before resuming delivery. A backup or
restore drill must not claim uninterrupted event ordering.
