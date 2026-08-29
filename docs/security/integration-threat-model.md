# Integration event threat model

Status: Phase 1 public event boundary

## Assets and trust boundary

The institution database owns placement records, application aggregate state,
public event projections, delivery state, and connector credentials. A Module is
trusted Engine code running in process. An Integration is an institution-facing
connection. A Connector is the out-of-process consumer across the trust
boundary. The Cloud control plane is not a placement data plane and must not
store event payloads, aggregate IDs, or example placement records.

The only public payload in this phase is `application.status_changed` schema 1.
There is no public Engine API or API scope. Private `DomainEvent` payloads and
internal module observer APIs remain implementation details even though their
durable state shares the transactional outbox table.

## Threats and controls

| Threat | Control |
|---|---|
| A legacy or private outbox row is mistaken for a public event | External claiming requires an explicit complete `public_*` projection. The database enforces all-null or all-present columns; no inference or backfill is allowed. |
| Private fields leak through serialization | The worker enumerates only delivery and public projection columns, never selects or decodes private `payload_json`, and constructs the exact governed envelope type. |
| Numeric database keys or personal identity leak | The projection accepts canonical institution and application public IDs only. The schema exposes only status tokens; candidate/company identity, contacts, notes, actors, and transition details are forbidden. |
| Schema confusion or malicious stored content reaches a connector | PHP types and SQLite/PostgreSQL guards validate catalog name, schema, IDs, positive version, correlation ID, exact payload keys, status tokens, and a real status change. Unknown schemas fail closed. |
| A producer rewrites history after a retry | Public projection, event identity, private source identity/content, and occurrence time are immutable once a public row exists. Aggregate type/ID/version is uniquely constrained. |
| Concurrent writes skip or duplicate an aggregate version | Every status mutation uses status-and-version compare-and-swap. Database guards require exactly `old + 1` for a real status change and prohibit version-only drift. Source state, audit/history, private event, and public projection commit atomically. |
| Retry causes duplicate connector side effects | Delivery is explicitly at least once. `event_id` is stable across attempts and connectors must deduplicate and make side effects idempotent. |
| Later state overtakes unresolved earlier state | The worker blocks a later public version while an earlier version of the same application is unresolved. Other aggregates remain independent; there is no global-order claim. |
| Outbound delivery is redirected or used for SSRF | Existing outbound policy requires HTTPS by default, rejects URL credentials, fragments, redirects, and private/reserved destinations, pins the resolved address, disables inherited proxies, bounds timeouts, and accepts private networks only by explicit deployment opt-in. |
| Signing or operational secrets enter source or payloads | Secrets are environment-owned, release publication scans public files, and the public schema contains no credential fields. Private repository visibility is not treated as a secret store. |
| Restore silently forks or rewinds the stream | Restore is documented as a continuity break. Operators stop delivery and require connector checkpoint/deduplication review plus resynchronization before resuming. |
| Control-plane compromise exposes institution records | Cloud compatibility fixtures contain declarations only. Connector execution and payload handling remain in a tenant-isolated data-plane runtime; Cloud stores no event payloads or aggregate IDs. |
| Recovery replays the wrong event or changes its content | Public replay requires one exact canonical event ID, an explicit public dead letter, no retained lease, and an actor who resolves to an active local administrator. Missing, inactive, nonexistent, and non-admin actor IDs fail closed, so shell access cannot create false attribution. A transactional row lock and status guard preserve the immutable envelope, write a fixed payload-free audit, and keep later aggregate versions blocked until success. |

## Residual risks and operator duties

An acknowledged side effect can repeat if acknowledgement state is lost. A
dead-lettered earlier version intentionally stops later versions for the same
application until investigated. Operators must monitor public pending and
dead-letter metrics, protect sink files and webhook secrets, limit connector
access, rotate credentials after suspected exposure, and preserve database and
log evidence during an incident.

Schema validation does not authorize a connector. Deployers must separately
approve the destination, exact compatibility declaration, network trust
boundary, retention, and downstream access controls.
