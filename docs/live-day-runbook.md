# Live-Day Runbook

This runbook keeps operations deliberately small: one PHP app, one SQLite file,
one backup folder, and one browser-accessible health page.

## Before Opening The System

Run:

```bash
php placement doctor
php placement backup
php placement readiness
php placement placement-report
php placement export
php placement suggest-slots
php placement deliver-notifications --dry-run
```

For local rehearsal or browser QA, use a throwaway database and add:

```bash
php placement seed-large-demo 80 10
```

The large seed resets only reserved synthetic IDs (`QACxxx` candidates and
`QAxx` companies), so it should not be used against real placement-day data
unless the team intentionally wants to replace those synthetic QA rows.

Confirm:

- PHP, SQLite, `data_writable`, and `database_directory_writable` show `OK`.
- The app is installed.
- A fresh backup exists.
- A fresh CSV export exists if the team needs human-readable audit snapshots.
- The placement report counters match the team's expected opening baseline.
- The slot suggestion report has no unexpected unassigned/capacity-constrained
  applications if the team is using interview slot assignments.
- If non-operating weekday/date guardrails are configured, no round schedule
  should land on those days unless the placement team has accepted the warning.
- If optional external notification handoff is enabled, the dry-run delivery
  report has no unexpected queued or failed rows.
- If SMS or WhatsApp handoff is enabled, the gateway configuration readiness
  check is `OK`, and the manual provider/consent/live-probe checklist in
  `php placement certify-notifications --channel=sms|whatsapp` has been
  completed by the institution.
- If the placement team accepts the suggested safe slot assignments, a technical
  operator can run `php placement assign-slots [COMPANY]` before opening the
  board. Review skipped rows instead of forcing them.
- Workflow configuration is valid.
- Configuration freeze is enabled once setup is final.
- Active users include the expected operators.

In the browser, sign in as an administrator and open `System` and `Reports`.
The live-day readiness table should not show `FAIL`. Warnings are allowed only
if the placement team has consciously accepted them.

## During Placement Operations

- Use the role-specific boards for the actual operator work.
- Watch `Notifications` for in-app wanted and preference notices targeted to
  the current operator role.
- If a local JSONL/webhook notification handoff is enabled, run
  `php placement deliver-notifications` from the operator machine or a simple
  cron cadence.
- Use board filters to narrow by candidate, company, status, operational flag,
  or actionable queue. The filter form is server-side and safe to reload.
- Use the saved board view links for common queues such as actionable, wanted,
  stale, outbound, arrivals, inside panels, or compact role-specific queues.
- Use `Save as my default` when an operator should reopen directly into the
  same filter/compact view on that browser session's account. The adjacent
  stale-minute field tunes when that operator's board marks active items stale.
- Configure company process type, room, tracker, active-candidate cap, and
  ordered rounds before opening each slot.
- Confirm round schedule rows, panelists, and room handoffs before sending
  candidates into each company process.
- Confirm candidate-specific interview slot assignments when a company is
  running parallel rooms or staggered rounds.
- Use `php placement suggest-slots [COMPANY]` for a read-only planning report
  before applying `php placement assign-slots [COMPANY]`.
- Control room users should watch requested, request-away, sent, wanted, and
  preference items.
- Acknowledge in-app notifications after the operator role has seen and taken
  ownership of the notice.
- Company trackers are restricted to their assigned company scope; server-side
  checks block cross-company moves even if an application id is guessed.
- Board move buttons carry the status shown on the card. If another operator
  has already moved that application, the stale click is rejected and the user
  should wait for the next board refresh or reload the board.
- Board move and return forms carry one-time idempotency keys. If a browser
  retries a submit or an operator double-clicks, the duplicate request is
  ignored instead of moving the candidate again.
- Use `Return to idle` for explicit exception cases such as company refusal,
  candidate unavailability, process pauses, or preference decisions that remove
  a candidate from one active company queue. The action writes an event and
  audit entry, resets the candidate location to control room, and cancels active
  slot assignments for that application.
- Mobile and floor trackers should keep candidate location movement current.
- Placement-office users should handle policy-sensitive moves and placement
  closure.
- When a candidate is placed and offer upgrades are disabled, the app
  automatically returns that candidate's other active company applications to
  idle and records cleanup events. Review the candidate trace if another team
  expected the candidate to remain active elsewhere.
- The board uses a lightweight pausable refresh countdown configured in Admin.
  The default is 45 seconds; each operator can pause/resume it, and setting it
  to `0` disables automatic refresh.

## Backup Cadence

For small local deployments, the simplest safe cadence is:

- Run `php placement backup` immediately before opening live operations.
- Run it again after each major slot/day boundary.
- Run `php placement export` after each major slot/day boundary when a
  spreadsheet-readable snapshot is useful.
- Run it before bulk imports, restores, or workflow changes.
- Preview every CSV import before committing it.
- Copy the configured backup directory off the host machine after the placement
  day closes. Keep each archive with its `.metadata.json` and `.sha256` sidecars.
- Encrypt off-machine backups using the institution's approved disk, archive,
  or backup tooling. The app deliberately does not ship a custom encryption
  format or secret-management layer for v1.

Avoid restoring while operators are actively making changes. Restore creates a
safety copy only after required checksum-bound identity metadata and archive
structure validate, but it still replaces the live database state.

## If Something Looks Wrong

Run:

```bash
php placement readiness
```

Then check the browser `System` page:

- `Workflow configuration` finds broken or unreachable transition setup.
- `Latest backup` tells you whether a recent backup exists.
- `Open wanted alerts` means a candidate needs floor/control follow-up.
- `Open preference requests` means a backloop decision is pending.
- `Open in-app notifications` means role-targeted notices still need operator
  acknowledgement.
- `External notification gateway configuration` warns when enabled SMS or
  WhatsApp handoff settings cannot pass the local certification preflight.
- `Stale active applications` points to candidates stuck in an active stage for
  more than 90 minutes in readiness checks. Board operators can save their own
  stale-minute threshold for local stale markers and stale board filters.
- `Active company conflicts` means one candidate is active with more than one
  company and needs control-room resolution before movement continues.
- `Company active capacity` warns when a company has more active applications
  than its configured cap.
- `Calendar guardrails` warns when configured non-operating weekdays/dates
  contain a scheduled round, or when numeric schedule days need a cycle start
  date before weekday checks can run.
- A stale-card warning means the board page was older than the application
  state. Reload and act from the current card.
- The audit log shows recent state-changing actions.

## After The Day Closes

Run:

```bash
php placement backup
php placement readiness
php placement export
```

Keep the final database backup and final export together. Do not
publish real candidate, recruiter, account, audit, or operational data in the
open-source repository.
