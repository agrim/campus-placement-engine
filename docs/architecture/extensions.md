# Extensions and integrations

Status: accepted boundary

## The short version

The Engine is a dependency-light modular monolith. Its source-bundled modules
are internal product components, not a public in-process extension ABI. Third-party systems
extend the product out of process through versioned events and APIs.

This keeps an institution's placement workflows usable when an optional
integration is unavailable and avoids granting uploaded code access to the PHP
process, filesystem, database, sessions, secrets, or privileged templates.

## Vocabulary

- **Module:** an Engine-owned product component shipped, reviewed, migrated,
  tested, and released with the Engine artifact.
- **Integration:** an institution-visible connection to another system.
- **Connector:** an out-of-process software package that implements an
  integration through documented event and API contracts with explicit
  permissions.

## Trust boundary

Engine core owns domain invariants and may reject a transaction. Bundled
modules may contribute routes, views, capabilities, migrations, privacy and
portability handlers, deterministic core guards, and post-commit observers
through internal interfaces.

External integrations may not:

- execute PHP or JavaScript in the Engine process;
- scan or load code from writable directories;
- install Composer packages from an administrator page;
- register migrations outside the Engine migration registry;
- access the database, internal services, sessions, filesystem, or templates;
- inject HTML or JavaScript into privileged pages;
- perform network or other irreversible work inside a core transaction.

## Public extension surfaces

The governed public surfaces are introduced in dependency order. The current
contract in `contracts/public-integration.v1.json` provides:

1. `application.status_changed` schema 1, delivered at least once through the
   existing outbox sinks;
2. institution-local API v1 for opportunity/application reads and one
   controlled application-status transition;
3. broader out-of-process connector capabilities only after their public
   contracts are governed.

API v1 uses the Phase 3A disabled-by-default service-account, exact-scope,
verifier-only token, rate-limit, and redacted-audit controls. Its five GET/HEAD
paths, one application-transition POST, exact projections/request, shared
domain policy, schemas, and compatibility rules are documented in
`docs/api/v1.md` and `docs/api/authentication.md`. Candidate resources and all
other command/write APIs remain outside the public surface.

Internal PHP class names, database tables, templates, and module subscriber
interfaces are not public contracts. The public compatibility policy is
versioned independently from the Engine release and fails closed on unknown
future contract versions. Private outbox payloads are never public by inference;
external delivery requires a complete explicit public projection.

## Engine and Cloud ownership

The Engine and its institution database own integration endpoints, API
credentials, signing secrets, event payloads, delivery attempts, mapping state,
and integration audit. Cloud may own connector catalog identity, entitlement,
immutable artifact digests, desired deployment generation, opaque installation
references, and aggregate health only.

Cloud never proxies ordinary placement API traffic and never stores candidate,
employer, application, interview, advising, offer, or placement payloads.
Managed connector execution belongs in a tenant-isolated data-plane runtime,
not a Cloud control-plane worker.

## Explicit non-goals

The project does not load uploaded PHP or JavaScript, publish an in-process
extension ABI, provide a public module marketplace, introduce a generic workflow language,
enable GraphQL by default, require a
message broker, arbitrary privileged UI slots, or an exactly-once delivery
claim. Executable extension packages remain deferred unless stable events and
APIs prove insufficient and a signing, revocation, migration, isolation,
compatibility, and support program is funded.
