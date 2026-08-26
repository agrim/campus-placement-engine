# Implementation Status

Date: 2026-07-18

> Repository-boundary update, 2026-08-26: hosted control-plane, tenant
> provisioning, fleet-operation, and support-management implementation now lives
> in a separate private Cloud repository. This public repository retains the
> independently installable Engine and its versioned managed-hosting contract.
> The dated verification evidence below describes the combined implementation
> before that mechanical extraction; equivalent cross-repository contracts must
> continue to pass.

## Implemented

### Career Services Portal Productization

- Modular-monolith portal kernel with institution context, scoped settings,
  people/student profiles, organizations, memberships, capabilities, module
  registry/lifecycle, events, privacy, and portability contracts.
- Placement Operations extracted behind a first-party module boundary while
  retaining the original low-overhead server-rendered interface.
- Career Advising proof module with appointments, staff notes, follow-up tasks,
  independent routes/capabilities, event subscription, privacy, portability,
  migrations, and hosted entitlement behavior.
- Durable placement model for cycles, participants, opportunities, offers,
  people, organizations, opaque public IDs, and legacy-reference bridges.
- Immutable versioned workflows with named branches, semantic states, guards,
  effects, simulation, explicit migration, and per-application version pinning.
- Custom published workflow definitions supported by standalone configuration
  and full logical portability round trips.
- Versioned checksum-backed portability bundle for core plus every installed
  module, excluding users, passwords, sessions, credentials, delivery state,
  billing, and hosted control metadata.
- SQLite and PostgreSQL connection providers, ordered migrations, and one shared
  database behavior contract in CI.
- PostgreSQL custom-format backup and single-transaction restore alongside
  atomic SQLite backup/restore, checksum verification, and restore-safety copy.
- Metadata-only hosted control plane with plans, domains, deployments,
  entitlements, jobs, backup records, audited support grants, and no operational
  student tables.
- Exact-domain tenant resolution, environment-only database secret mapping,
  per-tenant database identity verification, cross-tenant session clearing,
  idempotent provisioning, backup-first fleet upgrades, and fail-closed missing
  or swapped tenant databases.
- Portal-wide privacy reports and one-transaction erasure across installed
  modules, including disabled modules.
- Database login throttling, database-backed sessions with request locking,
  trusted-proxy opt-in, HTTPS HSTS, CSRF-protected logout, and signed/replay-safe
  institutional identity-proxy assertions.
- JSONL structured request logs with request IDs and common-secret redaction;
  liveness, readiness, token-protected metrics, outbox backlog, and dead-letter
  gauges.
- Transactional domain-event outbox worker with portable claims, retry backoff,
  dead letters, JSONL or signed HTTPS delivery, and stable event IDs.
- Bounded read-only HTTP load probe with optional `curl_multi` concurrency and a
  PHP streams fallback.
- Hosted operations, disaster recovery, module development, security operations,
  testing, environment, deployment, and dual-distribution runbooks.

- Plain PHP + SQLite app scaffold.
- Browser installer at `public/install.php`.
- Shared PHP/SQLite/writable-directory system preflight in the CLI doctor and
  browser installer.
- Installer lock that refuses second-run setup once a database has an
  `installed_at` marker.
- Transactional first-run setup after migrations, with IANA timezone validation
  and the installer lock written only after all setup stages succeed.
- CLI helper at `placement`.
- One-command local development server wrapper at `php placement serve`, backed
  by PHP's built-in server.
- Non-demo CLI first-run installer for technical setup.
- SQLite migrations.
- Placement cycle name, type, and date-range settings in browser install,
  CLI install, Admin settings, portable configuration snapshots, starter
  configuration templates, and upgrade backfill migration.
- Browser installer, CLI installer, and Admin-configurable local terminology
  labels for candidate/candidates and company/companies, rendered server-side
  and portable through configuration snapshots and starter templates.
