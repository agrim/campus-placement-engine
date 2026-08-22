# Hosted Operations

The hosted edition uses the same PHP release and module code as self-hosting.
Its extra layer is a metadata-only control plane that resolves an exact domain to
one institution data plane. Student, employer, application, advising, and
placement records never belong in the control plane.

## Minimum Shape

- Stateless PHP application instances behind a TLS reverse proxy.
- One PostgreSQL control-plane database.
- One PostgreSQL data-plane database per institution.
- A secret manager that maps each opaque database reference to a connection URL.
- A scheduler for fleet jobs, notifications, and domain-event outbox delivery.
- Encrypted backup storage with retention and restore drills.

Redis, Kubernetes, a message broker, and shared-table multitenancy are not
required. Add them only when measured demand justifies them.

## Configure The Control Plane

```bash
export CPE_HOSTED_MODE=1
export CPE_CONTROL_PLANE_DATABASE_URL='postgresql://USER:PASSWORD@HOST/CONTROL_DATABASE'
php placement hosted-control-migrate
```

Create the tenant metadata before provisioning its data plane:

```bash
php placement hosted-tenant-create \
  --slug=example-college \
  --name='Example College' \
  --domain=careers.example.edu \
  --plan=community \
  --database-ref=EXAMPLE_COLLEGE \
  --region=primary
```

The control plane stores `EXAMPLE_COLLEGE`, not database credentials. Supply the
actual secret only to the application process:

```bash
export CPE_TENANT_DATABASE_URL_EXAMPLE_COLLEGE='postgresql://USER:PASSWORD@HOST/TENANT_DATABASE'
```

Tenant host matching uses the direct HTTP `Host` value and exact active domain
records. Forwarded host headers are deliberately ignored. A missing secret,
unknown domain, inactive tenant, or institution/database identity mismatch fails
closed.

## Provision And Entitle

```bash
export CPE_ADMIN_PASSWORD='use-a-secret-manager-value'
php placement hosted-provision \
  --tenant=example-college \
  --admin-name='Career Services Admin' \
  --admin-email=admin@example.edu

php placement hosted-entitle --tenant=example-college --module=advising --enabled=1
```

Provisioning is idempotent and does not store the supplied administrator
password in the control plane. Plan defaults and explicit entitlements are
applied through the same module lifecycle used by self-hosted installs.

## Fleet Upgrades

```bash
php placement hosted-fleet-plan --version=0.1.0
php placement hosted-fleet-run --limit=10
```

Each claimed job resolves the tenant again, verifies data-plane identity, writes
a verified backup, applies migrations, runs readiness checks, and then marks the
deployment complete. Failure marks the deployment degraded and preserves the
error for operator review. Jobs are idempotent by deployment and target release.

Set `CPE_HOSTED_BACKUP_DIR` to an encrypted mounted backup destination. A local
filesystem path is useful for development; production retention should use an
institution-approved encrypted backup system.

## Support Access

Support grants are explicit, reason-bearing, audited, revocable, and limited to
24 hours:

```bash
php placement hosted-support-grant \
  --tenant=example-college \
  --subject=support-user \
  --reason='Incident 2026-07-15' \
  --expires-at='2026-07-15 18:00:00' \
  --confirm=GRANT

php placement hosted-support-list --tenant=example-college
php placement hosted-support-revoke --grant=GRANT_PUBLIC_ID --confirm=GRANT_PUBLIC_ID
```

A support grant is authorization metadata, not an automatic login bypass. The
hosting layer must still enforce staff authentication and the grant's scope.

## Scheduler Cadence

A simple process scheduler is sufficient to begin:

```text
every minute: php placement work-outbox --limit=100
every minute: php placement deliver-notifications --limit=100
operator/release cadence: php placement hosted-fleet-run --limit=10
```

Run one worker command per tenant data-plane context. Outbox events carry stable
public event IDs so webhook consumers can make delivery idempotent.

## Hosted Release Gates

- PostgreSQL data-plane contract passes from an empty database.
- PostgreSQL control-plane migration contains no operational record tables.
- Unknown domains, missing secrets, and swapped databases fail closed.
- Database sessions cannot cross tenant databases and session tenant binding is
  verified on every request.
- Backup, mutation, and restore drills pass before a release reaches tenants.
- Metrics, structured logs, readiness, outbox backlog, and dead letters are
  monitored.
- Portability export is validated before an institution offboards.
