# Career Services Portal Architecture

Status: accepted implementation direction

Date: 2026-07-15

Implementation state: the public Engine contains the portal kernel, Placement
Operations module, Career Advising proof module, dual database contract, logical
portability bundle, and versioned managed-hosting seam. The hosted control plane
described here is implemented in a separate private Cloud repository that
consumes pinned Engine releases. The product remains pre-1.0 while broader
institutional pilots, release governance, provider integrations, and operational
recovery exercises mature.

## Product Direction

The long-term product is an open-source Career Services Portal. Placement
Operations is its first flagship module and remains the complete user-facing
product until another real module is ready.

This is not a framework-first rewrite. The portal kernel is introduced only to
give shared concepts stable ownership while the current placement behaviour is
preserved and improved.

## Architectural Decisions

### Modular monolith

Core and first-party modules run in one PHP application and one request
lifecycle. The project does not require microservices, a client-side application
framework, Redis, a queue service, or a container orchestrator.

The portal kernel owns:

- Institution identity and scoped settings.
- People, student profiles, and employer organizations.
- User memberships and capabilities.
- Module discovery and lifecycle.
- Audit, notification delivery, and portability contracts.

Placement Operations owns:

- Placement cycles and cycle participants.
- Opportunities and applications.
- Workflow definitions, instances, transitions, guards, and effects.
- Rounds, schedules, panels, and slot assignments.
- Candidate movement, offers, preferences, and wanted alerts.
- Placement-specific imports, exports, readiness, and reports.

Modules own their tables and domain behaviour. They use explicit services or
post-commit events to collaborate and must not mutate another module's tables
directly.

### Placement remains first class

Placement Operations ships with the standard distribution, is installed and
enabled by default, and remains usable without any other optional module. The
portal does not expose an empty shell or module marketplace before multiple
real modules exist.

### One institution per data plane

The application data plane always executes in one institution context.

- A self-hosted installation resolves a fixed local institution context.
- The hosted service resolves a trusted domain to a tenant deployment and
  institution database before application bootstrap.
- Placement tables do not carry a defensive `tenant_id` on every row.
- The hosted control plane contains tenant, plan, deployment, and billing
  metadata, not student or placement records.

Standard hosted tenants may share stateless application compute while retaining
separate PostgreSQL databases. Dedicated deployments remain possible for
institutions needing stronger operational isolation.

### Dual distribution without a product fork

The same core and module releases power both editions:

| Concern | Self-hosted | Hosted |
|---|---|---|
| Application | Shared PHP release | Shared PHP release |
| Primary database | SQLite | PostgreSQL |
| Institution resolution | Fixed local context | Trusted tenant resolver |
| Installation | Browser or CLI installer | Automated provisioning |
| Sessions | Local PHP files or database | Database-backed tenant session |
| Background work | CLI plus cron | Managed scheduler plus transactional outbox |
| Backups | Local backup and restore | Managed encrypted backups and restore |
| Entitlements | Installed local modules | Hosted plan and installed modules |

Hosted-only provisioning, fleet operation, billing, and support tooling belongs
to a separate control plane. Domain behaviour must not be forked or copied into
that service.

### Dependency budget

The self-hosted runtime remains PHP 8.2 or newer, SQLite, server-rendered HTML,
vanilla CSS, and minimal JavaScript. Containers remain optional deployment
packaging. Browser automation and PostgreSQL may be used in CI without becoming
self-hosted requirements.

Hosted infrastructure adds PostgreSQL and a TLS reverse proxy. Object storage
is introduced only when a real document-owning module requires it. A database
outbox and scheduler are preferred before a standalone queue service.

### Module lifecycle

Every module declares:

- Stable key, version, name, and required core version.
- Required modules and capabilities.
- Routes and navigation contributions.
- Migrations and scoped settings.
- Events, import/export handlers, and privacy handlers.
- Activation, deactivation, and uninstall behaviour.

Disabling a module preserves its data. Uninstall is explicit, backup-first, and
requires a successful export or a deliberate destructive confirmation. The
initial product supports bundled first-party modules, not arbitrary uploaded PHP
packages.

### Workflow configuration

Workflow configuration belongs to Placement Operations, not to the portal
kernel or hosted billing layer.