- Browser installer, CLI installer, and Admin-configurable text-only site
  identity for site name, tagline, public placements page title, and candidate
  status page title, rendered server-side and portable through configuration
  snapshots and starter templates.
- Browser installer, CLI installer, and Admin-configurable non-operating
  weekday/date guardrails for round schedule readiness checks, portable through
  configuration snapshots and starter templates.
- Browser installer, CLI installer, and Admin-configurable audit request
  metadata retention policy, defaulting to no IP/user-agent retention and
  portable through configuration snapshots and starter templates.
- Configuration freeze in Admin, portable configuration snapshots, readiness
  checks, and CLI configuration import guard.
- Admin user creation with `password_hash()`.
- Session login/logout.
- Hardened PHP session cookies with strict cookie-only sessions, HttpOnly,
  SameSite=Lax, HTTPS-aware Secure cookies, reverse-proxy override, and session
  ID rotation on successful login.
- Baseline browser security headers for CSP, frame protection, MIME sniffing,
  referrer policy, browser device permissions, and private no-store caching.
- Fail-closed route capability checks for the board, candidate trace,
  notification detail, and candidate status surfaces.
- Aggregate-only anonymous public placement results without candidate names or
  identifiers; candidate-specific lookup requires a placement capability.
- Shared outbound HTTP policy for notifications and domain events: HTTPS by
  default, public-network resolution and address pinning, no proxy inheritance,
  no URL credentials or redirects, bounded payloads, and 2xx-only success.
- Spreadsheet-formula neutralization for human-facing CSV exports and reports.
- CSRF tokens on mutating forms.
- Default placement-day workflow configuration.
- Config-driven transition guard.
- Append-only event log for candidate-company movements.
- Control board grouped by workflow status.
- Candidate trace page.
- CSV import for candidates, companies, and shortlists.
- Candidate accommodation notes in records, imports, board cards, candidate
  trace, and CSV exports.
- Lightweight candidate/company tags in records, imports, board search, board
  cards, candidate trace, anonymization, and CSV exports.
- Dependency-free candidate/company `custom_fields_json` for simple
  institution-specific columns in records, imports, board search, optional
  board-card details, candidate trace, CSV exports, and candidate
  anonymization.
- Server-side role masking for live board and candidate trace projections:
  company users are scoped to their company, hidden private fields are not
  searchable by restricted roles, and mobile/floor users keep accommodation
  logistics without receiving private tags or custom fields.
- Page-level role gates and matching navigation for Admin, Records, Reports,
  Import, Preferences, Wanted, and System surfaces.
- CSV import preview/validation before mutating data.
- Admin settings page for college name and timezone.
- System page with runtime, SQLite, workflow validation, dummy-data cleanup, and
  audit log.
- Guided first-run browser setup for system preflight, site identity, placement
  cycle, terminology, workflow, first admin, and a live synthetic dummy
  placement drive.
- Synthetic demo data with admin-only System cleanup that removes reserved
  dummy candidates, companies, applications, role accounts, and related
  operational rows while preserving install settings, admin users, and real
  non-demo records.
- Resettable larger synthetic QA data for local dense-board and slot-suggestion
  stress testing.
- Minimal CSS and tiny JavaScript confirmation helper.
- CSV templates.
- Deployment documentation.
- Apache/shared-hosting `.htaccess` fallback rules and Apache/Nginx deployment
  example configs that keep `public/` as the preferred document root.
- Environment-variable reference and synthetic local env template, with `.env`
  and local config files ignored by default.
- Public-safe legacy inventory and publication risk register.
- Functional spec, workflow/permission matrix, glossary, configuration
  architecture, and Indian-college template notes.
- Open-source release governance files: license, security policy,
  contribution guide, code of conduct, release checklist, and issue templates.
- Publication hygiene CLI check for required release files and ignored private
  archive/runtime data patterns.
- GitHub Actions CI workflow for PHP lint, tests, publication hygiene, demo
  install, readiness, export, CLI smoke checks, and a dependency-free
  built-in-server HTTP smoke with restricted-role access checks.
