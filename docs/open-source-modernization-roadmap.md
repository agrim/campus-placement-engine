# Campus Placement Engine Open-Source Modernization Roadmap

Date: 2026-06-27

> Direction update, 2026-07-15: this document remains the historical extraction
> and placement-engine modernization record. The accepted product architecture
> now treats Placement Operations as the first flagship module of a broader
> Career Services Portal with both self-hosted and managed-hosted editions. See
> [career-services-portal-architecture.md](career-services-portal-architecture.md)
> for the current implementation direction and completion gates.

## Revised Productization Roadmap, 2026-07-15

The destination is a Career Services Portal with Placement Operations as its
first-class flagship module. Colleges can run the same release themselves or use
a managed service. Optional sections are modules, but the downloadable product
does not become a generic executable-extension framework or empty shell.

The implementation now follows one deliberately small technology policy:

- Plain PHP 8.2+, server-rendered HTML, vanilla CSS, and minimal JavaScript.
- SQLite for the zero-administration self-hosted default.
- PostgreSQL for hosted and institution-operated server deployments.
- One modular monolith and one release, not separate self-hosted/SaaS forks.
- One institution per data-plane database; hosted metadata lives separately.
- CLI/cron and a transactional database outbox before external queue systems.
- No Node build, SPA framework, Redis, message broker, Kubernetes, image assets,
  or arbitrary uploaded PHP modules.

### Delivery Phases

| Phase | Outcome | State |
|---|---|---|
| 1. Preserve | Quarantine historical archives; characterize behavior with synthetic fixtures and tests. | Implemented |
| 2. Kernel | Connection provider, institution context, scoped settings, capabilities, and compatibility shims. | Implemented |
| 3. Durable model | People, profiles, organizations, cycles, participants, opportunities, offers, and public IDs. | Implemented |
| 4. Placement module | Placement routes, navigation, domain services, privacy, and portability move behind module ownership. | Implemented |
| 5. Workflow engine | Immutable versions, named branches, guards/effects, simulation, and explicit migration. | Implemented |
| 6. Module lifecycle | Manifests, dependencies, capabilities, enable/disable, events, and uninstall safeguards. | Implemented |
| 7. Self-host distribution | Browser/CLI install, demo cleanup, upgrades, backup/restore, package verification, and SQLite round trip. | Implemented |
| 8. Dual persistence | Shared SQLite/PostgreSQL behavior contract and driver-aware recovery. | Implemented |
| 9. Hosted operations | Metadata-only control plane, exact-domain resolution, per-tenant databases, provisioning, entitlements, fleet jobs, backups, and audited support grants. | Implemented |
| 10. Extension proof | Career Advising module with independent data, routes, event use, privacy, portability, and hosted plan behavior. | Implemented |
| 11. Production hardening | Portal privacy, login throttling, database session locks, signed identity-proxy SSO, health, metrics, logs, outbox delivery, restore drills, and load probes. | Implemented |

“Implemented” means working source and automated/local contracts, not a claim of
production maturity. The pre-1.0 release gates that remain are institutional
pilot evidence, accessibility and cross-browser sign-off, real identity-provider
and messaging certification, hosted backup-retention operations, incident drills,
and the final license/trademark decision.

### Next Release Horizon

1. Freeze feature scope for the first public alpha and resolve any remaining
   migration, security, accessibility, or documentation findings.
2. Run clean-package install, representative pre-migration upgrade, PostgreSQL
   restore, logical portability, browser, role-access, and load-baseline gates;
   add true N-minus-one qualification after the first tagged release exists.
3. Pilot Placement Operations with synthetic rehearsals at more than one college;
   convert differences into configuration or vetted rules, not forks.
4. Pilot one real institution under self-hosting before treating managed hosting
   as generally available.
5. Operate hosted backups, tenant resolution, support grants, metrics, and
   recovery on test tenants long enough to establish useful SLOs.
6. Tag the first alpha only after public-history hygiene, legal license review,
   security contact, release notes, and maintainer ownership are settled.

### Later Portal Expansion

New modules should be demand-led. Employer relationships, internships, events,
mentoring, documents, and outcomes are plausible, but each needs a real owner,
workflow, privacy policy, portability contract, and two-driver test before it
earns navigation. Object storage, search infrastructure, or a queue service is
introduced only by a concrete module need and measured load.

## Executive Recommendation

Do not try to publish or cosmetically modernize the current PHP archive in place.
Treat the current repository as a private historical source archive and create a
clean public product from the domain model, workflows, edge cases, and operating
lessons embedded in it.

The valuable asset here is not the decade-old PHP implementation. The valuable
asset is the placement-day control system: a live logistics engine for moving
candidates through companies, panels, requests, locations, preferences, and
placement decisions under high pressure. The open-source tool should preserve
that operating model while replacing the legacy implementation with a secure,
configurable, testable, distributable system that other colleges can run.

The recommended path is:

1. Freeze and inventory the legacy material privately.
2. Extract a product and domain specification from the app, documents, CSVs, and
   dry-run scenarios.
3. Build a clean-room open-source implementation around an explicit transition
   engine and append-only event log.
4. Ship a synthetic demo dataset, browser installer, import templates, tests,
   and simple deployment documentation.
5. Keep institution-specific assumptions configurable instead of hardcoded.

## Current Repository State

The repo is currently clean from a public Git perspective: only `README.md` is
tracked. The rest of the folder is untracked archive material.

Important archive families:

