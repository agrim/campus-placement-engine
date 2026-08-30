# Privacy And Retention

The Career Services Portal stores sensitive student and placement data. Each
institution is responsible for its local retention policy. The app provides both
a placement-specific compatibility command and portal-wide person privacy
orchestration across every installed module.

## Portal-Wide Person Privacy

Use the opaque person public ID from the durable person model:

```bash
php placement privacy-person-report --person=PERSON_PUBLIC_ID
php placement privacy-person-erase \
  --person=PERSON_PUBLIC_ID \
  --reason='Institution retention period ended' \
  --confirm=PERSON_PUBLIC_ID
```

The report includes every installed module, even when a module is disabled.
Erasure writes one checksum-backed safety backup, opens one transaction, invokes
each module privacy handler, redacts shared person/profile identity, and records
an audit event. Placement Operations and Career Advising currently participate.

## Privacy Report

```bash
php placement privacy-report
```

The report gives simple counts for total candidates, anonymized candidates,
identifiable candidates, placed identifiable candidates, and open wanted or
preference queues.

## Candidate Anonymization

```bash
php placement anonymize-candidate C001 --confirm=C001
```

The explicit `--confirm` value must match the candidate external ID. Before any
mutation, the command writes a driver-appropriate safety backup under
`data/privacy/`.

Anonymization:

- Replaces the candidate external ID with `ANON-<id>`.
- Replaces the candidate name with `Anonymized Candidate`.
- Clears program, tags, accommodation notes, and current live location.
- Marks the candidate opted out and records `anonymized_at`.
- Keeps application rows, placement company links, and movement status history
  for aggregate reporting.
- Redacts linked event notes, slot-assignment notes, wanted reasons, preference
  notes, candidate-specific notification text/payloads, and related audit-log
  details.
- Closes open wanted alerts and preference requests for that candidate.

After anonymization, student lookup with the old external ID no longer resolves.

## Audit Request Metadata

By default, audit log entries do not retain request IP addresses or user-agent
strings. Administrators can opt in from Admin, the browser installer, CLI
install, or portable configuration by setting `audit_request_metadata` to
`ip`, `user_agent`, or `both`. The default and starter-template value is
`none`.

If enabled, request metadata appears in the local audit log and in the `full`
CSV export's `audit_logs.csv`. It is still excluded from `operations` and
`summary` exports because those profiles are meant for lighter placement-day
handoff and aggregate reporting.

## What It Does Not Do

Privacy erasure does not erase whole-company or aggregate placement history. It
also does not rewrite backups, exports, screenshots, or files that were already
created outside the live database. Apply the same retention policy to
`data/backups/`, `data/exports/`, and any institution-managed copies. Backup
checksum sidecars prove file integrity, not confidentiality; use approved
institutional encryption for retained or off-machine backups.

Signed webhook delivery rows retain no request body. They reference the
immutable governed public projection and are pruned in bounded batches 90 days
after creation once succeeded or dead-lettered. Endpoint URLs and encrypted
signing-secret metadata remain institution-local configuration and are excluded
from portability exports. A downstream Connector is a separate data controller:
its event-ID deduplication records, bodies, logs, and side effects require an
institution-approved retention and erasure policy.

The public API control boundary stores service-account metadata, exact
scope grants, clear random lookup IDs, 32-byte keyed verifiers, lifecycle
timestamps, keyed rate-limit/source fingerprints, and fixed redacted request
audit classifications. Transition commands add only a purpose-separated keyed
idempotency hash, canonical request hash, aggregate public ID, lifecycle, and
the exact canonical public response/ETag for at most 48 hours. The boundary
never stores the clear idempotency key, token secret, raw authorization header,
raw source address, request URL/query/body, or candidate/employer identity.
Expired command keys, rate-limit buckets, and 90-day request-audit rows are
removed in bounded institution-local batches with
`php placement api-prune --actor-user-id=USER_ID`.
Service-account and token lifecycle metadata remains until institution policy
authorizes a separately governed deletion feature; revoke instead of deleting
operational evidence.

API opportunity responses are institution-owned placement operations data.
Application IDs, participant IDs, opportunity links, status, versions, and
timestamps are pseudonymous placement records and remain restricted
institutional data even though direct identity fields are excluded. API v1
never emits candidate/person/student-profile names, external IDs, contact,
program, tags, accommodation, custom fields, notes, waitlist/offer details,
workflow internals, legacy identifiers, or numeric database keys. Downstream
consumers are separate retention and access-control domains and must cover API
responses, checkpoints, caches, backups, and logs in institution policy.

## Recommended Cycle-End Flow

1. Run `php placement backup`.
2. Run `php placement export` if an authorized audit export is required.
3. Run `php placement privacy-report`.
4. Run portal-wide person erasure for people whose retention window has ended.
5. Run `php placement privacy-report` again.
6. Store or destroy backups/exports according to the institution's policy.