- Lightweight test runner with temp SQLite integration tests.
- Role-tuned board filtering for company, mobile, floor, placement office, and
  auditor-style users.
- User management for creating scoped non-admin users.
- Preference/backloop request and resolution flow.
- Wanted/missing-person alert flow.
- Role-targeted in-app notification center for wanted and preference notices.
- Optional external notification delivery through JSONL outbox, webhook,
  dependency-free email, SMS gateway, and WhatsApp gateway commands.
- Institution-specific external notification text templates and SMS/WhatsApp
  JSON payload templates.
- Local SMS/WhatsApp notification gateway certification preflight for recipient
  route, gateway/outbox, authorization header syntax, template rendering, JSON
  payload validity, and manual provider/consent/live-probe checklist items.
- Aggregate public placement results and authenticated candidate status lookup.
- Reports page and CLI placement report for placement counters, status counts,
  company placements, program totals, and location counts.
- CSV snapshot export profiles for full local audit, operational detail, and
  aggregate summary/report bundles.
- Backup and restore CLI commands with required SHA-256 checksum sidecars,
  restore-time integrity verification, and a restore-safety copy.
- Backup-first upgrade CLI command that checks local requirements, writes a
  timestamped SQLite backup, applies migrations, and prints readiness checks.
- CSV snapshot export CLI command.
- Publication-safe release package CLI command with SHA-256 sidecar generation
  and a package verifier for checksum, path, root, symlink, duplicate, and size
  boundary checks.
- Workflow label, color, and transition-role overrides.
- Starter workflows for default placement day, engineering multi-branch,
  internship season, simple placement-cell operations, pooled campus drives,
  virtual interviews, and walk-in/job-fair operations.
- Portable starter configuration JSON templates for every shipped workflow,
  suitable for validation and import without candidate, recruiter, user, or
  notification-recipient data.
- Legacy wide-table CSV importer, including synthetic mapping from old
  `gd_round`/`gd_panel` columns into modern GD rounds, schedules, and slot
  assignments.
- Basic placement policies for opt-out, waitlist rank, placement freeze, and
  offer-upgrade guardrails.
- Placement cleanup that returns competing active applications to idle after a
  candidate is placed, unless offer upgrades are enabled.
- Board exception action for returning active applications to idle with stale
  guard, event/audit trail, candidate location reset, and slot cancellation.
- SQLite-backed idempotency keys for live board move and return forms.
- Records maintenance page for candidate, company, and application/shortlist
  edits without a spreadsheet round trip.
- Bulk user activation/deactivation and administrator password reset flows.
- Server-side company-scope guard for transition actions.
- Server-side stale-board guard for transition actions.
- Role-specific board headings and operator guidance.
- Role-aware board filters for search, status, company, operational flags, and
  actionable queue items.
- Role-aware saved board view links and compact board mode for dense queues.
- Configurable live-board HTML refresh interval, enabled on board pages only and
  portable through configuration snapshots.
- Admin-configurable board card detail fields for showing or hiding candidate
  ID, program, tags, company, process, tracker, rounds, schedules, slots,
  panels, movement route, location, accommodation notes, custom fields, and
  waitlist rank.
- Admin-configurable custom CSV export profile using a validated list of known
  export datasets.
- Per-user persisted board default filters, compact preference, and stale-alert
  threshold.
- Queue prioritization for wanted alerts, preference requests, stale active
  applications, waitlists, and role-hot statuses.
- Active-company conflict detection for candidates moving through more than one
  company at once, including board flagging and readiness warnings.
- Company process profile fields for process type, room, tracker, active cap,
  deadline day/time, and operator notes.
- Ordered company rounds with sequence, label, type, room, duration, and
  instructions.
- CSV import for company rounds.
- Round day/room/time schedules with room, day label, start, end, capacity,
  active/paused/break status, and notes.