| Path | Role in modernization |
| --- | --- |
| `place/` | Likely the most complete live application tree. Use as the primary behavioral oracle. |
| `place.zip`, `place.7z`, `place-day-zero.7z` | Legacy snapshots. Use for version comparison only. |
| `Control/` | Product notes, dry-run cases, screenshots, CSVs, and operational artifacts. Use for requirements mining. |
| `Control 2/` | Appears byte-identical to `Control/`; can be treated as a duplicate unless hash inventory proves otherwise. |
| `Control App/` | Older app revisions and `app27.zip` with alternate files and database dumps. Mine for missing variants. |
| `Control.zip`, `Place backup.zip`, `htdocs*.7z`, `app20.rar` | Historical archive snapshots. Inventory before deciding what to retain. |
| `OrangeRinds/` | Appears unrelated to the placement engine. Quarantine outside the public project. |

The most important immediate fact: because the private app archives are still
untracked, this is the right moment to create a clean public boundary. Do not
commit the raw archive material into the future open-source history.

## Sensitive Material And Publication Risk

The archive contains material that must not be published as-is:

- Real-looking student records in SQL and CSV files.
- Company/student assignment data.
- User accounts, password hashes, salts, login hashes, and user logs.
- Internal IP addresses, browser agents, request logs, timestamps, and operational traces.
- Hardcoded database credentials in legacy PHP files.
- Institution-specific branding, names, favicons, footer text, and process terminology.
- Excel and document files that encode internal placement-office process details.

Before any public release:

1. Keep the raw archive private.
2. Generate synthetic fixtures instead of anonymizing in place.
3. Replace real institution, company, user, and candidate names with generated data.
4. Remove all secrets from public history.
5. Add a `SECURITY.md`, disclosure contact, and data-retention guidance.
6. Add import/export warnings that make colleges responsible for local legal compliance.

## What The Legacy App Actually Is

This is not just a placement portal. It is a placement-day operations engine.

The core job is to coordinate live candidate movement and decision-making across
multiple actors:

- Control room operators.
- Placement committee operators.
- Company trackers.
- Mobile/floor trackers.
- Backloop/preference coordinators.
- Admin/system operators.
- Students/candidates, in the originally planned public/student views.

The legacy system has screens for:

- Status Change Window: control-room override and master status board.
- Company Status Trace: company-specific live candidate board.
- Notification Center Window: in/out requests and exceptions.
- Interview Time Alert: queue and panel alerts.
- System Update Window: CSV import/export and master data management.
- Student Status Trace: per-candidate audit and routing trace.
- Mobile Tracker Sheet: floor/mobile logistics view.
- LP Counter: placed/non-placed count and location view.
- Candidate Preference Page: backloop decision capture.
- System Dashboard: indexes, logs, status, and maintenance controls.
- User Management: account and role management.

The archive documents also mention planned or partially missing surfaces:

- Wanted / missing-person list.
- Public placement results, implemented as aggregate-only disclosure.
- Candidate-facing shortlist/status concept, currently restricted to
  authenticated placement roles pending a separately designed identity model.
- Schedule board.
- Redundancy / sanity-check board.
- More formal event-log table.

Those missing surfaces should be considered product requirements, not discarded
just because the latest PHP tree does not fully implement them.

## Most Important Legacy Insight

The original documents point toward an event-log model, but the later PHP
implementation stores workflow state as repeated columns such as `stat1`,
`time1`, `stat2`, `time2`, and so on.

For a modern open-source tool, reverse that decision:

- Make the append-only transition/event log the source of truth.
- Derive current state, board columns, time deltas, and history views from events.
- Keep compatibility importers that can read the old wide-table format.
- Never make the wide status columns the core domain model again.

This single architectural decision will make the system safer, more auditable,
more testable, and easier for other colleges to adapt.

## Legacy Workflow To Preserve

The default workflow should preserve the current 11-stage model because it
captures real placement-day nuance:

| Code | Legacy status | Meaning |
| --- | --- | --- |
| 0 | idle | Candidate is not active in this company process. |
| 1 | scheduled | Candidate is scheduled for a company. |
| 2 | intransit | Candidate is moving toward a company/panel. |
| 3 | arrived | Candidate has arrived outside the company/panel. |
| 4 | requested | Company has requested candidate to be sent in. |
| 5 | sendin | Control has granted send-in. |
| 6 | inside | Candidate is inside the panel/interview. |
| 7 | exit | Candidate has exited the panel/interview. |
| 8 | requestaway | Company has requested candidate to be sent away. |
| 9 | sendaway | Control has granted send-away. |
| 10 | sent | Candidate has been sent onward. |
| 11 | placed | Candidate has been placed. |

The default transition permissions should preserve the original operator model:

| Transition | Primary actor |
| --- | --- |
| idle -> scheduled | Control |
| scheduled -> intransit | Company or mobile tracker, depending on configuration |
| intransit -> arrived | Company |
| arrived -> requested | Company |
| requested -> sendin | Control |
| sendin -> inside | Company |
| inside -> exit | Company |
| exit -> requestaway | Company |
| requestaway -> sendaway | Control |
| sendaway -> sent | Company |
| sent -> placed | Control or placement office |

This should be configurable, but the shipped demo and tests should encode this
workflow as the default.

## File And Module Findings

### `place/`

Use this as the main behavioral reference, not as code to publish.

Key findings:

