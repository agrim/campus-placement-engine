# Deployment

The Career Services Portal keeps Placement Operations intentionally small at
runtime. The default self-hosted shape is still plain PHP plus SQLite.

## Requirements

- PHP 8.2 or newer.
- `pdo_sqlite` and `sqlite3` extensions for the default SQLite shape.
- A writable `data/` directory.
- No Node.js, Composer runtime, Redis, image assets, or container runtime is
  required. PostgreSQL is optional for hosted or larger deployments.

On macOS, use Homebrew if the bundled/system PHP is missing, too old, or lacks
SQLite support:

```bash
brew install php
```

Check the runtime before installing or deploying:

```bash
php placement doctor
```

The doctor command is intentionally the whole local preflight. In SQLite mode it
checks PHP, extensions, and writable paths. In PostgreSQL mode it checks
`pdo_pgsql` and the configured connection. `INFO installed: no` is expected
before first-time setup. The browser installer displays the same driver-specific
checks and blocks installation until they pass.

CSV imports are paste-based and do not store uploaded files. The default import
limits are 5,000,000 bytes and 10,000 non-empty data rows. Technical operators
can tune them with `CPE_IMPORT_MAX_BYTES` and `CPE_IMPORT_MAX_ROWS` when running
under a controlled PHP server.

Session cookies are HttpOnly, SameSite=Lax, and strict PHP session mode is
enabled. The app marks session cookies `Secure` automatically when it detects
HTTPS. If TLS terminates at a reverse proxy and PHP does not see HTTPS, set
`CPE_SESSION_SECURE=force`. Set `CPE_TRUST_PROXY_HEADERS=1` only when PHP is
reachable exclusively through a trusted proxy that replaces forwarded headers. Use
`CPE_SESSION_SECURE=never` only for local HTTP testing.

Web responses also send conservative browser security headers: a self-only
content security policy, same-origin frame protection, MIME-sniffing
protection, a restrained referrer policy, and disabled browser camera,
microphone, and geolocation permissions. Dynamic responses are also marked
private and no-store. Keep serving the app over HTTPS for real operations;
these headers complement transport security rather than replacing it.

See `docs/environment.md` for all supported environment variables and
`examples/env/local.env.example` for a synthetic local template. Do not commit
real `.env` files or gateway credentials.

## Local Development

```bash
php placement serve
```

Open `http://localhost:8000/` and run the installer.
The installer is intentionally Drupal/WordPress-like in shape but much smaller:
it performs the local system preflight, collects college/text-identity/cycle/
terminology/admin and workflow values, creates the SQLite database, and can
start with a fully live synthetic placement drive. After testing the dummy
board, administrators can clear dummy data from System before importing actual
candidates, companies, rounds, and shortlists.

Once setup writes the `installed_at` marker, the installer is locked. For a
fresh local or staging setup, point `CPE_DB_PATH` at a different SQLite file
instead of rerunning the installer over an existing live database.

`php placement serve` is only a convenience wrapper around:

```bash
php -S 127.0.0.1:8000 -t public
```

Use `php placement serve localhost:8000` or `CPE_SERVE_ADDRESS=localhost:8000`
to choose a different local address.

Apple Container is optional. It is useful for a disposable PostgreSQL service
or a release-like PHP sandbox, but adds no value to the normal edit-run loop over
`php placement serve`. See `apple-container-testing.md`.

## PostgreSQL Data Plane

Use PostgreSQL when operating the hosted edition or when an institution has
already chosen to operate a database server:

```bash
export CPE_DATABASE_URL='postgresql://USER:PASSWORD@DB_HOST/CAREER_SERVICES'
php placement doctor
php placement install --college='Example College' --admin-name='Admin' --admin-email=admin@example.edu
```

The target database must be empty for first install. Use a dedicated database
and least-privilege application role. PostgreSQL backup/restore also requires
`pg_dump` and `pg_restore`; Homebrew's `libpq` binaries are detected in their
standard locations, or set explicit binary paths from `environment.md`.
First-run installation is transactional after migrations and validates an IANA
timezone such as `Asia/Kolkata`; the installer lock is written only after the
administrator, optional demo data, portal kernel, and workflow setup succeed.

Do not use shared schemas or a `tenant_id` column as the hosted isolation model.
Each hosted institution resolves to its own PostgreSQL database. See
`hosted-operations.md`.

## Apache Or Shared PHP Hosting

