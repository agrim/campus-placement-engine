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
  `HostedBootstrap` as the versioned integration seam.

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

Self-hosted installations do not set either variable and follow the normal fixed
institution path.