- CSV import for round schedules.
- Candidate-level interview slot assignments tied to existing applications and
  round schedule rows.
- CSV import for interview slot assignments.
- Candidate unavailable windows for exams, accommodation breaks, travel, or
  other protected time blocks.
- CSV import/export for candidate unavailable windows.
- Read-only CLI slot suggestion report for active applications with missing
  round assignments against available company schedule capacity.
- Round-aware CLI slot planner that fills missing ordered rounds, respects
  existing assignments, schedule capacity, paused/break/cancelled schedule
  rows, company deadlines, panelist availability breaks, day-spillover
  ordering, imported candidate unavailable windows, and configurable
  candidate-level cross-company buffer conflicts, with operator-selectable
  schedule-order, earliest-time, or balanced-load scoring.
- Global optimized CLI slot planner with a bounded exact search for small
  one-round and multi-round scopes, plus a greedy constrained-assignment
  fallback for larger active-company plans.
- Explicit CLI slot assignment application for reviewed safe suggestions, with
  skipped capacity/no-schedule rows and audit logging.
- Round panel rosters with sequence, name, role, affiliation, contact,
  availability status, and notes.
- CSV import for round panelists.
- Transactional web imports after validation.
- Server-side CSV header alias normalization for common college spreadsheet
  variants such as Student ID, Roll No, Company Code, Round No, Venue, Start
  Time, Active Cap, and Waitlist Rank.
- Admin-configurable custom CSV header aliases layered on top of built-in
  aliases and portable through configuration snapshots.
- Pre-import rollback snapshots for web imports, with Import-page restore and
  CLI list/restore commands.
- Share-safe configuration JSON export/import for settings, planner/policy
  options, placement cycle name/type/date range, configuration freeze state,
  text-only site identity, local terminology labels, board refresh interval,
  board card detail fields, custom export profile datasets, custom import
  header aliases, non-operating calendar guardrails, notification text
  templates, audit request metadata retention policy, and workflow overrides
  without people or operational data.
- No-mutation configuration JSON validation CLI for portable settings, workflow
  references, cycle date format, status/transition overrides, role names,
  colors, board-card fields, text identity length, custom export dataset names,
  terminology label length, calendar weekday/date values, audit metadata mode,
  and custom import alias fields before import.
- Candidate privacy report and safety-copy-backed candidate anonymization CLI
  that redacts identity, tags, linked notes, wanted/preference queues,
  notifications, and related audit details while preserving aggregate history.
- Company active-capacity readiness warning.
- Non-operating calendar day readiness warning for configured weekdays/dates.
- Active-company conflict readiness warning.
- Open in-app notification readiness warning.
- Configuration freeze readiness check.
- Enabled SMS/WhatsApp gateway configuration readiness warning when local
  certification preflight would fail.
- Live-day readiness checks in the System page and CLI.
- Live-day backup/readiness runbook.
- CSV export documentation.
- External notification handoff documentation.
- Dry-run acceptance-test documentation.
- Dense-board browser QA runbook for desktop and phone-width checks.
- Public-safe migration guide from legacy spreadsheets, SQL dumps, and old app
  exports into fresh installs and current CSV templates.
- Dependency-free CLI HTTP smoke check for login, hardened session cookie
  attributes, browser security headers, key authenticated routes, public
  routes, optional restricted-role access blocks, and CSS assets against the PHP
  built-in server.
- Read-only CLI browser QA plan that reports dense-dataset status, readiness
  warnings, cross-browser viewport matrix, route checklist, and visual checks
  without adding a browser-driver dependency.

## Verified

### Current Release-Candidate Evidence (2026-07-18)

- All 117 broad integration and behavior tests pass on PHP 8.5.7.
- The shared database contract passes on SQLite 3.53.3 and a fresh PostgreSQL
  17.10 database hosted temporarily with Apple Container.
- The hosted isolation contract passes, and a freshly migrated PostgreSQL
  control plane contains only 11 `hosted_*` metadata tables with no candidate,
  application, or other institution-operational tables.