- Hand-rolled PHP app with no Composer, package lock, migrations, or modern app framework.
- Apache rewrite entrypoint through `.htaccess` and `index.php`.
- Global variables and include-based routing.
- Hardcoded base path, timezone, database configuration, and institution branding.
- RedBeanPHP is vendored directly as `models/rb.php`.
- Database schema is implicit and partly created by RedBean auto-resolution.
- Authentication uses a custom salted SHA-512 loop, not modern password hashing.
- SQL is frequently built by string interpolation.
- Several controllers mix request handling, domain logic, persistence, and view rendering.
- Some views mutate state directly.
- Auto-refresh and JavaScript alerts stand in for real-time updates.
- Logs and backup commands contain local XAMPP assumptions.
- Legacy comments and footers contain personal/institution-specific branding.

Specific high-risk examples to handle during extraction:

- `place/index.php`: global bootstrap, hardcoded `/place/`, timezone, URI parsing.
- `place/controllers/controller_generic.php`: assignment in condition causes authorization checks to always pass through the generic include path.
- `place/controllers/controller_cst.php`: assignment in condition around GD panel path handling.
- `place/controllers/controller_suw.php`: large CSV/admin importer with raw insert construction and mixed responsibilities.
- `place/controllers/controller_sys.php`: destructive maintenance actions exposed through the web app.
- `place/models/db.php`: hardcoded database connection.
- `place/models/user_evaluate.php`: session validation through interpolated query strings.
- `place/models/model_user.php`: legacy login hashing and login token handling.
- `place/models/model_log_entry.php`: core transition engine; this is the most valuable file to mine.
- `place/models/model_load_entry.php`: board projection logic; mine this for the read-model design.
- `place/models/model_next_company.php`: next-company routing logic; rewrite as a tested service.
- `place/views/navbar.php`: state-changing POST handling inside the view; separate this in the new app.
- `place/views/view_card_decisions.php`: button/permission logic; mine for expected operator controls.
- `place/style.css`: useful visual grammar for status colors and dense board layout, but not a modern UI foundation.

### `Control/`

Use this as the requirements archive.

Key findings:

- Contains product structure documents, dry-run scenarios, screenshots, CSVs,
  and operational artifacts.
- The documents describe additional planned modules that are not fully present in `place/`.
- The dry-run use cases are extremely valuable as acceptance tests.
- The screenshots confirm the app was a dense live operations board, not a CRUD dashboard.
- CSV and SQL files include sensitive operational data and should not be published.

### `Control 2/`

Treat as a duplicate of `Control/` unless the final hash ledger identifies any
differences. Do not spend product time on it before hashing.

### `Control App/`

Use this as a version archaeology source.

Key findings:

- Contains older app directories and `app27.zip`.
- `app27.zip` has alternate `_2`, `_3`, and app-specific file variants that may
  contain lost behavior.
- Its SQL dumps are not safe public seed data, even when their filenames sound blank.
- Older `app5` files can help identify how the app evolved, but should not drive
  the new architecture.

### Archives

Inventory them, hash them, and then move them out of the public project path or
keep them in a clearly private archive. Their value is historical comparison,
not direct distribution.

## Product Thesis

The open-source project should be positioned as:

"A configurable placement-day operations engine for colleges and universities."

It should help institutions run high-pressure placement processes by giving them:

- A live control room board.
- Role-specific company, mobile, floor, and placement-office views.
- Auditable candidate movement and status transitions.
- Import tools for candidates, companies, schedules, and shortlists.
- Lightweight live-board refresh, alerts, and exception handling.
- Preference/backloop decisions when candidates or companies create conflicts.
- Placement counts, candidate traces, and operational health views.
- Safe local deployment for institutions that cannot send data to a third party.

Avoid positioning it as a generic ATS. The distinctive value is the live
operations layer on placement day.

## Generic Indian College Perspective

Design for the range of Indian placement operations, not only the original
campus process.

Indian colleges can differ sharply in structure, maturity, vocabulary, policy,
and operational constraints. A credible open-source tool should work for:

- Large IIM/IIT/NIT-style institutes with high-pressure placement days.
- Engineering colleges with branch-wise eligibility, mass recruiters, and
  multiple parallel test/interview stages.
- MBA, law, design, liberal arts, medical, or vocational institutions with
  different placement calendars and evaluation stages.
- Colleges where the placement cell is run by a small staff with student
  volunteers.
- Universities with multiple schools, campuses, departments, batches, and
  placement offices.
- Pooled campus drives where one host institution coordinates candidates from
  several colleges.
- Internship, summer placement, lateral placement, final placement, PPO/PPI, and
  off-campus processes.
- Mostly offline placement days, mostly virtual processes, and mixed/hybrid
  processes.

This means the product must not bake in one institution's assumptions about:

- What a "company" means.
- What a "profile" or "role" means.
- What statuses exist.
- Who is allowed to move a candidate.
- Whether placement is a single terminal event or part of an offer-upgrade
  policy.
- How shortlists, waitlists, preferences, eligibility, and opt-outs work.
- Whether the process runs in one day, a week, a semester, or year-round.

The default workflow can mirror the legacy placement-day flow, but the product's
professional maturity should come from making that workflow one template among
many.

## Modernization Through Variableization

"Customizable" should not mean "edit source code." It should mean a college can
configure its placement process through supported admin screens, versioned
configuration files, and built-in validation checks.

Treat variableization as a core architecture layer:

- Configuration is stored as first-class data, not scattered constants.
- Each placement cycle uses an explicit configuration version.
- Configuration changes are auditable.
- Critical configuration can be frozen before placement day.
- Proposed configuration can be validated against built-in checks before it goes live.
- Starter workflow files can be shared without sharing private student or recruiter data.

The project should ship a few starter workflows:

- MBA day-zero placement template.
- Engineering multi-branch placement template.
- Internship season template.
- Pooled campus drive template.
- Simple college placement-cell template.
- Virtual interview process template.
- Walk-in or job-fair template.