Preferred Apache setup: point the virtual host document root at `public/`.
That keeps `app/`, `config/`, `database/`, `data/`, tests, and documentation
outside the web root.

A starter Apache virtual host lives at:

```text
examples/deployment/apache-vhost.conf
```

The package includes layered `.htaccess` safeguards:

- `public/.htaccess` disables directory listings, keeps the front controller
  working, and denies accidental database/log/config-like files if they appear
  under `public/`.
- root `.htaccess` is a convenience fallback for shared hosts that cannot point
  directly at `public/`. Use it only when Apache honors `.htaccess` and
  `mod_rewrite`; a real `public/` document root is cleaner.
- `data/.htaccess`, `database/.htaccess`, and `tests/.htaccess` deny direct
  access if a shared host exposes the package root despite the preferred
  document-root layout.

After copying files to an Apache host, run `php placement doctor` from SSH if
available, then open the browser installer. After installation, direct visits
to the installer redirect to the app and installer service calls refuse to
mutate the installed database.

## Nginx With PHP-FPM

Preferred Nginx setup also uses `public/` as the web root. A starter server
block lives at:

```text
examples/deployment/nginx-server.conf
```

Adjust `server_name`, filesystem paths, and `fastcgi_pass` for the PHP-FPM
socket or host used by the server. Keep `data/` writable by the PHP user but
outside the Nginx root.

## CLI Install

Technical operators can also run the same first-run setup without opening the
browser installer:

```bash
CPE_ADMIN_PASSWORD='change-this-password' php placement install \
  --college='Example College' \
  --cycle-name='Final Placements 2026' \
  --cycle-type=final \
  --admin-name='Placement Admin' \
  --admin-email=admin@example.edu
```

Optional flags:

- `--site-name='Placement Desk'`
- `--site-tagline='Live operations'`
- `--timezone=Asia/Kolkata`
- `--cycle-name='Final Placements 2026'`
- `--cycle-type=final` where the value is `final`, `internship`, `lateral`,
  `pooled`, `job_fair`, or `other`
- `--cycle-start-date=2026-01-10`
- `--cycle-end-date=2026-01-12`
- `--non-operating-weekdays=sat,sun`
- `--non-operating-dates=2026-01-26,2026-08-15`
- `--audit-request-metadata=none` where the value is `none`, `ip`,
  `user_agent`, or `both`
- `--workflow=default`
- `--candidate-label=Student`
- `--candidates-label=Students`
- `--company-label=Recruiter`
- `--companies-label=Recruiters`
- `--seed-demo` to load the same live dummy placement drive used by the browser
  installer.

## CLI Demo Install

```bash
php placement install-demo
php placement serve
```

Demo login:

- Email: `admin@example.test`
- Password: `password123`

Additional demo users use the same password:

- `control@example.test`
- `atlas@example.test`
- `mobile@example.test`
- `floor@example.test`
- `placement@example.test`
- `auditor@example.test`

## Data

The default SQLite database is `data/app.sqlite`. `php placement backup` writes
a consistent timestamped SQLite copy; PostgreSQL mode writes a custom-format
dump. Both receive a `.sha256` sidecar. `php placement restore` verifies an
required sidecar and writes a restore-safety backup before changing the live
database. A missing or mismatched sidecar is rejected. Stop concurrent writes
first. See `disaster-recovery.md`.

The app also ships CLI helpers:

```bash
php placement readiness
php placement privacy-report
php placement backup
php placement upgrade
php placement restore /path/to/app.sqlite
php placement rollback-import --list
php placement config-export /path/to/config.json
php placement config-validate /path/to/config.json
php placement config-import /path/to/config.json
php placement export --profile=summary
php placement export --profile=custom
php placement deliver-notifications --dry-run
```

Restore creates a safety copy of the current database before replacing it. Web
imports create pre-import rollback snapshots under `data/imports/`; use
`rollback-import --list` to inspect them. Configuration exports write share-safe
JSON without people or operational records. Validate configuration JSON before
importing it; configuration imports create a safety copy under `data/config/`.
Portable configuration can also carry local CSV header aliases through
`import_header_aliases_json`, text-only site identity through `site_name`,
`site_tagline`, `public_placements_title`, and `candidate_status_title`, and
local terminology labels through the `terminology_*_label` settings. It can also
carry non-operating weekday/date guardrails through
`calendar_non_operating_weekdays` and `calendar_non_operating_dates`, and audit
metadata retention policy through `audit_request_metadata`, so colleges can
adapt spreadsheet, UI, calendar, and audit/privacy behavior without editing PHP.
Export writes portable CSV snapshots under `data/exports/` by default and does
not include password hashes.

