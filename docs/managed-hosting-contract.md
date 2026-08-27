# Managed-hosting contract

Campus Placement Engine is independently installable and has no dependency on a
hosting platform. A separate management plane can host the same Engine release
through this deliberately small contract.

## Public Engine responsibilities

- Placement product behaviour, UI, modules, privacy, and data-plane migrations.
- SQLite and PostgreSQL connection providers.
- Institution-scoped sessions, identity checks, portability, readiness, backups,
  health, metrics, logs, and transactional outbox delivery.
- `App\Hosted\Tenant\TenantResolver`, `ResolvedTenant`, `HostedContext`, and
  `HostedBootstrap` as the tenant-resolution portion of the versioned
  integration seam.
- `App\Core\Modules\ModuleLifecycleService` and the release's
  `config/modules.php` capability manifest as the module-lifecycle portion of
  that seam.
- `App\Install\Installer::installHosted()` as the sole atomic first-install
  identity-binding operation.
- `App\Core\Persistence\DatabaseOwnership` as database-role contract version 1.

Hosted and self-hosted first installation share the distinct
`cpe.engine-installation` lock after ownership and migrations finish. A hosted
provisioner may race or retry, but only one installation transaction can bind
the immutable tenant identity, administrator, settings, audit marker, and
`installed_at`; later contenders receive the stable already-installed conflict.

## Database ownership

Before the Engine creates `migrations` or any institution table,
`DatabaseOwnership::claimOrVerify()` claims or verifies the singleton
`cpe_database_ownership` row as `engine_institution`. The table is runtime
contract DDL, not a numbered product migration. Its `owner_kind`, contract
version, and canonical UTC `claimed_at` value are permanent identity evidence;
there is no force, rebind, relabel, or automatic repair path.

Contract version 1 reserves exactly two owner values: `engine_institution` and
`cloud_control_plane`. A fresh database or a complete, unambiguous legacy
Engine signature may be claimed for the Engine. Partial, mixed, unknown, or
Cloud signatures fail closed before an ownership table or Engine migration
table is created. Once a valid row exists, the same owner may resume a partial
schema, but any marker reserved by the opposite plane is an incident.

Claims use the fixed `cpe.database-ownership` lock namespace. PostgreSQL uses a
database-identity-derived, session advisory lock; the connection must preserve
one backend session through checked unlock. Ownership is physical-database-wide,
not `search_path`-local: every non-system PostgreSQL schema is inspected for the
ownership relation and reserved Engine or Cloud markers, and a database may have
at most one canonical application-schema ownership table. Cross-schema owner
tables, markers, or attempts to claim the same database through another
`search_path` fail closed. File-backed SQLite uses a persistent adjacent lock
file derived from the canonical database path and then `BEGIN IMMEDIATE`;
in-memory SQLite uses `BEGIN IMMEDIATE`. Attached non-temporary SQLite databases
and ownership calls made inside an active transaction are rejected.

Operational failures expose stable identifiers:

- `CPE_DATABASE_OWNERSHIP_CONFLICT` for the other owner or opposite markers.
- `CPE_DATABASE_OWNERSHIP_AMBIGUOUS` for partial, mixed, or unknown legacy state.
- `CPE_DATABASE_OWNERSHIP_CORRUPT` for an invalid singleton or table contract.
- `CPE_DATABASE_OWNERSHIP_VERSION_UNSUPPORTED` for a newer or invalid version.

A management plane must consume these symbols and semantics from a pinned
Engine release. It must claim its own control-plane database as
`cloud_control_plane` before `hosted_migrations`, and must let this Engine claim
each institution data plane as `engine_institution`. It must never copy the
implementation, edit ownership rows, or treat a conflict as permission to
relabel a database.

## SQL migration serialization

`App\Core\Persistence\SqlMigrationRunner` contract version 1 is the generic
Engine-owned migration primitive. It validates a dedicated registry and accepts
only readable, non-symlinked regular files named `NNN_lower_snake.sql`, with a
unique nonzero sequence. Unexpected entries that claim the `.sql` suffix fail
closed; dialect child directories and other non-SQL entries are ignored. The
runner sorts those files lexically, acquires a caller-specific database lock,
then re-reads applied filenames. Top-level transaction-control statements are
forbidden because transaction ownership belongs to the runner; SQL comments,
literals, PostgreSQL dollar-quoted bodies, and SQLite trigger bodies are parsed
without treating their contents as transaction control. Every pending file and
registry insert is one
transaction on PostgreSQL and file-backed SQLite. Fileless SQLite uses one
`BEGIN IMMEDIATE` outer transaction without nesting. Final registry proof and
the optional post-migration callback remain inside the lock.

The institution Engine uses registry `migrations` and lock namespace
`cpe.engine-migrations`, after the separate `cpe.database-ownership` claim.
These namespaces are contract identifiers and must not be replaced with each
other or with a tenant mutation lock. `Database::migrate(false)` releases the
migration lock before the installer starts its atomic installation transaction.
Post-migration callbacks run without a runner-owned transaction on PostgreSQL
and file-backed SQLite; an open transaction left by a callback is rolled back
and rejected. Callback failure does not falsify committed migration history and
a later run retries the callback while holding the lock.

## Management-plane responsibilities

- University accounts, tenants, domains, plans, billing, and lifecycle.
- Database and secret provisioning.
- Resolver registration, wildcard routing, TLS, workers, fleet releases,
  monitoring, support operations, and offboarding.

