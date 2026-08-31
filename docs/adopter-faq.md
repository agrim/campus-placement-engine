# Adopter FAQ

## What outcome is the Engine designed to improve?

It helps university placement teams maximise job opportunities for candidates:
fewer missed interviews, faster clash resolution, clearer follow-up, and a more
complete view of who still needs help.

## Is it a hiring or applicant-tracking system?

No. Employers and candidates make hiring decisions. The Engine coordinates a
university's placement operations and records outcomes.

## Can we try it without candidate data?

Yes. Guided setup can create a complete synthetic drive. Use that for evaluation,
training, browser checks, and a placement-day rehearsal.

## Does it require Cloud?

No. The public Engine is the complete self-hosted product. Cloud is an optional
managed-hosting control plane and cannot own placement behaviour or institution
records.

## What does self-hosted mean?

The university or its approved host runs PHP and either SQLite or PostgreSQL.
The default installation has no mandatory external service, container runtime,
frontend build, queue server, or Cloud dependency.

## Is it production-ready?

The current release is an evaluation alpha suitable for synthetic evaluation and
a controlled institutional pilot. A real-data deployment also needs the exact
environment, backup/restore, security/privacy, browser/accessibility, capacity,
and operating evidence listed in [production-readiness.md](production-readiness.md).

## How are upgrades protected?

`php placement upgrade` verifies database identity, writes a checksummed backup,
applies only known migrations under a lock, and runs readiness checks. Current CI
also upgrades a database created by the exact previous public release package.

## Can we integrate another system or build a dashboard?

Yes, through the versioned institution-local API, signed webhooks, and public
event contract. The API is deliberately narrow rather than generic database
CRUD. See [API v1](api/v1.md) and [compatibility.md](compatibility.md).

## Can third parties extend placement behaviour?

Approved modules can extend the Engine within documented capability and event
boundaries. External integrations should use the API/events/webhooks. Arbitrary
third-party PHP is not loaded into a live Engine process.

## Where should we ask about a pilot?

Start a public
[GitHub Discussion](https://github.com/agrim/campus-placement-engine/discussions/new?category=general).
Do not post candidate details, credentials, or confidential university
information.