- PostgreSQL backup and recovery pass with a verified SHA-256 sidecar: a
  synthetic post-backup mutation is removed by restore, and the restore writes
  its own checksummed safety copy first.
- PHP syntax lint, publication hygiene, and whitespace checks pass.
- The 245-file release archive passes checksum and structural verification,
  contains every shared-hosting deny rule, and excludes runtime databases,
  private archives, local configuration, secrets, and build output.
- A clean extraction passes doctor, non-demo CLI installation, second-install
  refusal without mutation, readiness, aggregate export, backup-first upgrade,
  checksummed backup/restore, missing-sidecar rejection, corrupt-backup
  rejection, and verified restore-safety-copy creation.
- The clean extracted package also passes 14 real HTTP checks covering login,
  core authenticated routes, anonymous aggregate results, anonymous candidate
  lookup denial, and CSS delivery.
- Live semantic HTTP smoke passes 27 checks, including aggregate-only anonymous
  results, blocked anonymous candidate lookup, authenticated routes, restricted
  role gates, security headers, and static asset delivery.
- A 100-request local read-only load probe completed with 100 successes and a
  36.23 ms p95 at about 780 requests/second on the development machine. This is
  a regression baseline, not a hosted capacity claim.
- Fresh in-app browser QA passes at 1280x720 desktop and 390x844 phone width
  across 14 core routes, compact/company/wanted/conflict board states, candidate
  trace, and a non-mutating CSV preview. It found and verified fixes for the GET
  board-filter route and unnamed Admin controls. No page-level horizontal
  overflow, uncontained off-screen content, duplicate IDs, unnamed visible form
  controls, or browser-console warnings/errors remained after the fixes.
- Independent Safari and Firefox review remains a public-release sign-off item;
  the dated Chromium run below is retained as historical evidence only.

- PHP syntax lint over app, config, public, tests, and CLI files.
- Publication hygiene tests and `php placement publication-check`, including
  required release files, private/runtime ignore boundaries, forbidden archive
  extensions, and obvious public-source secret/token patterns.
- Release governance tests keep the legacy migration guide present and check it
  warns against publishing raw historical data.
- Release package tests cover tarball creation, exclusion of private/runtime
  data, checksum sidecar generation/verification, checksum mismatch rejection,
  clean extraction, standalone CLI install, readiness, and export.
- Governance tests cover required modernization extraction documents.
- CI workflow coverage for lint, tests, publication hygiene, demo install,
  readiness, export, and CLI smoke commands.
- Installer creates database, settings, admin, and demo data.
- CLI first-run installer creates a non-demo app from supplied college/admin
  options and reports installed status through `doctor`.
- Local server wrapper help documents the underlying PHP built-in server command
  and validates malformed addresses before starting a server.
- Workflow configuration validates.
- Transition engine updates application status and records events.
- Stale board transition submissions are rejected before event creation.
- Duplicate live board form submissions are ignored through idempotency keys.
- Invalid role transition is rejected.
- CSV imports upsert data and create shortlists.
- Candidate/company custom fields are covered by migration, records save,
  import, board search/projection, export, and privacy anonymization tests.
- CSV preview reports creates, updates, warnings, and errors without mutating
  data.
- Paste-only CSV import surface with no server-side uploaded file storage and
  configurable byte/row limits before preview or mutation.
- Password hashing and login path work.
- Session cookie hardening helpers are tested for HTTP, HTTPS, proxy, forced
  secure, and local HTTP modes, with a guard for login-time session ID rotation.
- Browser security header helpers and HTTP smoke coverage verify the expected
  response headers.
- CLI `doctor` reports PHP, SQLite, and writable data/database readiness and
  exits non-zero on true system requirement failures.
- Browser installer renders the same system requirements and blocks setup when
  required checks fail.
- Installer tests verify second-run setup is refused without creating users or
  overwriting settings.