Each starter profile should define workflow stages, roles, permissions, forms,
boards, reports, imports, and demo data. Keep this as simple data configuration,
not as an extension marketplace or a separate platform layer.

## Target Domain Model

The new implementation should explicitly model the domain instead of hiding it
inside status columns and global arrays.

Core entities:

- Institution
- Placement cycle
- Program/cohort
- Candidate
- Company/recruiter
- Company process/profile
- Interview slot/day
- Panel
- Shortlist/application
- Candidate process
- Transition event
- Candidate location
- Permission/request
- Candidate preference request
- Routing decision
- Wanted/missing alert
- User
- Role
- Role assignment
- Import batch
- Import validation issue
- Audit log
- System health event
- Workflow template
- Workflow stage
- Transition rule
- Config version
- Custom field definition
- Board/view definition
- Report/export definition

Core services:

- Transition engine
- Permission engine
- Configuration validator
- Board projection engine
- Next-company router
- Import validator
- Conflict detector
- Audit logger
- Backup/restore service

## Customization Model

The new system should expose structured customization across these layers.

### Institution And Cycle Configuration

Colleges should be able to configure:

- Institution, campus, school, department, and program structure.
- Placement cycles, seasons, rounds, days, and slots.
- Academic batches, branches, sections, cohorts, and student groups.
- Timezone, working hours, placement blackout periods, and holiday calendars.
- Branding, logos, colors, terminology, and public page names.
- Data-retention settings and privacy defaults.

This lets one installation support a single business school, a multi-campus
university, or a host college running a pooled drive.

### Workflow Stage Configuration

The workflow engine should support more than the legacy fixed status chain.

Configurable stage attributes:

- Stage key and display label.
- Stage type, such as shortlist, test, GD, interview, hold, request, movement,
  offer, waitlist, rejected, withdrawn, placed, or archived.
- Whether the stage is visible to candidates, recruiters, operators, or only
  admins.
- Allowed incoming and outgoing transitions.
- Actor roles allowed to trigger each transition.
- Required fields before entering or leaving the stage.
- Whether movement is reversible.
- Whether the stage is terminal.
- SLA or warning thresholds.
- Color, icon, board column, and sort order.
- Automatic transitions, if safe.
- Parallel stage support for processes that run tests, interviews, or document
  checks independently.

This is what lets an engineering college add "Online Assessment", "Technical
Round 1", "Technical Round 2", "HR Round", and "Offer Released" without
changing application code, while an MBA college keeps GD, PI, hold, and LP-style
placement flows.

### Role And Access-Control Configuration

Use role-based access control plus scoped attributes, not a flat legacy role
list.

Configurable role examples:

- Placement officer.
- Faculty placement coordinator.
- Department coordinator.
- Student placement representative.
- Company tracker.
- Floor or room volunteer.
- Transport or logistics volunteer.
- Recruiter or company representative.
- Candidate.
- Read-only auditor.
- System administrator.
- Data-import operator.

Permissions should be scoped by:

- Institution, campus, department, program, batch, or placement cycle.
- Company, role/profile, room, panel, or slot.
- Candidate group.
- Data sensitivity.
- Action type, such as view, import, edit, transition, override, approve,
  publish, export, delete, or configure.
- Time window, such as temporary placement-day access.

Sensitive fields should support masking by role. For example, a volunteer may
need candidate name, current location, and next room, but not compensation,
contact information, or private notes. The current release now applies basic
server-side masking for live board and candidate-trace projections: company
users are scoped to their company and do not receive candidate private fields,
cross-company routes, or private event notes; mobile/floor roles keep
accommodation logistics while private tags and custom fields stay hidden.

### Placement Policy Configuration

Indian colleges often have placement policies that are as important as the
interview workflow. The product should model them explicitly.

Configurable policy rules can include:

- One-student-one-offer rules.
- Dream, super-dream, core, non-core, or tiered offer rules.
- Upgrade eligibility after accepting an offer.
- PPO/PPI handling.
- Internship-to-final-placement conversion.
- Opt-in, opt-out, and withdrawal rules.
- Eligibility by program, branch, specialization, CGPA, backlog status,
  graduation year, work experience, or custom fields.
- Company-specific eligibility conditions.
- Waitlist movement.
- Offer acceptance deadlines.
- Exception approvals and override reasons.

These checks should produce explainable decisions. If a candidate cannot be
scheduled, moved, shortlisted, or shown a company, the UI should be able to say
which rule blocked it.

### Forms, Fields, And Data Schema Configuration

Do not hardcode candidate and company schemas.

The current dependency-light release has a deliberately small first step:
candidate and company records include `custom_fields_json`, a compact JSON
object for institution-specific local columns. It is available through records,
CSV import/export, board search, optional board-card details, and candidate
privacy anonymization. This avoids schema edits and frontend dependencies while
leaving room for richer typed fields later.

Colleges should be able to add custom fields for:

- Candidate profile.
- Academic records.
- Eligibility and preferences.
- Company profile.
- Job role/profile.
- Compensation components.
- Location and work mode.
- Panel and room logistics.
- Special accommodations.
- Internal notes.

Field definitions should include:

- Type, such as text, number, date, enum, multi-select, boolean, file, URL, or
  computed value.
- Required/optional rules.
- Visibility by role.
- Editability by role.
- Import/export mapping.
- Validation rules.
- Sensitivity level.
- Retention policy.

For Indian placement use cases, compensation should support local conventions
such as CTC, fixed pay, variable pay, joining bonus, stipend, location, and
notes, while keeping the schema flexible enough for institutions that track
different compensation structures.

