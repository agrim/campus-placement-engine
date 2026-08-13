# CSV Imports

Imports are intentionally plain CSV paste operations. They do not require a
spreadsheet library, background worker, browser-side parsing, or server-side
file upload storage.

The v1 import surface does not accept uploaded files. Operators paste reviewed
CSV text into the form, preview it, and then import it. The parser rejects
oversized input before preview or mutation. Defaults are 5,000,000 bytes and
10,000 non-empty data rows; technical operators can raise or lower those limits
with `CPE_IMPORT_MAX_BYTES` and `CPE_IMPORT_MAX_ROWS` in the server
environment.

## Supported Types

- Candidates
- Companies
- Company rounds
- Round schedule
- Round panelists
- Interview slot assignments
- Candidate unavailable windows
- Shortlists
- Legacy wide table

Templates live in `examples/csv-templates/`.

The legacy wide-table template is synthetic. It exists only to help colleges
translate old `stat1`...`stat11`, `gd_round`, and `gd_panel` style files into
the modern event/round/schedule model. Do not paste historical rows into public
issues or commits.

## Header Aliases

The importer accepts the canonical template headers and common college
spreadsheet variants. Header matching is case-insensitive and ignores spaces,
dashes, and punctuation, so `Student ID`, `student-id`, and `student_id` are
treated the same.

Administrators can add local aliases without editing source by setting
`import_header_aliases_json` in Admin or portable configuration. The value is a
small JSON object from canonical importer fields to local header names:

```json
{"external_id":["Campus UID"],"company_code":["Recruiter Short Code"]}
```

Custom aliases are layered on top of the built-in aliases below. Unknown
canonical fields are rejected during configuration validation.

Representative aliases:

| Canonical field | Accepted examples |
| --- | --- |
| `external_id` / `candidate_external_id` | `Student ID`, `Roll No`, `Registration Number`, `Admission No` |
| `name` | `Full Name`, `Student Name`, `Candidate Name`, `Company Name`, `Panelist Name` |
| `program` | `Programme`, `Branch`, `Department`, `Course`, `Specialization` |
| `tags` | `Tag`, `Labels`, `Cohort`, `Category`, `Segment` |
| `custom_fields_json` | `Custom Fields`, `Fields JSON`, `Local Fields`, `Metadata JSON`, `Extra Fields` |
| `code` / `company_code` | `Company Code`, `Recruiter Code`, `Employer Code`, `Organisation Code` |
| `slot` | `Day Slot`, `Placement Slot`, `Interview Slot` |
| `offer_tier` | `Tier`, `Offer Category` |
| `process_type` | `Process`, `Selection Process` |
| `room` | `Venue`, `Room No`, `Panel Room` |
| `max_active` | `Active Cap`, `Parallel Capacity` |
| `deadline_at` | `Deadline Time`, `Last Time`, `Finish By` |
| `round_sequence` | `Round No`, `Round Number`, `Round Order` |
| `round_label` / `label` | `Round Name`, `Stage`, `Stage Label` |
| `starts_at` / `ends_at` | `Start Time`, `End Time`, `Start`, `End` |
| `schedule_day` | `Day`, `Schedule Day`, `Interview Day` |
| `capacity` | `Seats`, `Slots`, `Room Capacity` |
| `availability_status` | `Availability`, `Panelist Status` |
| `waitlist_rank` | `Rank`, `Waitlist`, `List Rank` |

If two input headers normalize to the same canonical field, the import fails
before preview or mutation. This avoids silently choosing between duplicate
student IDs, company codes, or status columns.

## Preview First

The Import page has a `Preview CSV` action. Preview parses the submitted CSV and
reports:

- Row count.
- Rows that will create records.
- Rows that will update existing records.
- Missing candidates or companies.
- Missing company rounds for schedule or panelist imports.
- Missing applications or round schedules for interview slot assignment imports.
- Missing candidates or invalid `HH:MM` times for candidate unavailable-window
  imports.
- Optional schedule-day labels for multi-day round schedules and assignments.
- Unknown workflow statuses.
- Unknown schedule statuses for round-schedule imports.
- Unknown panelist availability statuses for round-panelist imports.
- Legacy GD panel rows with only one of `gd_round` or `gd_panel`.
- Duplicate keys inside the pasted file.
- Integer-field errors such as waitlist rank, active cap, round sequence, or
  duration.
- Optional candidate `accommodation_notes`, when supplied in candidate imports,
  remain visible on the board, candidate trace, and CSV exports.
- Optional candidate and company `tags`, when supplied, remain visible on board
  cards, searchable in board filters, and available in CSV exports.
- Optional candidate and company `custom_fields_json`, when supplied, must be a
  JSON object with scalar values. It remains searchable on the board, visible in
  records and exports, and can be enabled on board cards through Admin.
- Company deadline fields `deadline_day` and `deadline_at`, when supplied in
  company imports, are exported and used by slot suggestion commands.
- Candidate unavailable windows, when supplied, are exported and treated by the
  slot planner as candidate-level conflicts.

Preview does not write to the application database.

## Import Safety

The `Import CSV` action runs the same validation first. If validation fails, the
page shows the preview report and no rows are imported.

Oversized CSV text is rejected before parsing. This protects low-power local
machines and shared PHP hosts from accidental spreadsheet dumps that are too
large for the v1 paste-based workflow.

When validation passes, the web import runs inside one database transaction. If a
write fails, the transaction rolls back.

Shortlist, company-round, round-schedule, round-panelist, and interview-slot
assignment imports validate references before writing, so a bad later row does
not leave earlier rows partially imported.

Before a valid web import is applied, the app writes a pre-import SQLite
rollback snapshot under `data/imports/`. The Import page lists recent snapshots
and allows administrators or placement-office users to restore one. The CLI can
list or restore the same snapshots:

```bash
php placement rollback-import --list
php placement rollback-import import-YYYYMMDD-HHMMSS-abcdef
```

Rollback restores the whole application database to the pre-import snapshot and
writes a safety copy of the current database first. Use it immediately after a
mistaken import; any changes made after that import will also be undone.

## Legacy Wide Table

Legacy wide-table imports map the highest populated `statN` value to the
current workflow status. When both `gd_round` and `gd_panel` are present and
positive, the importer also creates a synthetic modern company round, round
schedule, and slot assignment:

- `gd_round=2` becomes round sequence `2` with label `GD Round 2`.
- `gd_panel=3` becomes room and schedule sequence `GD Panel 3`.
- `slot` becomes the schedule-day label when present.

Rows with partial GD panel data still import the candidate, company, and
application, but preview warns that no slot assignment can be created.