- Placement report tests cover real placement transitions, company placement
  counts, program totals, location counts, and CLI CSV output.
- Local PHP server responds on `127.0.0.1:8000`.
- Authenticated board, candidate, records, reports, import, admin, and system
  pages render over HTTP.
- Installer redirects after setup.
- CSS asset serves successfully.
- User management, workflow override, policy controls, preference, wanted,
  public, student, role-scoped company board, and legacy importer routes render
  or mutate correctly through HTTP smoke checks.
- HTTP smoke verifies automatic refresh is present only on board pages when the
  board reports it enabled.
- Backup and restore commands create a backup, write and verify checksum
  sidecars, make a safety copy, and restore the SQLite database.
- Upgrade command tests cover help text, throwaway demo install, backup
  creation, migration execution, readiness output, and completion reporting.
- Import rollback tests cover pre-import snapshot creation, full SQLite restore,
  safety-copy creation, and restored-snapshot status.
- Configuration snapshot tests cover share-safe JSON export, local-only data
  exclusion, service validation without mutation, CLI validation, service
  import, CLI export/import, workflow override restoration, safety-copy
  creation, and rejection of non-portable settings.
- Starter configuration template tests and CLI validation cover every shipped
  workflow and confirm the examples stay free of demo users and external
  notification recipients.
- Privacy tests cover candidate anonymization, old lookup removal, linked queue
  closure, notification/audit redaction, safety-copy creation, privacy report
  counts, CLI confirmation enforcement, and anonymized export fields.
- Audit privacy tests cover default no-retention behavior and opt-in
  IP/user-agent capture.
- Snapshot export tests cover manifest creation, readable CSV files, round
  schedules, interview slot assignments, candidate unavailable windows, round
  panelists, full/operations/summary profiles, aggregate report CSVs, CLI
  profile selection, and password-hash exclusion.
- Policy tests cover opt-out blocking, placement freeze, and offer-upgrade
  behavior.
- Dry-run scenario tests cover placement-driven cleanup of competing active
  queues, including event/audit trail and the offer-upgrade exception.
- Records service tests cover candidate, company, round, schedule, interview
  slot assignment, panelist, and application create/update.
- Candidate accommodation tests cover import, records update, role-masked board
  projection, role-masked candidate trace projection, restricted-role search
  masking, and snapshot export headers.
- Candidate/company tag tests cover canonical and alias imports, records update,
  board projection/search, candidate trace projection, anonymization redaction,
  and snapshot export headers.
- Candidate unavailable-window tests cover import preview, college header
  aliases, `HH:MM` validation, planner conflict avoidance, blocked-row reasons,
  and snapshot export headers.
- Admin tests cover password reset and user deactivation.
- Scope tests cover company users being blocked from cross-company moves.
- Navigation/access tests cover restricted-role denial and hiding sensitive
  operational page links for company/mobile roles.
- Scenario tests cover a full synthetic placement-day path through every default
  workflow transition.
- Readiness tests cover stale active applications, open wanted alerts, and open
  preference requests, open in-app notifications, enabled SMS gateway
  configuration warnings, and capacity warnings.
- Notification tests cover wanted/preference notification creation, role
  visibility, acknowledgement, auditor mutation blocking, and source closure.
- External notification tests cover optional delivery queueing, dry-run
  inspection, JSONL outbox delivery, local email outbox delivery, export
  omission of delivery targets, local SMS/WhatsApp gateway outbox delivery, and
  readiness status.
- Notification template tests cover custom email subject/body rendering and
  custom SMS/WhatsApp message/payload rendering through JSONL outboxes.
- Notification certification tests cover SMS gateway preflight checks, rendered
  message templates, rendered JSON payloads, unsupported-channel failures, and
  manual provider approval checklist surfacing.