### Board, Card, And Component Configuration

The live UI should be configurable without code forks.

Colleges should be able to configure:

- Which boards exist.
- Which stages appear as columns.
- Which cards appear in each board.
- Which fields appear on each card.
- Which buttons/actions appear for each role.
- Which counters and warnings appear in headers.
- Which filters and quick searches are available.
- Which colors/icons represent statuses.
- Which views are mobile-first versus control-room-first.
- Which dashboards are public, internal, recruiter-facing, or candidate-facing.

Reusable component types should include:

- Board column.
- Candidate card.
- Movement route line.
- Company card.
- Queue panel.
- Alert banner.
- Counter tile.
- Timeline.
- Request queue.
- Preference decision widget.
- Import validation table.
- Report table.

The key maturity principle: customize the composition and configuration of
components, but keep transition commands and permission checks centralized.

### Notification And Communication Configuration

Many Indian colleges run placement operations through a mix of email, phone,
WhatsApp, SMS, notice boards, and verbal floor coordination. The open-source
tool should not assume one channel.

Provide a pluggable notification layer with:

- Email provider support.
- SMS provider support.
- WhatsApp or messaging-provider integration points where legally and
  operationally appropriate.
- In-app notifications.
- Webhooks.
- Printable call sheets.
- Exportable room-wise and company-wise lists.
- Message templates by role and stage.
- Per-cycle communication rules.
- Candidate consent and contact visibility controls.

The first open-source version can ship basic email/in-app/webhook support, but
the architecture should allow colleges to add local providers without changing
the domain engine.

### Reports, Exports, And Compliance Configuration

Colleges need different reports for placement office, directors, departments,
recruiters, student bodies, accreditation, and internal review.

Make reports configurable:

- Placement summary by program, branch, company, role, compensation band, or
  location.
- Candidate status and movement audit.
- Company participation report.
- Offer acceptance and conversion report.
- Unplaced candidate list.
- Day-wise and slot-wise operations report.
- Recruiter feedback report.
- Exception and override report.
- Data-retention and deletion report.

Exports should support CSV/XLSX/PDF where practical, with role-based field
masking.

### Extension And Integration Points

Other colleges will have local systems. The app should integrate without making
each college fork the code.

Recommended future extension points:

- Import adapters.
- Export adapters.
- Authentication providers.
- Custom report definitions.
- Read-only API for institutional dashboards.
- Webhooks for approved integrations.

For v1, do not promise a broad executable-extension ecosystem. Publish a stable config shape
and keep the code easy to extend later.

## Data Model Principles

Use an append-only event model for candidate-company state transitions.

Minimum transition event fields:

- `id`
- `institution_id`
- `cycle_id`
- `candidate_process_id`
- `candidate_id`
- `company_id`
- `from_status`
- `to_status`
- `actor_user_id`
- `actor_role`
- `source_surface`
- `reason`
- `occurred_at`
- `correlation_id`
- `idempotency_key`
- `metadata`

Use projections/read models for:

- Current candidate-company status.
- Candidate current location.
- Company board columns.
- Mobile/floor board queues.
- Notification center requests.
- Candidate trace.
- Placement counts.
- System health metrics.

This lets the app support auditability, undo/reversal, replay, reporting, and
safer concurrent operation.

## Security And Privacy Requirements

Minimum bar for open source:

- Modern password hashing.
- Session fixation protection. The current release rotates the PHP session ID
  after successful login and sets strict, cookie-only, HttpOnly, SameSite=Lax,
  HTTPS-aware session cookies.
- Browser security headers. The current release emits a self-only content
  security policy, same-origin frame protection, MIME-sniffing protection,
  restrained referrer policy, and disabled browser device permissions.
- CSRF protection.
- Role-based access control enforced server-side.
- Server-side role masking for sensitive live-board and candidate-trace fields.
- Input validation for every import and mutation.
- Parameterized database access.
- Strict file upload handling. The v1 importer is paste-only, stores no uploaded
  files, and now enforces configurable byte/row limits before preview or import.
- No secrets in source. The current release ignores `.env`-style local files,
  ships only a synthetic environment template, and fails `publication-check` on
  common public-source token/private-key patterns.
- Environment-based configuration. `docs/environment.md` documents supported
  `CPE_*` variables for database paths, first-run install, session cookies,
  import limits, smoke/QA checks, notification handoff, and safety-copy paths.
- Audit trail for all state changes.
- Privacy-conscious logging with configurable IP/user-agent retention. Audit
  request metadata is now opt-in and defaults to no IP/user-agent retention.
- Backup encryption guidance. New app-created backups write SHA-256 checksum
  sidecars, restore verifies them when present, and docs route off-machine
  backup encryption to institution-approved tooling.
- Data export and deletion paths.
- Synthetic demo data only.
- Security policy and vulnerability reporting process.

Placement-day systems handle sensitive career outcomes. Treat security and audit
as core product features, not as a hardening pass after the UI is finished.

## Reliability Requirements

Placement-day reliability matters more than visual polish.

Build for:

- Optimistic locking on candidate-process updates.
- Idempotent button actions.
- Transactional transition handling.
- Stale-page detection.
- Conflict warnings when a candidate is active in multiple places.
- Lightweight timed refresh or polling for live boards.
- Clear fallback behavior when timed refresh or polling fails.
- CSV export snapshots during operations.
- Backup/restore commands.
- Health checks.
- Import rollback or batch reversal.
- Deterministic tests for all transition rules.

## Configuration And Independentization

Configuration should be a product capability, not a deployment afterthought.

