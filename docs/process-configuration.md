# Process Configuration

Company records can carry a lightweight process profile. This keeps operational
details configurable without introducing executable extensions, a frontend build, or a
separate scheduling service.

Supported company/process fields:

- `code` - short stable company key.
- `name` - display name.
- `slot` - placement day/slot label.
- `offer_tier` - local policy tier such as dream, core, internship, or lateral.
- `process_type` - interview, test, GD, case, HR, final round, or local wording.
- `room` - room, panel, link, or floor location.
- `tracker_name` - person/team responsible for tracking this process.
- `max_active` - optional active-candidate cap; `0` means unlimited.
- `deadline_day` - optional day label by which this company must finish. Leave
  blank for a same-day time-only cutoff.
- `deadline_at` - optional `HH:MM` finish cutoff. Schedule rows ending after
  this cutoff are skipped by slot suggestion commands.
- `process_notes` - short operator note.

These fields appear on board cards and in the Records maintenance page. CSV
imports can set them with the same column names.

Candidate records also support an optional `accommodation_notes` field. Use it
for public-safe operational notes such as room, floor, seating, timing, or
accessibility constraints that should remain visible on the board and candidate
trace. The field is deliberately free text for v1 so each college can use its
own wording without adding a taxonomy or executable extension.

Companies can also have ordered rounds for process detail:

- `company_code` - existing company code.
- `sequence` - display order, starting at `1`.
- `label` - round label such as case discussion, analytics test, or final PI.
- `round_type` - optional local grouping such as case, test, GD, interview, HR.
- `room` - room, lab, panel, or link for that round.
- `duration_minutes` - optional planned duration; `0` means unspecified.
- `instructions` - short tracker note.

Round rows appear in `Records`, can be imported with the `Company rounds` import
type, and are summarized on board cards as a compact ordered sequence.

Rounds can carry lightweight room/time schedule rows:

- `company_code` - existing company code.
- `round_sequence` - existing round sequence for that company.
- `round_label` - existing round label for that sequence.
- `sequence` - schedule display order.
- `room` - room, lab, panel, or link for this schedule row.
- `schedule_day` - optional day label for multi-day processes. Use a numeric
  day offset such as `1` or `2`, or an ISO date such as `2026-07-01`.
- `starts_at` - optional local start label such as `09:00`.
- `ends_at` - optional local end label such as `09:45`.
- `capacity` - optional active capacity for this room/time row; `0` means
  unspecified.
- `schedule_status` - optional row state: `active`, `paused`, `break`, or
  `cancelled`. Non-active rows remain visible in Records and exports but are
  skipped by slot suggestion commands.
- `notes` - short operational note.

Schedule rows appear in `Records`, can be imported with the `Round schedule`
import type, and are summarized on board cards below the ordered round list.

Specific candidate-company applications can then be assigned to existing
schedule rows:

- `candidate_external_id` - existing candidate external ID.
- `company_code` - existing company code for an existing application/shortlist.
- `round_sequence` - existing round sequence for that company.
- `round_label` - existing round label for that sequence.
- `schedule_sequence` - existing schedule sequence for that round.
- `room` - existing schedule room.
- `schedule_day` - optional existing schedule day. Include it when importing
  multi-day assignments.
- `starts_at` - existing schedule start label; use an empty value only when the
  schedule row has an empty start.
- `assignment_sequence` - display order for the candidate's assigned slots.
- `assignment_status` - local label such as assigned, checked-in, delayed, or
  done.
- `notes` - short operational note.

Assignment rows appear in `Records`, can be imported with the `Interview slot
assignments` import type, and appear on board cards and candidate traces as the
candidate-specific `Slot` line. The app checks that the selected schedule
belongs to the same company as the candidate-company application.

For a read-only planning report, run:

```bash
php placement suggest-slots [COMPANY]
```

The command lists active applications that still need one or more company-round
assignments. For each application, it walks the company's ordered rounds, skips
rounds that already have a non-cancelled assignment, ignores schedule rows whose
`schedule_status` is `paused`, `break`, or `cancelled`, and suggests the first
capacity-safe active schedule row for each missing round. When prior round
assignments have day and `HH:MM` start/end labels, later round suggestions avoid
rows that start before the previous assigned round ends. This supports simple
day spillover, for example a late day-one screen followed by a day-two final.
If a company has `deadline_at` configured, the planner also requires each
suggested schedule row to have a parseable end time that finishes on or before
the company deadline. This models cases where a company has a hard departure or
process stop time.

