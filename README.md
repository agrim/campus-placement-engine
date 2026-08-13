# Campus Placement Engine

A lightweight placement-day operations engine for colleges, and the first
flagship module of an open-source Career Services Portal.

The same application now supports a downloadable self-hosted edition and the
data plane for a managed hosted service without forking placement behaviour. The accepted
architecture and staged implementation gates are documented in
[docs/career-services-portal-architecture.md](docs/career-services-portal-architecture.md).

This modernization is intentionally dependency-light:

- Plain PHP.
- SQLite by default; PostgreSQL is supported for hosted and larger deployments.
- Server-rendered HTML.
- Vanilla CSS and tiny vanilla JavaScript.
- No framework, Node build, Redis, external queue service, image assets, or
  database server required for the default self-hosted install.

## Run Locally

Requirements:

- PHP 8.2 or newer.
- `pdo_sqlite` and `sqlite3` PHP extensions.
- A writable `data/` directory.

On macOS, if PHP is missing or too old, the maintainable local install path is:

```bash
brew install php
```

```bash
php placement serve
```

Open `http://localhost:8000/` and complete the installer.
The browser installer shows the same PHP, SQLite, and writable-directory
preflight as `php placement doctor`, guides college identity, cycle, workflow,
terminology, admin, and dummy-data choices, and will not install until required
checks are passing. The default browser setup can start with a fully live
synthetic placement drive. Use it to learn the board, then clear dummy data from
System before importing actual data. After the database has an `installed_at`
marker, the installer is locked; use a different `CPE_DB_PATH` for a fresh setup
instead of rerunning it over live data. `php placement serve` is just a small
wrapper around `php -S 127.0.0.1:8000 -t public`; no extra server dependency is
introduced.

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
php placement doctor
php tests/run.php
```

`doctor` is the local preflight gate. It prints `OK` or `ERROR` for PHP,
SQLite, and writable data/database locations, and exits non-zero when a true
system requirement is missing. `INFO installed: no` is normal before the first
setup run. The first-run browser installer uses the same checks.

## Useful CLI Commands

```bash
php placement doctor
php placement migrate
php placement upgrade
php placement serve [127.0.0.1:8000]
php placement install --college="College" --cycle-name="Final Placements 2026" --admin-name="Admin" --admin-email=admin@example.test
php placement seed-demo
php placement seed-large-demo [candidate-count] [company-count]
php placement readiness
php placement metrics
php placement placement-report
php placement privacy-report
php placement anonymize-candidate C001 --confirm=C001
php placement privacy-person-report --person=PERSON_PUBLIC_ID
php placement privacy-person-erase --person=PERSON_PUBLIC_ID --reason='Retention period ended' --confirm=PERSON_PUBLIC_ID
php placement backup
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
php placement verify-package /path/to/campus-placement-engine-version.tar.gz
php placement deliver-notifications [--channel=file|webhook|email|sms|whatsapp] [--dry-run]
php placement work-outbox [--limit=100]
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

Backups are complete SQLite copies or PostgreSQL custom-format dumps and get a
matching `.sha256` checksum sidecar. Restore requires that sidecar, verifies it,
and creates a safety backup first. Keep both files out of Git and encrypt
off-machine copies with institution-approved storage or archive tooling.

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
- `app/Hosted/` - metadata-only hosted control plane, tenant resolution, provisioning, and fleet operations.
- `app/` - controllers, domain logic, installer, imports, and security helpers.
- `config/` - default app and workflow configuration.
- `database/` - SQLite/PostgreSQL data-plane migrations and control-plane migrations.
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
The separate [hosted environment example](examples/env/hosted.env.example)
keeps advanced SaaS variables out of the normal college setup path.
For hosted tenant provisioning, entitlements, and fleet upgrades, see
[docs/hosted-operations.md](docs/hosted-operations.md). For tested backup and
restore procedures, see [docs/disaster-recovery.md](docs/disaster-recovery.md).
For module boundaries and extension rules, see
[docs/module-development.md](docs/module-development.md). For health, metrics,
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