Build a configuration architecture with:

- A small documented configuration shape.
- Versioned placement-cycle configuration.
- A few starter workflow/configuration files that can be validated, imported,
  copied, and edited.
- Admin UI for common changes.
- File-based configuration for technical users.
- Validation and linting for invalid stages, broken permissions, missing fields,
  and unreachable transitions.
- A dry-run validation screen before a workflow is published.
- Freeze/unfreeze controls around live placement days.
- Import/export of configuration without candidate or recruiter data.

Ship one default configuration that mirrors the legacy workflow, plus a small
number of synthetic starter workflow/configuration files for common Indian
college operating models.

## User Experience Direction

The UI should remain operationally dense. Do not turn it into a marketing-style
dashboard full of large cards.

Keep the frontend deliberately austere:

- Server-render HTML.
- Use one small CSS file.
- Use one small JavaScript file only for necessary interaction.
- Avoid frontend frameworks.
- Avoid bundlers.
- Avoid images in the core app.
- Avoid icon fonts, web fonts, animations, and heavy client-side rendering.
- Prefer tables, forms, buttons, and status colors over decorative UI.
- Make pages usable on low-power laptops, old desktops, and ordinary phones.

Recommended surfaces:

- Control room board: all companies/statuses, fast filtering, conflict banners.
- Company tracker board: only assigned company/process, action buttons, queue counts.
- Mobile/floor board: movement queues, handoff status, current location updates.
- Notification center: send-in/send-away and exception requests.
- Candidate trace: full chronological route and audit history.
- Placement counter: placed/non-placed, company placements, location summary.
- Preference/backloop console: ask, answer, decide, audit.
- Import/admin console: validate, preview, import, rollback.
- System health console: stale boards, stuck candidates, active users, backup status.

Design principle: every button should map to an explicit transition command,
permission check, and audit event.

Configuration principle: every surface should have safe adjustment points. A
college should be able to add a board, add a card field, rename a stage, hide a
sensitive field, or change an action label without weakening the underlying
transition engine.

## Recommended Technical Direction

Choose the smallest serious stack:

- Plain PHP.
- SQLite by default.
- PDO for database access.
- Server-rendered HTML templates.
- Vanilla CSS.
- Minimal vanilla JavaScript only where it materially improves operations.
- PHP sessions.
- `password_hash()` and `password_verify()` for passwords.
- Simple CSRF tokens.
- Simple migration table.
- Simple role and permission checks.

The runtime release should not require:

- Laravel or another framework.
- Composer to run the app.
- Node.js.
- React, Vue, Angular, or a frontend build step.
- Redis.
- A queue worker.
- A separate API service.
- A separate database server.
- Docker or any container runtime.
- Images, icon packs, fonts, or heavyweight UI assets.

The goal is a self-contained web app that can run anywhere a modest PHP server
can run. The default local database is a single SQLite file that is easy to
create, back up, copy, and reset.

The stack choice is deliberately conservative so the product energy goes into:

- Clean transition logic.
- Safe permissions.
- Reliable imports.
- Lightweight control-room screens.
- A first-run installer.
- Synthetic demo data.
- Simple backup and restore.
- Clear open-source documentation.

Future versions can add other database backends, a richer UI layer, or optional
container images if adoption proves those are needed. They should not be v1
dependencies.

## Local Testing And Containers

The cleanest local testing path is PHP's built-in web server:

```bash
php -S localhost:8000 -t public
```

Then open:

```text
http://localhost:8000/
```

This matches the product philosophy: no container, no database server, no build
step, and no extra moving parts. A developer can delete the SQLite file and run
the installer again.

Apple Container should be optional, not the default. It is useful later for
checking that a release can run as an OCI image on Apple silicon Macs, but it is
not simpler than `php -S` for this project. It also narrows the local path to
developers with supported Apple silicon/macOS 26 setups, while the PHP server
path works almost everywhere. Treat it as a release smoke-test option, not the
daily development contract.

Use containers only as an additional packaging target:

- v1 default: PHP built-in server for development.
- v1 deployment: upload/unzip to a PHP host or run under Apache/Nginx.
- Later optional: OCI image for people who prefer containers.
- Later optional: Apple Container smoke test for the OCI image on Mac.

## Minimal Testing Strategy

Keep tests close to the app and free of heavy infrastructure.

Recommended v1 checks:

- `php -l` syntax checks for PHP files.
- A tiny `php tests/run.php` test runner if avoiding dev dependencies.
- Unit-style tests for transition rules and permission checks.
- SQLite integration tests using temporary database files.
- Import tests using small synthetic CSV fixtures.
- Installer tests that create a fresh SQLite file and admin user.
- Browser smoke tests against `php -S localhost:8000 -t public`.
- A seeded live dummy placement drive that can be cleared from System before
  actual imports, or reset entirely by deleting the throwaway SQLite file.

Add heavier tools only when they clearly pay for themselves. For example,
Playwright can be useful later for the main operator board, but should not be a
v1 runtime dependency.

## First-Run Installer

The deployment experience should feel like classic self-hosted PHP software:
copy the files, open the browser, finish setup.

Installer flow:

1. Check PHP version and required extensions.
2. Confirm the `data/` directory is writable.
3. Create or select the SQLite database file.
4. Generate application secrets.
5. Create the first administrator.
6. Set college name, timezone, and basic terminology.
7. Choose the default placement workflow.
8. Run migrations.
9. Optionally load synthetic demo data.
10. Lock the installer. The current release refuses second-run installation
    once the database has an `installed_at` marker.

The same setup should also be available from CLI for technical users:

