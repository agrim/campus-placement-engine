# Bundled Module Development

This is an Engine-contributor guide, not a third-party plugin SDK. The portal is
a modular monolith. A module is first-party PHP code loaded in the same request,
reviewed and released in the same immutable Engine artifact, and covered by the
same compatibility matrix. It is not an uploaded package, independently
versioned extension, or remote service. Placement Operations is the flagship
module; Career Advising is the independent proof module.

The PHP interfaces under `app/Core/Modules/` are internal organization
contracts. They may change with an Engine release and are deliberately excluded
from the public integration compatibility promise. External systems integrate
through documented, out-of-process event and API contracts.

## Ownership Rules

- Core owns institution, people, profiles, organizations, memberships,
  capabilities, settings, audit, events, privacy orchestration, and portability.
- Each module owns its tables, services, controllers, routes, navigation,
  migrations, privacy handler, and portability handler.
- A module must not write another module's tables.
- Cross-module reactions use a core reference service or named domain event.
- Disabling a module hides its routes and jobs but preserves its data.
- Uninstall is explicit, backup-first, and requires export or destructive
  confirmation.

## Manifest And Interfaces

Add the module manifest to `config/modules.php` with a stable lowercase key,
version, required core version, dependencies, and capabilities. Implement the
core module interfaces used by the feature:

- `Module` for identity, routes, and navigation.
- `ProvidesEventSubscribers` for stable, namespaced post-commit observer
  subscriptions.
- `ProvidesPrivacy` for person report and erasure behavior.
- `ProvidesPortability` for versioned logical export, validation, and import.

Register the module class in the manifest. Add capability defaults in
`config/capabilities.php`; controllers must authorize capabilities, not assume a
particular role name.

Every bundled module class declares public `CPE_MODULE_KEY` and
`CPE_MODULE_VERSION` constants. These immutable implementation values must
exactly match the configured key/version and the durable
`module_installations.version`. Core validates them without constructing the
optional module before granting capabilities or recording event eligibility;
drift is an operable module-state outage, never an implicit upgrade.

## Data And Events

Add equivalent ordered migrations under:

```text
database/migrations/
database/migrations/pgsql/
```

Use opaque public IDs at portability and event boundaries. Internal integer keys
may remain local. Dispatch a named event inside the source write transaction:
the immutable outbox row and one eligibility row for each enabled bundled module
whose immutable metadata names that event are committed atomically with the
domain change. No module class, manifest, declaration, or callback is constructed
there. `php placement work-outbox` expands declarations post-commit, then leases
the stable deliveries and invokes observers only after the lease transaction
commits.

Each bundled observer declares an `InternalEventSubscription` with a stable,
namespaced, versioned ID such as `internal.advising.offer_follow_up.v1`. Do not
reuse an ID for different behavior. Observers must be idempotent because
delivery is at least once: a completed side effect may be replayed if its lease
expires before acknowledgement. One observer's retry or dead letter does not
block another observer for the same event. Network calls and irreversible I/O
do not belong in source transactions.

Keep `internal_event_observer_events` in `config/modules.php` exactly aligned
with each module's declarations. Zero or mismatched declarations fail and retry;
they are never silently marked expanded. Operators can recover corrected dead
letters with `replay-internal-fanout` or `replay-internal-delivery`, using the
exact event/module or event/subscription identity and an active operator user ID.
Both operations are transactionally fenced and audit only fixed metadata.

The outbox envelope is `career_services.domain_event.v1`. Its public event ID is
also the stable idempotency key in file and webhook delivery, and webhook
requests send it in `X-CPE-Idempotency-Key`. Consumers must deduplicate on that
key: a successful side effect whose acknowledgement loses its worker claim is
reported as outcome unknown and may be replayed after stale-claim recovery. Add
only stable, necessary references to payloads; do not place passwords, session
IDs, gateway tokens, or unbounded private notes in an event.

## Privacy And Portability

An installed module participates in privacy handling even while disabled. Its
privacy report must identify module-owned records for a core person public ID;
erasure must redact or detach module-owned personal data without corrupting
required aggregate history.

Portability handlers must:

- Version their schema.
- Validate structure, uniqueness, references, and forbidden secret fields before
  mutation.
- Export stable public references rather than source database IDs.
- Import inside the portal-wide transaction.
- Declare exclusions such as sessions, credentials, and delivery state.

Round-trip the module through a fresh target install and both database drivers.

## Frontend Budget

Module pages are server-rendered. Reuse the existing layout, CSS primitives,
forms, tables, and navigation. Do not add a JavaScript framework, package build,
web font, image asset, or client-side state system for ordinary module screens.
JavaScript is reserved for small progressive enhancements; all core workflows
must remain server-operable.

## Definition Of Done

- Fresh SQLite and PostgreSQL migrations pass.
- Enable, disable, and entitlement behavior pass without deleting data.
- Routes and navigation are capability-filtered.
- Event observers have stable subscription IDs, run post-commit, and are
  idempotent under replay.
- Privacy report and erasure are covered.
- Portability validates, rejects tampering, and round-trips.
- No placement internals were modified solely to make the new module work.
- PHP lint, unit/integration tests, HTTP smoke, and mobile/desktop browser checks
  pass.
