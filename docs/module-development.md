# Module Development

The portal is a modular monolith. A module is first-party PHP code loaded in the
same request, not an uploaded package or remote service. Placement Operations is
the flagship module; Career Advising is the independent proof module.

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
- `ProvidesEventSubscribers` for named event handlers.
- `ProvidesPrivacy` for person report and erasure behavior.
- `ProvidesPortability` for versioned logical export, validation, and import.

Register the module class in the manifest. Add capability defaults in
`config/capabilities.php`; controllers must authorize capabilities, not assume a
particular role name.

## Data And Events

Add equivalent ordered migrations under:

```text
database/migrations/
database/migrations/pgsql/
```

Use opaque public IDs at portability and event boundaries. Internal integer keys
may remain local. Emit a named event after the source transaction's durable
state exists. Subscribers must be idempotent because an event can be retried or
replayed.

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
- Event subscribers are idempotent.
- Privacy report and erasure are covered.
- Portability validates, rejects tampering, and round-trips.
- No placement internals were modified solely to make the new module work.
- PHP lint, unit/integration tests, HTTP smoke, and mobile/desktop browser checks
  pass.
