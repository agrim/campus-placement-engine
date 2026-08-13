# Migration From Legacy Data

This guide is for colleges moving from old placement spreadsheets, SQL dumps,
or a local legacy placement-day app into Campus Placement Engine.

The rule is simple: migrate concepts and reviewed rows into a fresh install.
Do not publish, commit, or attach historical raw data to the open-source repo.

## Boundary

- Keep legacy archives, SQL dumps, spreadsheets, screenshots, and documents in
  private storage such as `.legacy-private/` or institution-controlled storage.
- Never import legacy password hashes or user sessions. Create fresh users in
  Admin or through the installer.
- Use synthetic examples for public issues, tests, screenshots, and bug reports.
- Treat old CSV/XLSX/SQL files as sensitive even when filenames look generic.

Before touching production-like data, run the local preflight:

```bash
php placement doctor
```

## Recommended Path

1. Create a fresh database with the browser installer or CLI installer.
2. Choose a starter configuration from `examples/config-templates/` or export
   a reviewed configuration from another clean install.
3. Convert legacy files into the current CSV templates under
   `examples/csv-templates/`.
4. Paste each CSV into the Import page and use `Preview CSV`.
5. Import only after preview is clean.
6. Run readiness, reports, exports, and slot suggestions before live use.
7. Take a backup before the final cutover.

For CLI-first setup:

```bash
CPE_ADMIN_PASSWORD='change-this-password' php placement install \
  --college='Example College' \
  --cycle-name='Final Placements 2026' \
  --admin-name='Placement Admin' \
  --admin-email=admin@example.edu
```

## Import Order

Use this order so references exist before dependent rows:

1. `candidates.csv`
2. `companies.csv`
3. `company_rounds.csv`
4. `round_schedules.csv`
5. `round_panelists.csv`
6. `shortlists.csv`
7. `candidate_unavailability_windows.csv`
8. `interview_slot_assignments.csv`
9. `legacy_wide.csv`, only when translating old status-column data

The importer is paste-only in v1. It stores no uploaded files and rejects
oversized pasted text before preview or mutation.

## Field Mapping

Prefer the canonical template headers. The importer also accepts common college
header aliases such as `Student ID`, `Roll No`, `Company Code`, `Venue`,
`Start Time`, `End Time`, `Active Cap`, and `Waitlist Rank`.

If local spreadsheets use different terms, set `import_header_aliases_json` in
Admin or in a portable configuration snapshot. Example:

```json
{"external_id":["Campus UID"],"company_code":["Recruiter Short Code"]}
```

Duplicate normalized headers fail preview instead of guessing.

For local candidate or company columns that do not deserve first-class schema
fields yet, consolidate them into `custom_fields_json` on `candidates.csv` or
`companies.csv`. The value must be a JSON object with scalar values only, for
example a CSV cell like `"{""branch"":""Finance"",""cgpa"":9.1}"`.
These fields remain searchable on the board and are included in snapshot
exports. Avoid storing secrets or sensitive free-form notes there; candidate
custom fields are cleared by candidate anonymization, but exports made before
anonymization remain separate historical files.

## Legacy Wide Rows

Use `legacy_wide.csv` only as a bridge for old files that store movement in
wide status columns such as `stat1`, `stat2`, ... `stat11`.

The importer maps the highest populated `statN` value to the current workflow
status. When both `gd_round` and `gd_panel` are present, it also creates a
synthetic modern round, schedule, and slot assignment:

- `gd_round=2` becomes `GD Round 2`.
- `gd_panel=3` becomes `GD Panel 3`.
- `slot` becomes the schedule-day label when present.

After the migration, use the modern event log, rounds, schedules, and slot
assignments. Do not keep using wide status columns as the operating model.

## Rollback And Safety

Before every valid web import, the app writes a rollback snapshot under
`data/imports/`. You can restore it from the Import page or CLI:

```bash
php placement rollback-import --list
php placement rollback-import import-YYYYMMDD-HHMMSS-abcdef
```

Rollback restores the whole application database from the pre-import snapshot and
writes a safety copy of the current database first. Use rollback immediately
after a mistaken import; changes made after that import are also undone.

Take full database backups at milestones:

```bash
php placement backup
```

Each new backup gets a `.sha256` sidecar. Keep the database and checksum
together, keep them out of Git, and encrypt off-machine copies with
institution-approved tooling.

## Verification

After importing reviewed data, run:

```bash
php placement readiness
php placement placement-report
php placement export --profile=summary
php placement suggest-slots
php placement browser-qa-plan --format=markdown
```

Then open the local app and check:

- Board cards show the right candidates, companies, statuses, locations, and
  movement routes.
- Candidate traces show the expected applications and transition history.
- Records show company rounds, schedules, panelists, slot assignments, and
  candidate unavailable windows.
- Reports match a trusted independent spreadsheet count.
- The System page has no unresolved readiness warnings that matter for the
  planned live day.

## Cutover

Before a real placement day:

- Freeze configuration from Admin once setup is reviewed.
- Run `php placement backup`.
- Run `php placement readiness`.
- Export a summary snapshot for a second reviewer.
- Confirm no raw legacy CSV, SQL, XLSX, DOCX, PDF, ZIP, RAR, 7z, screenshots,
  exports, backups, or runtime SQLite files are staged in Git.
- Keep `.legacy-private/` private and ignored.

If a college needs to preserve old reports for compliance, store them in the
institution's records system, not in this source repository.
