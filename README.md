# Campus Placement Engine

A lightweight placement-day operations engine for colleges, and the first
flagship module of an open-source Career Services Portal.

## Download and install

Download the newest self-hosted alpha ZIP and its checksum from
[GitHub Releases](https://github.com/agrim/campus-placement-engine/releases),
extract it, and run:

```bash
php placement setup
```

Open the address printed in the terminal and enter the one-time setup code shown
there. The guided setup checks the system, then helps you configure your institution, placement cycle, workflow,
terminology, first administrator, and optional synthetic sample data. See
[INSTALL.md](INSTALL.md) for local and university-hosting instructions.

This public repository is the complete downloadable, self-hosted product. It
also exposes a small versioned integration contract through which a separate
management plane can run the same Engine release as a hosted service without
forking placement behaviour. The accepted architecture and staged implementation gates are documented in
[docs/career-services-portal-architecture.md](docs/career-services-portal-architecture.md).

This modernization is intentionally dependency-light:

- Plain PHP.
- SQLite by default; PostgreSQL is supported for hosted and larger deployments.
- Server-rendered HTML.
- Vanilla CSS and tiny vanilla JavaScript.
- No framework, Node build, Redis, external queue service, image assets, or
  database server required for the default self-hosted install.

## Requirements and manual local start

Requirements:

- PHP 8.2 or newer.
- `mbstring`, `pdo_sqlite`, and `sqlite3` PHP extensions.
- A writable `data/` directory.

On macOS, if PHP is missing or too old, the maintainable local install path is:

```bash
brew install php
```

For an uninstalled checkout, start the authorized local setup server with
`php placement setup`, then open `http://localhost:8000/install.php`.
The browser installer shows the same PHP, SQLite, and writable-directory
preflight as `php placement doctor`, guides college identity, cycle, workflow,
terminology, admin, and dummy-data choices, and will not install until required
checks are passing. The default browser setup can start with a fully live
synthetic placement drive. Use it to learn the board, then clear dummy data from
System before importing actual data. After the database has an `installed_at`
marker, the installer is locked; use a different `CPE_DB_PATH` for a fresh setup
instead of rerunning it over live data. `php placement setup` accepts only a
loopback address and prints a strong one-time setup code to the trusted local
terminal. Enter that code on the unlock-only browser page within 20 minutes.
The code is passed to the child server only through its environment and is never
placed in a command argument, URL, redirect, form default, session, or database.
Local terminal access is therefore part of this setup trust boundary.
`php placement serve` is the ordinary post-install development server and does
not grant browser setup authority.

For a technical first-run setup from CLI:

```bash
CPE_ADMIN_PASSWORD='change-this-password' php placement install \
  --college='Example College' \
  --site-name='Placement Desk' \
  --cycle-name='Final Placements 2026' \
  --candidate-label='Student' \
  --company-label='Recruiter' \
  --admin-name='Placement Admin' \
  --admin-email=admin@example.edu
```

For a synthetic demo install:

```bash
php placement install-demo
php placement serve
```

Demo login:

- `admin@example.test`
- `password123`

Additional demo users use the same password:

- `control@example.test`
- `atlas@example.test`
- `mobile@example.test`
- `floor@example.test`
- `placement@example.test`
- `auditor@example.test`

For a larger throwaway QA dataset:

```bash
php placement seed-large-demo 80 10
```

This resets only reserved synthetic IDs (`QACxxx` candidates and `QAxx`
companies) and is meant for local board, readiness, and slot-suggestion stress
testing.

## Test

```bash
php placement setup --check
php placement doctor
php tests/run.php
php tests/public_event_contract.php
php tests/webhook_delivery_contract.php
php tests/webhook_delivery_concurrency_contract.php
php tests/webhook_revoke_completion_concurrency_contract.php
php tests/webhook_capture_revoke_concurrency_contract.php
php tests/webhook_receiver_example_contract.php
php tests/api_identity_contract.php
php tests/api_identity_rotation_concurrency_contract.php
php tests/api_http_contract.php
php tests/install_concurrency_contract.php
php tests/setup_authorization_contract.php
```

`doctor` is the local preflight gate. It prints `OK` or `ERROR` for PHP,
SQLite, and writable data/database locations, and exits non-zero when a true
system requirement is missing. `INFO installed: no` is normal before the first
setup run. The first-run browser installer uses the same checks.

## Useful CLI Commands

```bash
php placement setup [127.0.0.1:8000] [--check]
php placement doctor
php placement migrate
php placement upgrade
php placement serve [127.0.0.1:8000]
php placement install --college="College" --cycle-name="Final Placements 2026" --admin-name="Admin" --admin-email=admin@example.test
php placement seed-demo
php placement seed-large-demo [candidate-count] [company-count]
php placement readiness
php placement metrics
php placement api-status
php placement api-service-account-create --name=NAME --scopes=opportunities.read,applications.read,applications.transition --actor-user-id=USER_ID
php placement api-token-rotate --service-account=apisa_ID --actor-user-id=USER_ID
php placement api-token-revoke --token-id=LOOKUP_ID --actor-user-id=USER_ID
php placement api-enable --actor-user-id=USER_ID
php placement api-disable --actor-user-id=USER_ID
php placement api-prune --actor-user-id=USER_ID
php placement placement-report
php placement privacy-report
php placement anonymize-candidate C001 --confirm=C001
php placement privacy-person-report --person=PERSON_PUBLIC_ID
php placement privacy-person-erase --person=PERSON_PUBLIC_ID --reason='Retention period ended' --confirm=PERSON_PUBLIC_ID
php placement backup
php placement convert-legacy-backup /path/to/alpha1-backup.sqlite --confirm=CONVERT
php placement restore /path/to/app.sqlite
php placement rollback-import --list
php placement config-export [target-json]
php placement config-validate /path/to/config.json
php placement config-import /path/to/config.json
php placement bundle-export [/path/to/new-bundle-directory]
php placement bundle-validate /path/to/bundle
php placement bundle-import /path/to/bundle --confirm=IMPORT
php placement export [target-directory] [--profile=full|operations|summary|custom]
php placement package --target=dist --force
php placement verify-package /path/to/campus-placement-engine-version.zip
php placement deliver-notifications [--channel=file|webhook|email|sms|whatsapp] [--dry-run]
php placement work-outbox [--limit=100]
php placement work-integrations [--limit=100]
php placement replay-webhook-delivery --delivery=whdel_ID --actor-user-id=USER_ID
php placement replay-public-event --event=event_ID --actor-user-id=USER_ID
php placement replay-internal-delivery --event=event_ID --subscription=internal.module.name.v1 --actor-user-id=USER_ID
php placement replay-internal-fanout --event=event_ID --module=MODULE_KEY --actor-user-id=USER_ID
php placement certify-notifications --channel=sms|whatsapp [--require-live]
php placement smoke-http [--base-url=http://127.0.0.1:8000] [--restricted-email=atlas@example.test]
php placement load-smoke [--base-url=http://127.0.0.1:8000] [--requests=50] [--concurrency=5]
php placement browser-qa-plan [--base-url=http://127.0.0.1:8000] [--format=text|markdown]
php placement suggest-slots [COMPANY]
php placement optimize-slots [COMPANY]
php placement assign-slots [COMPANY]
php placement assign-optimized-slots [COMPANY]
php placement publication-check
```

Backups are complete SQLite copies or PostgreSQL custom-format dumps. Each has
a versioned `.metadata.json` identity sidecar, and its `.sha256` file binds both
the archive and metadata. Restore checks driver, ownership contract, and exact
installed institution identity before creating a safety backup or changing the
target. Keep all three files out of Git and encrypt off-machine copies with
institution-approved storage or archive tooling.

For PostgreSQL, the pre-restore check is structural only: `pg_restore --list`
does not prove the archive's institution rows. Exact archive identity is
confirmed after the direct restore unless an operator first performs a full
restore into an isolated staging database. Use the isolated drill for release
and disaster-recovery evidence; do not describe the structural preflight as a
restore drill.

The anonymous public results page exposes aggregate placement counts only. It
does not publish candidate names or IDs. Candidate-specific status and trace
pages require an authenticated placement role with the corresponding
capability.

CSV imports are paste-only in v1. The app stores no uploaded files and rejects
oversized CSV text before preview or import.

Before live operations, Admin can freeze configuration changes separately from
placement decisions. `configuration_freeze` blocks settings, workflow override
edits, and `config-import`; `placement_freeze` blocks non-admin placement
decisions.

## Project Shape

- `public/` - web entrypoints and tiny static assets.
- `app/Core/` - shared portal contracts, module lifecycle, events, privacy, and portability.
- `app/Modules/` - Placement Operations and Career Advising module ownership.
- `app/Hosted/` - managed-hosting integration contract and tenant-bound runtime context.
- `app/` - controllers, domain logic, installer, imports, and security helpers.
- `config/` - default app and workflow configuration.
- `contracts/` - governed institution-facing integration declarations, schemas, examples, and consumer fixtures.
- `database/` - SQLite/PostgreSQL institution data-plane migrations.
- `examples/config-templates/` - portable starter configuration JSON files.
- `data/` - local runtime database files, ignored by Git.
- `docs/` - modernization and deployment notes.
- `.legacy-private/` - ignored historical archive quarantine.

For live operations, start with [docs/live-day-runbook.md](docs/live-day-runbook.md).
For Apache, Nginx, shared-hosting, and release-package deployment, see
[docs/deployment.md](docs/deployment.md) and
[examples/deployment](examples/deployment).
For supported environment variables and local secret-file boundaries, see
[docs/environment.md](docs/environment.md) and
[examples/env/local.env.example](examples/env/local.env.example).
For the stable boundary used by a separate managed service, see
[docs/managed-hosting-contract.md](docs/managed-hosting-contract.md). Managed
tenant provisioning, billing, infrastructure, entitlements, and fleet operations
are intentionally not part of this public repository. For tested backup and
restore procedures, see [docs/disaster-recovery.md](docs/disaster-recovery.md).
For module boundaries and extension rules, see
[docs/architecture/extensions.md](docs/architecture/extensions.md) and the
Engine-contributor-only [docs/module-development.md](docs/module-development.md).
For the public integration contract, event delivery semantics, and schema
compatibility rules, see [docs/integrations/events.md](docs/integrations/events.md)
and [docs/compatibility.md](docs/compatibility.md). For the administrator
workflow, one-time secrets, exact signing headers, Connector verification,
retry/replay, SSRF policy, and worker operations, see
[docs/integrations/webhooks.md](docs/integrations/webhooks.md). `DomainEvent`
and bundled module PHP interfaces remain private Engine implementation details.
The disabled-by-default institution-local service-account/token boundary is
documented in [docs/api/authentication.md](docs/api/authentication.md). The
opportunity/application read API and its one controlled application-status
transition command, privacy allowlists, ETags, idempotency, cursor recovery,
errors, rate limits, OpenAPI, and self-host/managed parity are in
[docs/api/v1.md](docs/api/v1.md). There are no candidate or other command/write
API endpoints, and Cloud does not proxy ordinary institution API traffic.
For health, metrics,
SSO, sessions, logs, and outbox operations, see
[docs/security-operations.md](docs/security-operations.md).
For the extracted product shape, see [docs/functional-spec.md](docs/functional-spec.md),
[docs/workflow-transition-matrix.md](docs/workflow-transition-matrix.md),
[docs/glossary.md](docs/glossary.md), and
[docs/configuration-architecture.md](docs/configuration-architecture.md).
For company/process setup, including rooms, active caps, ordered rounds,
round schedules, interview slot assignments, candidate unavailable windows, and
panel rosters, see
[docs/process-configuration.md](docs/process-configuration.md).
For Indian-college starter workflow guidance, see
[docs/indian-college-template-notes.md](docs/indian-college-template-notes.md).
Portable starter configuration JSON files live in
[examples/config-templates](examples/config-templates).
For import behavior and CSV templates, see [docs/imports.md](docs/imports.md).
For moving from old spreadsheets, SQL dumps, or a legacy placement app, see
[docs/migration-from-legacy.md](docs/migration-from-legacy.md).
For CSV audit/export snapshots, see [docs/exports.md](docs/exports.md).
For candidate anonymization and opt-in audit request metadata retention, see
[docs/privacy-retention.md](docs/privacy-retention.md).
For optional external notification handoff, see
[docs/notifications.md](docs/notifications.md).
For synthetic placement-day dry-run coverage, see
[docs/dry-run-acceptance-tests.md](docs/dry-run-acceptance-tests.md).
For dense-board browser QA, see [docs/browser-qa.md](docs/browser-qa.md).
For the optional ignored `http/` Apple Container testing server, see
[docs/apple-container-testing.md](docs/apple-container-testing.md).
For publication boundaries, see [docs/legacy-inventory.md](docs/legacy-inventory.md)
and [docs/publication-risk-register.md](docs/publication-risk-register.md).
Before public release, review [SECURITY.md](SECURITY.md),
[CONTRIBUTING.md](CONTRIBUTING.md), and
[docs/release-checklist.md](docs/release-checklist.md).