```bash
php placement install
php placement doctor
php placement backup
php placement restore
```

The installer should not require a separate database server, mail server,
container runtime, or package manager.

## Suggested Public Repository Shape

Use a single-app structure:

```text
docs/
  functional-spec.md
  legacy-inventory.md
  dry-run-scenarios.md
  configuration-architecture.md
  security-and-privacy.md
  deployment.md
  migration-from-legacy.md

examples/
  demo-college/
  csv-templates/

public/
  index.php
  install.php
  assets/
    app.css
    app.js

app/
  Controllers/
  Domain/
  Import/
  Install/
  Security/
  Support/
  Views/

config/
  defaults.php
  workflows.php

database/
  migrations/
  seeds/

data/
  .gitkeep

tests/
```

Preserve conceptual boundaries inside the monolith:

- Domain transition logic.
- Importers.
- Read-model queries.
- UI templates.
- Installer.
- Documentation/examples.

Keep private legacy material outside the public repo, or under a clearly ignored
private path that never enters Git.

## Phased Plan

### Phase 0: Freeze And Inventory

Goal: establish a safe public boundary.

Actions:

- Add `.gitignore` rules for private archives and raw data.
- Hash every archive and directory.
- Produce an inventory table with size, type, likely role, and sensitivity.
- Identify duplicate archives.
- Move unrelated material out of the project workspace.
- Create a private `legacy/` storage plan outside public Git.
- Confirm no raw SQL/CSV/docx/xlsx with sensitive data will be committed.

Deliverables:

- `docs/legacy-inventory.md`
- `docs/publication-risk-register.md`
- Private archive hash ledger

### Phase 1: Functional Extraction

Goal: turn the archive into a product spec.

Actions:

- Extract every role, screen, status, transition, and permission.
- Identify every institution-specific assumption in names, statuses, roles,
  fields, reports, and policies.
- Convert dry-run cases into acceptance scenarios.
- Document hidden assumptions in the PHP code.
- Compare `place/`, `app27.zip`, and old app revisions for missing behavior.
- Decide which missing planned modules belong in MVP versus later releases.
- Draft the first generic Indian-college template set.

Deliverables:

- `docs/functional-spec.md`
- `docs/workflow-transition-matrix.md`
- `docs/dry-run-acceptance-tests.md`
- `docs/glossary.md`
- `docs/configuration-architecture.md`
- `docs/indian-college-template-notes.md`

### Phase 2: Clean Public Scaffold

Goal: create the actual open-source project shell.

Actions:

- Pick license.
- Commit to the plain PHP + SQLite v1 stack.
- Create the single-app scaffold.
- Add PHP built-in-server quickstart.
- Add environment/configuration file handling.
- Add CI.
- Add lightweight lint/test commands.
- Add contribution and security docs.
- Add initial workflow configuration files.
- Add synthetic demo data generator.
- Add one default placement workflow.

Deliverables:

- Public app scaffold.
- `README.md` with quickstart.
- `LICENSE`
- `CONTRIBUTING.md`
- `SECURITY.md`
- Default workflow config.
- Installer skeleton.
- Demo seed.

### Phase 3: Domain Kernel

Goal: implement the placement workflow without UI complexity.

Actions:

- Model candidates, companies, processes, roles, transitions, and events.
- Implement config-driven transition guards.
- Implement scoped permission checks.
- Implement simple eligibility, offer, opt-out, and override checks where needed.
- Implement next-company routing.
- Implement conflict detection.
- Implement workflow config loading and validation.
- Add tests from the transition matrix and dry-run cases.

Deliverables:

- Transition engine.
- Permission engine.
- Configuration validator.
- Event log schema.
- Projection schema.
- Test suite.

### Phase 4: Imports And Admin

Goal: make the app usable by a college before placement day.

Actions:

- Define CSV templates.
- Define configurable import schemas and field mappings.
- Build import preview and validation.
- Build candidate import.
- Build company import.
- Build shortlist/schedule import.
- Build user/role setup.
- Build first-run installer.
- Add import batch rollback.

Deliverables:

- Import console.
- CSV templates.
- Field-mapping console.
- Web installer.
- Validation reports.
- Synthetic sample imports.

### Phase 5: Operator MVP

Goal: recreate the live placement-day value.

Actions:

- Build login and role-based navigation.
- Build configurable control room board.
- Build configurable company tracker board.
- Build configurable mobile/floor tracker board.
- Build notification center.
- Build candidate trace.
- Build placement counter.
- Build preference/backloop console.
- Add audit views.

Deliverables:

- Usable local MVP for a synthetic placement day.
- Seeded demo scenario.
- End-to-end tests for primary workflows.

### Phase 6: Live Board Reliability

Goal: make the system reliable under live use.

Actions:

- Add simple timed refresh or lightweight polling for live boards.
- Add optimistic locking.
- Add idempotency keys.
- Add stale-page warnings.
- Add health checks.
- Add backup/restore commands.
- Add export snapshots.
- Add operational runbook.

Deliverables:

- Reliable live board.
- Degraded-mode behavior.
- Backup/restore docs.
- Placement-day runbook.

### Phase 7: Generalization

Goal: make it usable by institutions beyond the original context.

Actions:

- Externalize role, status, stage, transition, and permission configuration.
- Add branding, terminology, timezone, calendar, and cycle configuration.
  Text-only site identity is now first-run/Admin configurable and portable.
  Basic terminology labels for candidate/candidates and company/companies are
  now first-run/Admin configurable and portable. Basic non-operating
  weekday/date guardrails are now first-run/Admin configurable, portable, and
  checked in readiness. Logo/image branding and richer calendar controls remain
  later generalization work.
