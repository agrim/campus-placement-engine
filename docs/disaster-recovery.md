# Disaster Recovery

Backup and portability solve different problems. A database backup is the
fastest exact recovery artifact for the same deployment. A logical portability
bundle is the reviewed, versioned path between supported installations. The
current version supports self-hosted-to-self-hosted moves only; hosted target
identity remains platform-owned and fail-closed.

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
produces a custom-format `.pgdump` using `pg_dump`. Each archive receives a
versioned `.metadata.json` sidecar containing its driver, permanent database
owner and ownership-contract version, immutable institution public ID, Engine
version, archive SHA-256, and creation time. The adjacent `.sha256` file binds
both archive and metadata. Keep all three files together.

Before upgrades and destructive imports, the app writes a backup automatically.
Copy production backups to encrypted institution-controlled storage; the
checksum proves integrity, not confidentiality.

### Public alpha.1 backup conversion

Public `v0.1.0-alpha.1` SQLite backups used a single-entry `.sha256` file and
predate checksum-bound identity metadata. Current restore validation does not
silently weaken itself for those archives. Preserve both original files and
create a new converted archive:

```bash
php placement convert-legacy-backup /secure/path/to/old.sqlite \
  --confirm=CONVERT --target-dir=/secure/path/to/converted
```

Conversion rejects symbolic links, oversized or malformed files, a mismatched
legacy checksum, incomplete/mixed schema, and invalid installed identity. It
opens the actual source read-only, runs SQLite integrity validation, exclusively
creates a new archive, adopts permanent `engine_institution` ownership only in
that copy, and writes current metadata plus the two-entry checksum. It never
overwrites or mutates the original. Legacy PostgreSQL dumps cannot be converted
this way; restore them only into an isolated database, validate identity and
ownership there, then create a new current-format backup.

## Restore

Put the application into maintenance mode and stop web/worker writes first.
Then run:

```bash
php placement restore /secure/path/to/backup.sqlite
# or
php placement restore /secure/path/to/backup.pgdump
```

Before creating a safety backup or mutating the target, restore verifies both
checksum entries and metadata, then requires an exact driver, permanent owner,
ownership-contract, and installed institution identity match. SQLite opens the
actual backup read-only, runs `PRAGMA integrity_check`, and compares its owner,
installed marker, and institution identity with the metadata. PostgreSQL runs
`pg_restore --list` to establish custom-archive structural readability before
direct restore, then verifies the restored identity. This structural preflight
is not an isolated staging restore or recovery drill. SQLite atomically replaces
the database file; all old PHP/PDO processes must be stopped because they retain
handles to the old file. PostgreSQL uses
`pg_restore --clean --if-exists --single-transaction` and requires
`CPE_DATABASE_URL` plus `pg_restore`.

After restore:

```bash
php placement doctor
php placement readiness
php placement metrics
php placement smoke-http --base-url=https://careers.example.edu
```

The first mutating command after restore verifies that
`cpe_database_ownership` still identifies an `engine_institution` database at
contract version 1. This row is permanent recovery evidence. A backup from a
Cloud control plane, a mixed database, a partial legacy database without a safe
signature, a corrupt singleton, or a newer ownership contract must fail closed.
Never delete or rewrite the row to make a restore start. Keep the restored copy
offline, preserve its checksum and safety backup, confirm the source database
and release contract, and recover forward into a correctly owned target.

Migration recovery is serialized independently under
`cpe.engine-migrations`. Each SQL file and its `migrations` row commit together,
so a process crash may leave the registry table itself present but cannot leave
only one side of a file/row pair. After confirming the database owner and backup,
rerun the same pinned Engine release; it rechecks every discovered filename and
resumes only unrecorded files. If the final synchronizer failed, the committed
registry remains authoritative and the synchronizer is retried on the next run.

Stopping a PHP migration worker does not itself prove that its PostgreSQL
backend session has ended. A long-running server statement can keep the session
advisory lock until PostgreSQL observes the disconnect or the backend is
cancelled or terminated. Configure a bounded `statement_timeout` appropriate to
the institution's largest migration. During recovery, identify the exact
backend PID and application identity in `pg_stat_activity`; follow the database
operator's cancel/terminate policy and confirm that session is gone before
retrying. Never delete a registry row or attempt to unlock another session as a
substitute for ending the failed migration backend.

Verify institution identity, administrator access, workflow version, module
state, candidate/application counts, recent transitions, notification backlog,
and advising records before reopening writes.

## Import Rollback

CSV imports create pre-import snapshots. Inspect and restore them with:

```bash
php placement rollback-import --list
php placement rollback-import IMPORT_ROLLBACK_ID
```

Alpha.1 import manifests may contain historical absolute backup paths. Current
code ignores those directories and resolves only a strictly validated backup
basename inside the configured import rollback directory. `--list` reports
`legacy_conversion_required`; after placing the preserved archive and checksum
in that directory, convert it explicitly with:

```bash
php placement rollback-import IMPORT_ROLLBACK_ID \
  --convert-legacy --confirm=CONVERT
```

The manifest is rewritten with only the new safe artifact reference, preserved
legacy basename, and conversion audit time. The original snapshot remains
unchanged. Restore the same rollback ID only after conversion succeeds.

For an alpha.1 PostgreSQL import rollback, `--list` recognizes only the strict
`.pgdump` basename inside the configured import directory and reports
`legacy_postgres_isolated_validation_required`; it never displays or follows
the absolute directory stored by the old manifest. Direct restore and
`--convert-legacy` both fail with fixed guidance because metadata-free
PostgreSQL archives cannot be identity-validated in place. Preserve the archive
and old checksum, restore the dump into an isolated PostgreSQL database, verify
its schema, ownership, installed marker, and institution identity there, then
create a new current-format backup before any target restore decision.

An import rollback is still a whole-database restore. Stop concurrent writes and
rebind any long-lived process after it completes.

## Portability Recovery

Version 1 logical portability is for moving data between installed self-hosted
`inst_...` targets without transferring the installation identity:

```bash
php placement bundle-export /secure/path/to/new-bundle
php placement bundle-validate /secure/path/to/new-bundle
php placement bundle-import /secure/path/to/new-bundle --confirm=IMPORT
```

The target must be installed and operational placement tables must be empty.
Import preserves the target institution public ID and creation time, creates a
safety backup, validates file sizes/checksums and module versions, restores
module data, then applies core institution configuration. A hosted `tenant_...`
target requires an exact bundle identity match; the current `inst_...` bundle
schema therefore rejects self-hosted bundle injection into hosted targets.

## Drill Cadence

- Monthly for active hosted tenants and before every release: restore into a
  disposable database and run readiness plus HTTP smoke.
- Before each live placement period: create a backup, verify its checksum, and
  confirm the named restore operator.
- After a real incident: preserve logs and the pre-restore safety backup, record
  timing against RPO/RTO, and update the runbook.

A backup that has never been restored is only a hypothesis.
