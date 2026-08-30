# Production-readiness evidence and release boundary

## Current verdict

Campus Placement Engine is a dependency-light prerelease with substantial
correctness, authorization, migration, backup, API, event, webhook, and hosted
contract coverage. A tagged alpha is suitable for synthetic-data evaluation and
controlled institutional pilots. It is not, by source code or CI alone, a claim
of unattended production capacity for every university or hosting environment.

Engine remains independently self-hostable. Cloud is optional and must not own
placement-product behavior or institution-operational data.

## Evidence levels

Every release decision must distinguish these levels:

1. **Source contract:** the invariant is represented in code and documentation.
2. **Automated evidence:** deterministic tests exercise supported local/CI paths.
3. **Production-shaped evidence:** the exact intended PHP, web-server, database,
   TLS, proxy, scheduler, storage, and backup topology has been exercised.
4. **Institutional evidence:** representative users completed a controlled pilot,
   accessibility review, security/privacy review, restore drill, and operating
   rehearsal.

A lower level must never be described as proof of a higher one.

## Release blockers for real data

Before an institution uses real candidate or placement data, it must record:

- the exact Engine release, archive checksum, PHP version, database version, and
  deployment topology;
- successful `doctor`, installation or upgrade, readiness, authenticated HTTP
  smoke, and role-boundary checks;
- HTTPS and proxy configuration, secure session-cookie behavior, and protected
  health/metrics endpoints;
- administrator and service-account review, SSO/MFA policy where applicable,
  token rotation, and incident contacts;
- encrypted off-machine backups, retention, named restore operators, and a
  successful isolated restore drill against the release being deployed;
- worker scheduling, heartbeat, backlog, dead-letter, log rotation, disk, memory,
  database connection, and alert thresholds;
- representative load and capacity measurements for the institution's candidate,
  company, application, schedule, integration, and concurrent-user volumes;
- keyboard, screen-reader, zoom, responsive, and browser checks for the actual
  operator devices;
- data retention, privacy erasure, exports, backups, subprocessors, and legal
  responsibility; and
- a controlled pilot plus a documented go/no-go and rollback decision.

## Supported-runtime policy

The minimum runtime is PHP 8.2. Compatibility workflows exercise maintained PHP
minor versions separately from the full release workflow. A release may claim
only the PHP, SQLite, PostgreSQL, web-server, and browser versions listed in its
release notes and verified by the corresponding evidence. “PHP 8.2+” is not an
unbounded promise for future runtimes.

## Release sequence

1. Merge reviewed Engine changes only after required checks succeed.
2. Publish an immutable Engine tag and release artifact with checksums and release
   notes.
3. Verify installation, upgrade, backup, restore, API/event contracts, and the
   documented compatibility window from that exact artifact.
4. Update Cloud's immutable Engine pin only after the Engine release exists.
5. Run Cloud compatibility and artifact verification.
6. Canary one controlled tenant, then advance by bounded cohorts with automatic
   pause conditions.

An older binary must fail closed against unsupported newer migration history.
Do not delete migration rows, rewrite database ownership, or edit release locks
to bypass compatibility failures.

## Rollback boundary

Application rollback is allowed only when the prior release explicitly supports
the current schema and public contracts. After an irreversible migration or
external side effect, recovery is a verified restore or a forward fix, not an
unqualified binary downgrade. Preserve the pre-change backup, migration registry,
incident references, and release identity until recovery is complete.

## Repository governance gate

`main` and release tags must be protected in GitHub. Required settings include a
pull request, current required CI, code-owner review for security/persistence/
release boundaries, stale-review dismissal, resolved conversations, no force
push, no branch deletion, and restricted administrator bypass. `CODEOWNERS` and
pull-request templates document this policy but do not enforce it by themselves.

## Implementation backlog

The dependency-ordered implementation and evidence plan is maintained in
[Engine production epic #22](https://github.com/agrim/campus-placement-engine/issues/22).
That epic links repository protection, supported-environment qualification,
browser/accessibility, static and security analysis, capacity, compatibility,
privacy/incident evidence, and characterization-first maintainability work. An
item is complete only when its issue acceptance criteria and cited evidence are
satisfied; closing the issue is not itself proof.