The management plane may not copy or fork Engine product behaviour. Its control
plane stores metadata and opaque secret references, never candidate, employer,
application, advising, or placement records.

## Runtime registration

`HostedBootstrap::CONTRACT_VERSION` identifies the compatibility contract. A
platform provides an implementation of `TenantResolver` and registers it from an
operator-controlled bootstrap file:

```bash
export CPE_HOSTED_MODE=1
export CPE_PLATFORM_BOOTSTRAP=/absolute/path/to/platform/bootstrap.php
```

The bootstrap file is loaded only from the absolute path configured by the
server operator. If hosted mode is enabled without a resolver, an unknown domain
is requested, a database secret is absent, or the resolved database identity
does not match the tenant, the request must fail closed.

`GET /health.php` remains public process liveness and does not load this
bootstrap or resolve a tenant. When hosted mode is enabled or a platform
bootstrap path is configured, `GET /health.php?ready=1` and `GET /metrics.php`
require the same `CPE_METRICS_TOKEN` of at least 24 characters in an exact HTTP
`Authorization: Bearer ...` header. Authorization completes before the
bootstrap is loaded or the tenant `Host` is inspected. Missing, malformed,
invalid, or weakly configured credentials receive the same concealed 404 text
response. A real management-plane proxy must preserve the monitor's
`Authorization` header and the intended tenant `Host`; query parameters,
cookies, source IP, and forwarded identity headers never authorize probes.
The managed-hosting HTTP contract sends a bounded concurrent/repeated set of
missing, malformed, and wrong-token readiness and metrics requests across known
and unknown hosts. It requires one concealed 404 signature and zero platform
bootstrap, resolver, or provider work. This is authorization-under-load
evidence, not a capacity or scale benchmark.

Self-hosted installations do not set either variable and follow the normal fixed
institution path.

Contract version 2 removes the former post-install identity-rebinding method.
This is a deliberate breaking seam change: an Engine release containing version
2 must be published before the management plane updates its immutable Engine pin
and consumer validation. Until that future pin update is deployed, the
management plane must remain on its compatible Engine release; Engine and
management-plane contract versions must not be mixed during provisioning.

## Hosted installation identity

`Installer::HOSTED_INSTALL_CONTRACT_VERSION` versions the hosted installation
boundary. Fresh migrations mark the default institution identity explicitly
unbound. `installHosted(array $input, string $tenantPublicId)`
compare-and-sets that marker to the control plane's immutable tenant public ID
in the same database transaction as the installation marker. A crash therefore
cannot leave a database that looks installed but has not yet been bound to its
tenant, and a second tenant cannot rebind the database.

A management plane must call this operation only for an uninstalled, dedicated
data plane while holding its tenant mutation lock. Retries against an installed
database must use `HostedBootstrap::assertDataPlaneIdentity()`; a mismatched
installed database must never be rebound automatically.

Before any installation ownership claim or migration, Engine performs a strict
read-only installed-marker probe. A missing settings relation may continue to
first installation; an existing installed marker returns the stable installed
conflict, and any other probe failure fails closed. Checked database-lock
release or session-integrity failures are typed, and cached connections are
discarded only after the lock boundary has attempted release.

The install transaction has fixed, data-free observation stages after settings,
identity binding, administrator creation, optional demo seed, synchronizers,
the installed marker, and the install audit. The release atomicity contract
interrupts each stage and proves that the reserved unbound institution row and
all other durable state roll back exactly. It then proves a complete retry.
For an uncertain response after a possible commit, the caller first checks
`Database::isInstalled()` and verifies the same tenant with
`HostedBootstrap::assertDataPlaneIdentity()`; it does not rerun installation.

Contract version 2 has no public post-install binding or rebinding operation.
`installHosted()` is the only path that may bind a tenant identity, and it may do
so only while completing a fresh installation transaction. An existing
self-hosted `inst_...` identity and an installed `tenant_...` identity are both
immutable. Provisioning retries must use `assertDataPlaneIdentity()` to verify a
completed installation; same-tenant verification is read-only, while a
different tenant, malformed tenant identity, or self-hosted identity fails
closed without mutation. There is no force, relabel, or tenant-to-tenant rebind
path.

## Module lifecycle

`ModuleLifecycleService::CONTRACT_VERSION` versions these public, idempotent
Engine operations. Version 1 includes:

- `ModuleLifecycleService::modules()` returns the release capability manifest
  together with installed, configured, entitled, and effective state.
- `ModuleLifecycleService::enable(string $moduleKey, ?int $actorId = null)`
  installs and enables a known module after its dependencies are enabled.
- `ModuleLifecycleService::disable(string $moduleKey, ?int $actorId = null)`
  disables a known module while retaining its institution data.

The same operations back `php placement module-list`, `module-enable`, and
`module-disable` for self-hosted operators. A management plane must activate a
verified `HostedContext` for the intended institution before calling them, obey
manifest dependency order, and record desired state separately from observed
convergence. It may not edit module tables directly or carry a private copy of
module behavior.

Contract consumers must pin an immutable Engine release and verify the contract
version, service class, public method signatures, and capability manifest before
provisioning or reconciliation. Additive modules may appear in later compatible
releases; a consumer must fail closed on module keys unknown to its pinned
release. Breaking signature or lifecycle-semantics changes require a managed
hosting contract version increment.