Use `php placement upgrade` after replacing the application files with a newer
release. It checks driver-specific requirements, writes an upgrade backup,
applies migrations, and prints readiness checks.

Candidate anonymization writes safety copies under `data/privacy/` and redacts
candidate identity while preserving aggregate placement history. See
`docs/privacy-retention.md`.

Before live placement operations, run:

```bash
php placement backup
php placement readiness
php placement export --profile=summary
```

Use the browser `System` page for the same readiness checks. See
`docs/live-day-runbook.md` for the operating cadence.

Backups contain the complete local placement database, including candidate,
company, account, audit, and notification data. Keep `data/backups/` out of Git,
copy live-day backups to institution-controlled storage, and encrypt them with
the college's approved disk, archive, or backup tooling before moving them off
the operator machine. Keep the `.sqlite` file and matching `.sha256` sidecar
together so restore can verify integrity.

The live board refreshes with a plain HTML meta refresh on board pages only.
The default interval is 45 seconds. Administrators can tune
`board_refresh_seconds` from Admin or through portable configuration snapshots;
set it to `0` to disable automatic refresh.

Before a live placement day, administrators can enable `configuration_freeze`
from Admin. While enabled, settings, workflow override edits, and
`config-import` are blocked until an administrator unfreezes configuration. This
is separate from `placement_freeze`, which controls placement-decision
transitions.

From another terminal, run a dependency-free HTTP smoke against the running
local server:

```bash
php placement smoke-http --base-url=http://localhost:8000
```

The smoke signs in with demo-style credentials unless `--email`, `--password`,
`CPE_SMOKE_EMAIL`, or `CPE_SMOKE_PASSWORD` are supplied. When the install has a
non-admin user available, add `--restricted-email` and
`--restricted-password` so the same smoke also confirms sensitive pages redirect
away from restricted roles.

Company process fields such as room, tracker, process type, active cap, and
ordered rounds can be maintained through `Records` or imported from CSV. See
`docs/process-configuration.md`.

Use the Import page's `Preview CSV` action before bulk imports. Web imports run
inside one database transaction after validation. See `docs/imports.md`.

## Operations Endpoints And Workers

Use `/health.php` for liveness and `/health.php?ready=1` for readiness. Protect
`/metrics.php` with `CPE_METRICS_TOKEN`; it intentionally returns 404 when the
token is absent or invalid. Configure the scheduler to run any enabled
notification handoff and `php placement work-outbox`. See
`security-operations.md`.

Use `php placement export` after major placement-day milestones or before
upgrades when a readable CSV audit trail is useful. See `docs/exports.md`.

## Release Package

Build a publication-safe source archive with:

```bash
php placement package --target=dist --force
```

The package command writes `campus-placement-engine-<version>.tar.gz` and uses an
allowlist of public app, config, migration, doc, example, test, and CI files. It
includes `data/.gitkeep` but excludes runtime SQLite files, backups, exports,
`.legacy-private/`, `config/local.php`, symbolic links, and local browser-QA
scratch directories. Packaging runs the publication check first. Verification
also rejects unsafe paths, multiple archive roots, duplicate entries, and
oversized expanded content before extraction.
It also writes a matching `.sha256` sidecar. Keep the archive and sidecar
together when moving the release package between machines.

Verify the package before publishing or installing it elsewhere:

```bash
php placement verify-package dist/campus-placement-engine-0.1.0.tar.gz
```

Before publishing a package, extract it into a clean temp directory and run:

```bash
export CPE_DB_PATH="$(mktemp -t cpe-package-smoke).sqlite"
php placement doctor
CPE_ADMIN_PASSWORD='password123' php placement install \
  --college='Package Smoke College' \
  --admin-name='Package Admin' \
  --admin-email=package-admin@example.test
php placement readiness
php placement export /tmp/cpe-package-export
```

The automated test suite performs this extracted-package smoke with isolated
temporary paths.

For legacy spreadsheets, SQL dumps, or old placement apps, follow
`docs/migration-from-legacy.md` before importing real institutional data into a
fresh install.

If external notification handoff is enabled, run
`php placement deliver-notifications --dry-run` before live operations and run
`php placement deliver-notifications` from the operator machine or a simple cron
cadence during the day. See `docs/notifications.md`.
