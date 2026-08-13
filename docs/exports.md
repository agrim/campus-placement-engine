# CSV Snapshot Exports

Backups and exports serve different purposes.

- `php placement backup` creates a driver-appropriate database backup and writes a
  `.sha256` checksum sidecar.
- `php placement export` writes portable CSV snapshots for audit, reporting,
  upgrade checks, and handoff.

Backups are complete local databases, not share-safe files. Encrypt them with
the institution's approved storage/archive tooling before moving them off the
operator machine, and keep each `.sqlite` or `.pgdump` file with its `.sha256`
sidecar.

## Command

```bash
php placement export
```

By default this creates:

```text
data/exports/snapshot-YYYYMMDD-HHMMSS/
```

You can choose a target directory:

```bash
php placement export /path/to/export-folder
```

The target directory must be empty.

You can also choose a fixed export profile:

```bash
php placement export --profile=summary
php placement export /path/to/export-folder --profile=operations
php placement export /path/to/export-folder --profile=custom
```

Profiles:

- `full`: complete local audit snapshot, including users, notifications, audit
  logs, workflow/settings rows, operational data, and aggregate summary CSVs.
- `operations`: placement-day operational records without account tables,
  notification deliveries, settings, workflow overrides, or audit logs.
- `summary`: aggregate report CSVs only, without candidate-level rows.
- `custom`: datasets selected by the `export_profile_custom_datasets` setting.
  Administrators can edit the comma-separated dataset list in Admin, or carry
  it through portable configuration snapshots. Unknown dataset names are
  rejected before export.

Common dataset names include `placement_totals`, `application_status_counts`,
`placements_by_company`, `candidates_by_program`, `candidates_by_location`,
`candidates`, `companies`, `applications`, `events`, `round_schedules`,
`round_panelists`, and `application_slot_assignments`.

## Files

The full export writes `manifest.csv` plus CSVs for summary and operational
data:

- placement totals
- application status counts
- placements by company
- candidate counts by program
- candidate counts by location
- settings
- users, without password hashes
- user board preferences, including saved filters, compact mode, and stale
  threshold
- in-app notifications
- external notification delivery status and payloads, without delivery targets
- candidates, including tags, custom fields, accommodation notes, and
  anonymization markers
- companies, including process metadata, tags, and custom fields
- company rounds
- round schedules
- round panelists, including availability status
- interview slot assignments
- applications, including previous/next company codes for handoff route audits
- movement events
- preference requests and options
- wanted alerts
- audit logs
- workflow overrides

Exports use readable keys where possible, such as candidate external IDs,
company codes, and actor emails.

When `audit_request_metadata` is enabled, `full` export audit logs include the
retained IP address and/or user-agent values. `operations` and `summary`
exports do not include audit logs.

Do not publish real exports in the open-source repository.

Exports are point-in-time files. Candidate anonymization affects the live SQLite
database after it runs; it does not rewrite CSV exports or backups that were
created earlier. Apply your institution's retention policy to old export
folders.
