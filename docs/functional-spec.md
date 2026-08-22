# Functional Spec

Placement Operations is the dependency-light placement-day flagship module of
the Career Services Portal. It can be self-hosted or run as the same code in the
managed hosted data plane. It is an operations tool whose core job is to
coordinate candidates, companies, placement
office operators, control-room users, company trackers, mobile/floor trackers,
and auditors through a configurable live workflow.

## Product Goals

- Run locally or on a modest PHP host with no database server.
- Install through a browser or CLI first-run flow.
- Model candidate-company applications as the live unit of work.
- Keep every candidate movement auditable.
- Support different college workflows through configuration.
- Provide synthetic demos and CSV templates instead of shipping historical data.

## Users And Roles

| Role | Main responsibility |
|---|---|
| `admin` | Install, configure, override, manage users, and operate all flows |
| `control` | Move candidates through central control-room stages |
| `placement` | Resolve placement-office decisions, offers, preferences, and send-in/send-away actions |
| `company` | Operate one scoped company tracker board |
| `mobile` | Move candidates from schedule to transit |
| `floor` | Confirm floor/room arrival |
| `auditor` | Read-only review of boards, traces, system state, and logs |

## Core Entities

| Entity | Purpose |
|---|---|
| Candidate | Student or participant being coordinated |
| Company | Hiring organization, process owner, or interview panel |
| Application | Candidate-company relationship and current workflow status |
| Event | Append-only movement history for an application |
| Notification | In-app wanted/preference/operational message by role |
| Preference request | Placement-office decision when a candidate has competing choices |
| Wanted alert | Missing/unavailable candidate follow-up |
| Company round | Ordered process step such as case, GD, technical, HR, or interview |
| Round schedule | Day/room/time/capacity/status row for a round |
| Slot assignment | Candidate assignment to a specific round schedule |
| Panelist | Lightweight roster for a company round |
| Candidate unavailable window | Candidate-specific protected time block such as exam, travel, or accommodation |
| Setting | College/cycle name, timezone, policies, messaging, and planner configuration |

Candidates and companies can carry lightweight free-text tags for local cohorts,
categories, sectors, or operating segments. Tags are searchable on the board,
visible when enabled in board-card fields, and exported with CSV snapshots.
They can also carry a small `custom_fields_json` object for institution-specific
local columns. Custom fields stay server-rendered, searchable, importable, and
exportable without adding a frontend framework or changing the database schema
for every college.

Live board and candidate-trace projections apply server-side role visibility.
Company users see only their scoped company applications, cannot use hidden
candidate private fields as search text, and receive masked candidate tags,
custom fields, accommodation notes, cross-company routes, and private event
notes. Mobile and floor roles keep accommodation logistics visible but do not
receive private tags or custom fields.

## Main Screens

- Login/logout.
- Browser installer with local PHP/SQLite/writable-directory preflight.
- Role-aware board.
- Candidate trace.
- Records maintenance.
- CSV import/preview with canonical templates and common college header aliases.
- Wanted alerts.
- Preference requests.
- Notifications.
- Reports and placement counters.
- Admin settings and users.
- System/readiness/audit view.
- Aggregate-only public placement results without candidate identities.
- Authenticated candidate status lookup for placement roles.

Sensitive internal pages are role-gated before rendering. Company, mobile, and
floor users stay on their operational board/notification surfaces except where
the role explicitly needs Wanted alerts. Admin-only surfaces are hidden from
navigation and rejected server-side.

## Placement-Day Flow

The default workflow is:

```text
idle -> scheduled -> intransit -> arrived -> requested -> sendin -> inside
     -> exit -> requestaway -> sendaway -> sent -> placed
```

Each transition is role-gated. Movement writes an event and an audit log. When a
candidate is sent away from one company, the engine can hand the candidate to
the next scheduled company by marking that next application `intransit` and
recording the handoff as an event. Board cards and candidate traces project the
movement route, such as `previous company -> next company`, from stored handoff
fields. Placing a candidate clears competing active applications unless offer
upgrades are enabled.

## Scheduling Flow

Companies can define ordered rounds, schedules, capacity, and panelists. A round
with configured panelists is schedulable only while at least one panelist is
`active`. The CLI can report or apply safe slot suggestions:

- `suggest-slots`: company-order planner.
- `optimize-slots`: bounded exact small-scope planner with greedy fallback for
  larger scopes.
- `assign-slots`: applies safe company-order suggestions.
- `assign-optimized-slots`: applies safe optimized suggestions.

The planner respects active assignments, round order, schedule day/time order,
schedule capacity, panelist availability, candidate-level cross-company
conflicts, imported candidate unavailable windows, configured buffer minutes,
and optional company finish deadlines.

## Import And Export Flow

Imports are CSV-first and validate before mutation. Snapshot export writes a
portable folder of CSV files and a manifest without password hashes or delivery
targets. Candidate accommodation notes, lightweight tags, and candidate/company
custom fields are carried through import, records, board visibility where
enabled, role-masked candidate trace where relevant, and export as operational
context.
Configuration export/import writes share-safe JSON for settings and workflow
overrides without users, candidates, companies, notifications, audit logs, or
local delivery routes. Cycle name/type/date range, text-only site identity,
local terminology labels, and board card field choices are portable
configuration, so a college can keep operating context, display copy,
vocabulary, and dense or sparse operator cards across installs without editing
templates.
Candidate anonymization provides a post-cycle privacy path that redacts
candidate identity and linked notes while preserving aggregate application,
placement, and movement history.

## Reporting Flow

The Reports page and `php placement placement-report` expose placement-day
counters without a spreadsheet round trip:

- total, placed, and unplaced candidates
- total and active applications
- application counts by workflow status
- placements by company
- candidates by program
- candidates by current location

## Reliability And Safety

- CSRF protection on mutating forms.
- Role/scope guards on transitions.
- Stale-board guard before movement.
- Idempotency keys on live-board movement forms to avoid duplicate moves from
  double-clicks or browser retries.
- Configurable lightweight board refresh using HTML refresh metadata, without
  JavaScript polling.
- SQLite migrations.
- Backup/restore CLI.
- Browser and CLI first-run preflight checks.
- Placement counter and report checks.
- Readiness checks for workflow, backups, stale applications, active conflicts,
  capacity, open queues, notifications, and active users.

## Out Of Scope For V1

- Multi-tenant SaaS hosting.
- Required containers.
- Required JavaScript frontend framework.
- Required external queue or database server.
- Provider-certified SMS/WhatsApp behavior.
- Heavyweight institutional optimization solvers.
