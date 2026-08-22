# Configuration Architecture

The app stays dependency-light by keeping configuration in PHP arrays, SQLite
settings, database records, and CSV templates instead of introducing a framework
or build system.

## Layers

| Layer | Location | Purpose |
|---|---|---|
| App defaults | `config/defaults.php` | App name/version, database path, session name, default settings, roles |
| Workflow templates | `config/workflows.php` | Starter statuses, labels, colors, and transition roles |
| Runtime settings | `settings` table | College/cycle name, timezone, policies, notifications, planner options |
| Admin overrides | Admin screen and `workflow_transition_overrides` | Workflow label/color/role overrides without source edits |
| Portable config snapshot | `php placement config-export` | Share-safe JSON for settings and workflow overrides without people or operations data |
| Operational records | SQLite tables | Candidates, companies, applications, rounds, schedules, panelists, assignments |
| Import templates | `examples/csv-templates/` | Supported CSV shapes for setup and maintenance |
| Documentation | `docs/` | How a college adapts defaults safely |

## Configuration Principles

- Prefer explicit CSV/import/admin paths over editing source.
- Keep defaults generic enough for other colleges.
- Make placement-day policy visible: freeze, upgrades, active caps, buffers.
- Validate workflow changes before live use.
- Freeze configuration before live operations when setup should stop moving.
- Back up before major configuration changes.
- Treat institution-specific integrations as optional adapters.

## What Is Configurable Now

- College name, timezone, placement cycle name, cycle type, and cycle date
  range.
- Text-only site identity: displayed site name, tagline, public placements page
  title, and candidate status page title.
- Local terminology labels for candidate/candidates and company/companies.
- Workflow choice at install.
- Workflow labels, colors, and transition roles.
- Roles and scoped users.
- Configuration freeze for locking settings, workflow overrides, and portable
  configuration imports during live operations.
- Placement freeze.
- Offer-upgrade policy.
- Company process metadata: type, room, tracker, active cap, notes.
- Lightweight candidate and company tags for local cohorts, categories, and
  operational segments.
- Simple candidate and company `custom_fields_json` values for
  institution-specific local columns, stored as small JSON objects without
  schema edits or frontend dependencies.
- Ordered rounds and day/room/time/capacity/status schedules.
- Panel rosters.
- Slot planner strategy.
- Candidate cross-company scheduling buffer.
- Bounded exact optimizer limit.
- Live-board refresh interval.
- Board card detail fields.
- Custom CSV export profile dataset list.
- Custom CSV import header aliases for local spreadsheet vocabulary.
- Non-operating weekdays and dates for schedule/readiness guardrails.
- Audit request metadata retention mode: none, IP address, user agent, or both.
- External notification channels and local outbox/gateway settings.
- External notification text and SMS/WhatsApp JSON payload templates.

## Portable Configuration Snapshots

Use configuration snapshots when a college wants to move setup between
installations or share an operating template without exporting live placement
records:

```bash
php placement config-export /path/to/config.json
php placement config-validate /path/to/config.json
php placement config-import /path/to/config.json
```

The package includes share-safe college/cycle settings, text-only site identity,
policy/planner options, board refresh interval, board card field choices such
as movement route visibility, custom export profile dataset choices, local
terminology labels, custom import header aliases, non-operating calendar
guardrails, notification text templates, audit request metadata retention
policy, and workflow label/color/role overrides. It excludes users, password
hashes, candidates, companies,
applications, notifications, audit logs, installed timestamp, local outbox
paths, gateway URLs, and recipient routes.

`config-validate` checks schema, portable setting keys, workflow references,
cycle date format, status overrides, transition overrides, role names, colors,
board-card fields, text identity length, custom export dataset names,
terminology label length, calendar weekday/date values, audit metadata mode,
and custom import alias fields without mutating the database. `config-import`
runs the same validation before mutation and writes a driver-appropriate safety
backup under
`data/config/`. If `configuration_freeze` is enabled, config imports are
rejected until an administrator unfreezes configuration from Admin.

## Starter Workflow Templates

Current starter workflows:

- Default placement day.
- Engineering multi-branch.
- Internship season.
- Simple placement cell.
- Pooled campus drive.
- Virtual interview process.
- Walk-in or job-fair process.

See `docs/indian-college-template-notes.md` for how these map to common college
operating shapes. Portable JSON examples for these workflows live under
`examples/config-templates/`; validate one with `php placement config-validate`
before importing it into an installed app.

## Safe Change Flow

1. Export configuration and back up the application database.
2. Make changes in Admin, Records, or CSV import preview.
3. Run `php placement readiness`.
4. Run relevant dry-run or browser QA checks.
5. Freeze configuration before live operations if settings and workflow changes
   should stop.
6. Freeze placement decisions if placement outcomes should require admin
   override.
7. Apply any later changes during a low-risk window after explicitly unfreezing.

## Later Configuration Work

- Deeper custom export bundle definitions beyond the fixed CSV export profiles.
- Rich typed field definitions for custom fields, including role visibility,
  validation, sensitivity, retention, and import/export mapping.
- Institution-specific validation profiles.
- Additional typed configuration scopes only when a real module needs them.