When `waitlist_rank` is present on applications, lower ranks are planned first.
This supports extended-shortlist operations where list-one candidates must be
served before later lists even if later-list cards were created or updated
earlier.

The planner also avoids candidate-level clashes across companies. If
`scheduling_buffer_minutes` is set in Admin, the report treats each existing or
newly suggested candidate slot as a busy window and requires that many minutes
between slots. For example, a `15` minute buffer means a candidate with a
09:00-09:30 assignment cannot be suggested for a 09:35 slot, even with another
company. It does not create assignments; operators can review the CSV-style
output before importing or entering final assignment rows.

Colleges can also import candidate-specific unavailable windows for exams,
accommodations, travel, or other protected blocks. Use the `Candidate
unavailable windows` import type or
`examples/csv-templates/candidate_unavailability_windows.csv` with:

- `candidate_external_id` - existing candidate/student ID.
- `label` - short reason shown in planner block messages.
- `schedule_day` - optional day/slot label matching schedule rows.
- `starts_at` and `ends_at` - `HH:MM` local times.
- `notes` - optional operator note.

Unavailable windows are non-mutating constraints for the planner. They do not
move the candidate through the workflow and they do not create interview
assignments; they only stop `suggest-slots`, `optimize-slots`, `assign-slots`,
and `assign-optimized-slots` from choosing overlapping schedule rows.

Admins can also choose a `slot_planner_strategy`:

- `sequence` keeps the default schedule-row order.
- `earliest` chooses the earliest safe `HH:MM` start among available rows.
- `balanced` chooses the least-loaded safe row before falling back to start time
  and sequence.

After reviewing that report, technical operators can apply only the safe
suggested rows with:

```bash
php placement assign-slots [COMPANY]
```

`assign-slots` creates candidate-level assignment rows only when a schedule row
is available under the current round order and capacity rules. Rows without
schedules, rows blocked by paused/break/cancelled schedules, rows blocked by
capacity, rows blocked by earlier round timing, or rows blocked by
candidate-level cross-company buffer conflicts are reported as skipped instead
of being forced. Each created assignment is audited and carries the note
`Auto-assigned from slot suggestion.`

For a more global non-mutating pass across active companies, run:

```bash
php placement optimize-slots [COMPANY]
```

The optimized report uses the same schedule safety rules, but it chooses across
all active companies by constrainedness. For small scopes, it runs a bounded
exact search that maximizes the number of safe assignments while respecting
round order, schedule capacity, panelist availability, day/time order, and
company deadlines, and candidate-level buffer conflicts. This exact pass can
search several ordered rounds together, so it can choose an earlier first-round row when a
sequence-first later row would block the next round. The Admin setting
`slot_optimizer_exact_limit` controls that exact search size and defaults to
`10`; set it to `0` to disable the exact pass. Larger scopes fall back to the
transparent greedy pass, where candidates with fewer active company options and
rows with fewer safe schedule choices are served first. After review, apply that
report with:

```bash
php placement assign-optimized-slots [COMPANY]
```

This keeps optimization dependency-free and transparent enough for operators to
audit while still handling common small one-round and multi-round cases exactly.

Rounds can also carry a lightweight panel roster:

- `company_code` - existing company code.
- `round_sequence` - existing round sequence for that company.
- `round_label` - existing round label for that sequence.
- `sequence` - panelist display order.
- `name` - panelist name.
- `role` - optional role such as lead, observer, coordinator, or interviewer.
- `affiliation` - optional company, college, or team label.
- `contact` - optional contact note for operators.
- `availability_status` - `active`, `break`, or `unavailable`.
- `notes` - short operational note.

Panelist rows appear in `Records`, can be imported with the `Round panelists`
import type, and are summarized on board cards below the ordered round list. If
at least one panelist is configured for a round, the slot planner requires one
panelist to remain `active`; rounds whose configured panelists are all on
`break` or `unavailable` are reported as blocked instead of receiving slot
suggestions.

Administrators can configure non-operating weekdays such as `sat,sun` and
specific non-operating ISO dates such as `2026-01-26` in Admin, the browser
installer, CLI install, or portable configuration snapshots. `php placement
readiness` and the browser `System` page warn when a round schedule lands on
one of those configured days. Numeric schedule days such as `1` and `2` are
resolved from the configured cycle start date; if no cycle start date is set,
readiness asks operators to set one before weekday rules can be checked.

`php placement readiness` and the browser `System` page also warn when a
company has more active applications than its configured `max_active` value.