- Board filter tests cover search, company filter, wanted flag filter,
  conflict flag filter, actionable filtering, stale markers, saved view
  presets, compact queue presets, configurable board refresh metadata,
  configurable board card fields, persisted board preferences, per-user stale
  thresholds, idempotency keys, and urgent item ordering.
- Dry-run scenario tests cover active-company conflict surfacing on the board
  and readiness checks.
- Legacy dry-run mining tests cover GD round/panel columns importing into
  synthetic modern slot assignments.
- Dry-run exception tests cover non-shortlisted company requests, company
  rejection/refusal, stale exception guards, candidate choice conflict
  resolution, automatic next-company handoff after send-away, route projection
  on board rows and candidate traces, slot bottlenecks, dynamic panel capacity
  changes, paused-panel capacity recovery, and panelist availability breaks.
- Process configuration tests cover Records save/update, CSV import, ordered
  rounds, room/time schedules, interview slot assignments, slot suggestions,
  extended-shortlist waitlist priority, round-aware multi-round planning,
  operator planner scoring strategy, constrained global slot optimization,
  exact multi-round optimization, cross-company schedule-buffer conflicts,
  day-spillover routing, paused/break schedule skipping and recovery, company
  deadline blocking, safe slot suggestion application, panelist rosters with
  availability, board row projection, and active-capacity readiness warnings.
- Import safety tests cover preview validation, all-rows-before-write shortlist
  validation, whole-database pre-import rollback, common college CSV header
  aliases across modern import types, duplicate normalized-header rejection,
  oversized paste rejection, portable board card field settings, and portable
  configuration import validation.
- Privacy safety tests cover post-cycle candidate anonymization without
  deleting aggregate placement history.
- The 2026-06-28 Chromium QA run against a large synthetic dataset covered
  login, desktop board,
  phone-width compact board, Records, Reports, Admin, System, Notifications,
  Public, authenticated candidate lookup, authenticated Import access, page
  overflow checks, and console error checks in Chromium.
- CLI HTTP smoke covers login, hardened session cookies, browser security
  headers, authenticated app routes, reports, public/student routes, optional
  restricted-role sensitive-page blocks, board refresh/idempotency markers, and
  CSS asset delivery without Playwright or Node.
- Browser QA plan tests cover help text, invalid-format rejection, dense
  synthetic dataset detection, Markdown output, cross-browser matrix, route
  checklist, and visual checklist text without launching a browser.
- Large synthetic QA seed tests cover deterministic reset behavior, dense board
  visibility, process metadata, schedule capacity, panel rosters, existing slot
  assignments, slot suggestions, and readiness compatibility.

## External Or Future Work

- Real provider approval and first live SMS/WhatsApp probe with a
  campus-approved gateway. This is institution-local deployment work, not a
  source fixture.
- Deeper company/process modeling beyond the bounded exact planner and greedy
  fallback, such as heavyweight search/solver optimization for complex
  institutional constraints. Keep this demand-proven before adding moving
  parts.
- Independent Safari and Firefox placement-day visual sign-off using the
  generated browser QA plan and a large synthetic dataset. Current in-app
  browser desktop/phone evidence must not be represented as multi-engine proof.
- True N-minus-one package upgrade qualification after the first tagged release;
  current upgrade contracts use representative installed pre-migration states.
- Further historical dry-run mining for institution-specific synthetic edge
  cases, converted only into synthetic tests and examples.
- Real institutional pilot runs, including identity-provider certification,
  measured placement-day capacity, recovery timing, accessibility review, and
  operator feedback.
- Managed SaaS commercial operations such as billing-provider integration,
  service-level objectives, regional backup retention, status communications,
  and formal support staffing. These remain control-plane concerns and must not
  fork module behavior.
- Additional career-services modules only after a concrete institutional
  workflow is specified. Likely candidates include employer relationships,
  internships, events, mentoring, documents, and outcomes reporting; none should
  be added as empty navigation or speculative framework code.
- Final license/trademark decision after legal review. The repository remains
  under its current MIT license until that explicit decision is made.
