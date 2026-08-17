# Disaster Recovery

Backup and portability solve different problems. A database backup is the
fastest exact recovery artifact for the same deployment. A logical portability
bundle is the reviewed, versioned path between supported self-hosted and hosted
installations.

## Decide Before Go-Live

Each institution should record:

- Recovery point objective: how much recent placement activity may be lost.
- Recovery time objective: how long the service may be unavailable.
- Backup frequency, retention, encryption owner, and off-machine destination.
- Who may initiate a restore and who verifies the result.
- Which identity, notification, and gateway secrets must be restored separately.

Secrets, sessions, delivery credentials, and hosted billing metadata are not in
portability bundles.

## Create And Verify A Backup

```bash
php placement backup
```

SQLite produces a consistent `.sqlite` copy using `VACUUM INTO`. PostgreSQL
produces a custom-format `.pgdump` using `pg_dump`. Both receive a `.sha256`
sidecar. Keep the backup and sidecar together.

Before upgrades and destructive imports, the app writes a backup automatically.
Copy production backups to encrypted institution-controlled storage; the
checksum proves integrity, not confidentiality.

## Restore

Put the application into maintenance mode and stop web/worker writes first.
Then run:

```bash
php placement restore /secure/path/to/backup.sqlite
# or
php placement restore /secure/path/to/backup.pgdump
```

Restore requires and verifies the adjacent `.sha256` checksum sidecar, then
writes a safety backup of the current database before mutation. A missing or
mismatched sidecar is rejected. SQLite atomically replaces the database file;
all old PHP/PDO processes must be stopped because they retain handles to the old
file. PostgreSQL uses `pg_restore --clean --if-exists --single-transaction` and
requires `CPE_DATABASE_URL` plus `pg_restore`.

After restore:

```bash
php placement doctor
php placement readiness
php placement metrics
php placement smoke-http --base-url=https://careers.example.edu
```

Verify institution identity, administrator access, workflow version, module
state, candidate/application counts, recent transitions, notification backlog,
and advising records before reopening writes.

## Import Rollback

CSV imports create pre-import snapshots. Inspect and restore them with:

```bash
php placement rollback-import --list
php placement rollback-import IMPORT_ROLLBACK_ID
```

An import rollback is still a whole-database restore. Stop concurrent writes and
rebind any long-lived process after it completes.

## Portability Recovery

Use portability when moving to a new supported installation or database driver:

```bash
php placement bundle-export /secure/path/to/new-bundle
php placement bundle-validate /secure/path/to/new-bundle
php placement bundle-import /secure/path/to/new-bundle --confirm=IMPORT
```

The target must be installed and operational placement tables must be empty.
Import creates a safety backup, validates file sizes/checksums and module
versions, restores module data, then applies core institution configuration.

## Drill Cadence

- Monthly for active hosted tenants and before every release: restore into a
  disposable database and run readiness plus HTTP smoke.
- Before each live placement period: create a backup, verify its checksum, and
  confirm the named restore operator.
- After a real incident: preserve logs and the pre-restore safety backup, record
  timing against RPO/RTO, and update the runbook.

A backup that has never been restored is only a hypothesis.