- Definitions are versioned and published versions are immutable.
- Applications are pinned to one workflow version.
- Transitions are named branches rather than an implicit next ordered state.
- Guards decide whether a transition is allowed.
- Effects run known application behaviours after a transition.
- Semantic state categories keep reports portable when labels differ.
- Corrections require an explicit transition, capability, reason, and audit.
- Administrators compose a vetted rule vocabulary, never arbitrary PHP or SQL.

Configuration inherits from shipped template to institution, cycle, and
opportunity. A plan entitlement may enable a module but cannot influence a
candidate-level workflow decision.

### Data portability

Self-hosted and hosted installations use a versioned logical portability bundle.
It contains the core schema version, module versions, institution configuration,
workflow definitions, module records, and attachment manifests when applicable.
It excludes secrets, sessions, notification credentials, billing records, and
hosted control-plane metadata.

Every module must provide validation, export, import, and privacy-erasure hooks.
A hosted export must be importable into a supported self-hosted release, and a
self-hosted export must be importable into the hosted service.

### Licensing and brand

The recommended release posture is AGPL-3.0-or-later for the networked server
application plus a separate trademark policy. Because this has legal and
ecosystem implications, the license text changes only after explicit legal
review. The decision must be settled before accepting outside contributions;
the current MIT file remains in force until then.

## Durable Domain Model

The current candidate and company records combine durable identities with one
placement cycle's operations. The migration target separates them:

```text
Institution
├── People
│   └── Student profiles
├── Organizations
├── Users and memberships
└── Modules
    └── Placement Operations
        ├── Placement cycles
        │   └── Cycle participants
        ├── Opportunities
        │   └── Applications
        │       └── Workflow instance and transition events
        ├── Rounds, schedules, panels, and slots
        ├── Movement and presence
        └── Offers and outcomes
```

Current integer primary keys may remain internal. Stable opaque public IDs are
used at import, export, API, and cross-database boundaries.

## Implementation Sequence

1. Preserve and characterize current placement behaviour.
2. Add an application context, connection provider, institution context, and
   capability service behind compatibility shims.
3. Create the durable institution, person, organization, cycle, participant,
   and opportunity model with a backup-first migration.
4. Extract Placement Operations into module ownership without changing UI or
   workflow behaviour.
5. Introduce workflow definitions, versions, named transitions, guards,
   effects, validation, and simulation.
6. Add module manifests, lifecycle, route/navigation contributions, and
   portability handlers.
7. Release and verify the self-hosted edition with SQLite.
8. Add PostgreSQL persistence contracts and hosted data-plane isolation.
9. Add the hosted control plane, provisioning, entitlements, fleet upgrades,
   backup/restore, and support audit.
10. Implement a second module to prove the extension contract.
11. Add portal-wide privacy, signed identity-proxy SSO, database sessions,
    monitoring, disaster recovery, outbox delivery, load probes, and security
    gates.

Steps 1 through 11 have working implementations and automated contracts across
the public Engine and separate Cloud management plane. The next work is release
qualification and pilot feedback, not a product fork or platform rewrite.

## Non-goals During Placement 1.0

- A generic BPMN or no-code automation platform.
- A single-page frontend application.
- Arbitrary third-party code upload.
- A public module marketplace.
- Shared-table multitenancy.
- Event sourcing the entire portal.
- Mandatory containers, Redis, Kubernetes, or a message broker.
- Hosted-only dependencies in the downloadable edition.

## Completion Gates

- All existing placement behaviour and legacy-derived scenarios pass.
- Existing installations migrate without data loss and with a rollback backup.
- Placement runs through the module and capability contracts.
- Published workflow versions remain reproducible and auditable.
- Clean self-hosted install and N-minus-one upgrade pass.
- SQLite and PostgreSQL persistence contracts pass in CI.
- Logical self-hosted-to-hosted-to-self-hosted round trips pass.
- Cross-tenant request, session, job, export, and support isolation tests pass.
- Backup restore, disaster recovery, privacy, load, and security gates pass.
- A second module works without modifying Placement Operations internals.

Automated and local contracts cover the implemented architecture. Remaining
release qualification includes a fresh cross-browser visual run, real
institutional pilot evidence, production identity-provider certification,
managed backup retention, and a public release decision. True N-minus-one
upgrade qualification begins after the first tagged release exists; until then,
upgrade tests use representative pre-migration fixtures. These are release,
deployment, and governance gates rather than missing architectural layers.