- Expand configurable candidate, company, role/profile, panel, offer, and
  board/card fields beyond the current basic board-card setting.
- Add configurable import mappings, validation profiles, and export definitions.
- Expand report/export configuration beyond the current fixed full,
  operations, and summary CSV export profiles.
- Expand the shipped starter workflow/configuration examples for common Indian
  college styles.
- Expand configuration validation beyond the current portable config validator,
  placement-day freeze, and backup-before-change safeguards.
- Add documentation for adapting the defaults without editing source code.

Deliverables:

- Configuration files and admin screens.
- Multi-institution examples.
- Starter workflow/configuration examples.
- Config validation checks.
- Config migration guide.

### Phase 8: Distribution

Goal: make it easy to adopt.

Actions:

- Publish versioned releases.
- Keep the current one-command PHP server/demo start polished.
- Add zip/tar release download.
  The current package command now writes a tarball plus `.sha256` sidecar, and
  `php placement verify-package` checks the checksum and archive boundary.
- Add deployment guide for PHP built-in server, Apache, and Nginx.
  The current docs now include `public/` document-root guidance plus Apache and
  Nginx starter configs under `examples/deployment/`.
- Add optional container image after the plain PHP release works.
- Keep the current backup-first `php placement upgrade` flow and upgrade guide
  polished as releases evolve.
- Add migration guide from CSV/legacy data. `docs/migration-from-legacy.md`
  now documents a public-safe fresh-install migration path.
- Add public roadmap and issue templates.

Deliverables:

- Public release.
- Install docs.
- Upgrade docs.
- Example deployment.
- Optional container smoke-test docs.

## License Recommendation

Decide this early.

Good options:

- MIT: easiest adoption, minimal friction.
- Apache-2.0: permissive with explicit patent grant.
- AGPL-3.0: requires network-hosted modifications to remain open, but may reduce institutional/vendor adoption.

If the goal is maximum college adoption, use Apache-2.0 or MIT. If the goal is
to prevent private SaaS forks from improving the system without contributing
back, consider AGPL-3.0.

## Governance Recommendation

Before announcing:

- Add a code of conduct.
- Add issue templates.
- Add a security disclosure path.
- Add a public roadmap.
- Mark the project as alpha until at least one synthetic placement-day demo drive passes.
- Keep maintainership clear: this is a serious operational tool, not a toy repo.

## Acceptance Tests To Derive From Legacy Dry Runs

The dry-run documents should become tests and demo scenarios, especially:

- No candidate wants a company.
- Company interviews run long.
- Candidate is unavailable due to room, illness, or special handling.
- GD panel sizes change dynamically.
- Company requests non-shortlisted candidates.
- Company rejects or refuses candidates.
- Candidate has conflicting company movement.
- Candidate chooses another company.
- Company threatens to leave or pauses panels.
- Panelist breaks create queue changes.
- Last panels create bottlenecks.
- Day spillover changes routing.
- Placement creates downstream removal from other active queues.

These scenarios are the product moat. They encode the lived pressure of the
placement process better than generic CRUD requirements.

## What Not To Do

- Do not publish the current archive as-is.
- Do not commit raw SQL, CSV, XLSX, DOCX, ZIP, RAR, or 7z files containing legacy data.
- Do not spend weeks prettifying the old PHP UI.
- Do not make the wide `stat1` to `stat11` shape the new source of truth.
- Do not rely on browser refreshes for live operations.
- Do not keep institution-specific names, logos, or paths in source.
- Do not treat security as a post-MVP task.
- Do not build a generic applicant tracking system and lose the placement-day workflow.

## Current Alpha Hardening Steps

The original immediate setup items are now represented by the public scaffold,
private archive boundary, documentation set, starter workflows, tests, and
release package commands. The next useful work is release hardening rather than
more stack selection.

1. Keep the dependency-light release gate green: `php tests/run.php`,
   `php placement publication-check`, `php placement package`, `php placement
   verify-package`, and `php placement smoke-http` with a restricted-role
   account when the test install has one.
2. Run the generated browser QA plan against a large synthetic dataset on the
   target desktop and phone-width browsers before tagging a public alpha.
3. Decide whether the demo database shipped for screenshots should be
   configuration-frozen, or document clearly that it is an editable local demo.
4. Tag the first alpha only from a verified tarball and checksum sidecar.
5. Treat real SMS/WhatsApp provider approval and first live probes as
   institution-local deployment work, not source-code fixtures.
6. Continue mining old dry-run documents only into synthetic tests and examples.
7. Keep Composer, Node, queues, and OCI application images deferred until real
   adopters prove they are worth the added moving parts. Apple Container remains
   an optional developer tool for disposable PostgreSQL qualification; it is not
   part of the application runtime or the normal SQLite self-hosting path.

## Working Name

Use a neutral name that is not tied to one institution.

Possible directions:

- Placement Control Engine
- Campus Placement Engine
- Placement Day Ops
- Campus Hiring Control
- PlacementOps

The old name "PLACE" has historical value, but for open source it should be
expanded or rebranded so the purpose is obvious to other colleges.

## Bottom Line

The right modernization path is not "clean up old PHP and publish it." It is:

- preserve the operating knowledge,
- extract the workflow,
- protect the historical data,
- rebuild the domain kernel correctly,
- make college-specific variation a first-class configuration layer,
- ship a safe, configurable, one-command installable tool.

That gives other colleges a real placement-day engine instead of a brittle copy
of one institution's 2016 control-room app.
