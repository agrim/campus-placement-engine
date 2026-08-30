# Integration event threat model

Status: Phase 2 signed delivery plus governed read-only API v1

## Assets and trust boundary

The institution database owns placement records, application aggregate state,
public event projections, subscription/delivery state, and encrypted Connector
credentials. A Module is trusted Engine code running in process. An Integration
is an institution-facing connection. A Connector is the out-of-process consumer
across the trust boundary. The external encryption keyring remains outside the
database. The Cloud control plane is not a placement data plane and must not
store endpoint URLs, secrets, event payloads, delivery bodies, raw diagnostics,
aggregate IDs, or example placement records.

The public surfaces are `application.status_changed` schema 1 and the five
GET/HEAD-only API v1 paths for opportunity/application reads. Private
`DomainEvent` payloads, internal module observer APIs, candidate resources, and
command/write routes remain implementation details or explicit non-goals.

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
| A placement transaction commits without endpoint delivery work | Eligible active/degraded subscriptions and event selection are captured as per-subscription delivery rows inside the same source transaction. Capture locks eligible subscription rows before insertion, serializing with revoke so unresolved work cannot appear after revocation. A capture fault rolls back the source event; no network, transport, or secret object is constructed in that transaction. |
| One slow or failing endpoint blocks another | Delivery state, lease fencing, retry, circuit, backlog, health, and replay are per subscription. Endpoint and institution concurrency are bounded, a persisted cyclic cursor prevents repeated short runs from restarting at the lowest subscription, and the worker continues after one endpoint fails. |
| Later state overtakes unresolved earlier state | The worker blocks a later public version only for the same subscription and application aggregate. A delivery deliberately terminated by revocation is never resumed or replayed and is treated as a continuity boundary for future events after clean reactivation. Other aggregates and subscriptions remain independent; there is no global-order claim. |
| Outbound delivery is redirected, rebound, or used for SSRF | Every attempt resolves and validates all A/AAAA answers, rejects credentials, fragments, redirects, ambiguous hosts, and private/reserved ranges, and pins one validated address into the TLS connection. Proxy inheritance is disabled and request/response size plus connect/total time are bounded. Self-hosted private delivery is an explicit per-subscription administrator choice and admits only RFC 1918/ULA; managed mode is public-egress only. |
| Invalid TLS is silently downgraded | The transport verifies the peer and hostname, restricts protocols to the configured scheme, follows no redirects, and treats TLS failure as terminal/degraded. |
| A validation probe leaks placement data or is mistaken for a real event | Validation uses a separately typed signed `webhook.validation` challenge with no application, aggregate, candidate, employer, or example placement values. Activation requires a recent successful challenge. |
| Signing secrets are disclosed from storage, logs, UI, or support output | Secrets are random one-time reveals. AES-256-GCM ciphertext binds institution, subscription, and explicit key version as associated data. There is no plaintext fallback; URL, body, secret, and raw failure values are excluded from audit, diagnostics, health, metrics, and Cloud. |
| Secret rotation creates an unbounded acceptance window | At most the current and previous secret are encrypted. Both signatures are emitted for 24 hours; another rotation is refused during overlap, expired metadata is cleared, and revoke clears both immediately. |
| A stale worker acknowledges after disable, revoke, or replay | Claims use random tokens plus monotonic lease generations. Every state mutation is token/generation fenced; revoke steals/fences active claims and dead-letters unresolved work. An already in-flight HTTP request remains possible and is covered by consumer verification/deduplication. |
| A forged or replayed request causes side effects | HMAC-SHA256 binds event ID, Unix timestamp, and exact raw body. Consumers use constant-time comparison, reject excessive clock skew, validate the schema/header identity, and transactionally deduplicate event IDs. |
| Signing or operational secrets enter source or payloads | Runtime keyrings are environment/secret-manager owned, publication scans public files, and the public schema contains no credential fields. Private repository visibility is not treated as a secret store. |
| An API token is recovered from the database, browser history, logs, audit, or support output | Token secrets are 32 random bytes revealed once. Storage holds only a clear random lookup ID and 32-byte HMAC verifier under an external versioned keyring. Management never flashes the token, all management responses are no-store, logs/audit are fixed and redacted, and health/metrics expose aggregate counts only. |
| Unknown API token IDs become an enumeration oracle | Strict parsing uses the same generic denial, performs a dummy HMAC for unknown or malformed lookups, and never returns account state. A missing referenced key version is an aggregate readiness failure rather than credential-specific disclosure. |
| A broad user role or wildcard silently grants API access | API principals are service accounts, never browser users. Exact stored scopes map fail closed to an enabled Placement module and a durable capability catalog row; user-role inheritance and wildcard scope syntax are not consulted. |
| Rotation leaves an unbounded credential overlap | Expiry is mandatory (90-day default, 365-day maximum), the previous current token receives no more than 24 hours of grace, database guards allow only one current and at most two unrevoked tokens, and concurrent rotation serializes on the account. |
| A browser session, cookie, query token, CORS preflight, or alternate method crosses into the API | API paths are detected before session startup. Authentication accepts only exact Bearer syntax; query/cookie/session credentials are ignored, CORS headers are absent, and only GET/HEAD are registered. Errors are fixed JSON with no redirect or flash. |
| An API read crosses institution or privacy boundaries | Opportunity/application queries use explicit current-institution joins and exact public allowlists. Cross-institution IDs return the same 404 as missing rows; numeric IDs, candidate/person/contact/profile fields, notes, workflow internals, and broad PlacementService projections are never selected. |
| A cursor is forged, replayed across a route/tenant, or expands into an unbounded export | Canonical bounded cursor payloads use a purpose-separated HMAC with explicit key version and bind institution, route/resource, normalized filter, upper snapshot, and last stable tuple. Collections default to 50 and cap at 100. |
| Oversized or malformed input consumes unbounded work | Request targets, query strings, headers, parameter count/value lengths, cursor payloads, and bodies have fixed bounds. Unknown, duplicate, array-shaped, malformed, and incompatible parameters fail before data reads. |
| Rate-limit or API audit state becomes a secondary personal-data store | Buckets retain keyed institution/token/source dimensions only. Request audit accepts fixed route, scope, outcome, status, and detail classes with a keyed source fingerprint; raw addresses, URLs, queries, bodies, headers, tokens, and placement identities are excluded and retention is bounded. |
| Restore silently forks or rewinds the stream | Restore is documented as a continuity break. Operators stop delivery and require connector checkpoint/deduplication review plus resynchronization before resuming. |
| Control-plane compromise exposes institution records | Cloud compatibility fixtures contain declarations only. Connector execution and payload handling remain in a tenant-isolated data-plane runtime; Cloud stores no event payloads or aggregate IDs. |
| Recovery replays the wrong endpoint event or changes its content | Webhook replay requires one exact canonical delivery ID, a replayable per-subscription dead letter, no retained lease, an active/degraded subscription, and an actor who resolves to an active local administrator. Revocation-terminal deliveries are never replayable. A transactional row lock and generation fence preserve the immutable source envelope, write a fixed payload-free audit, and keep later versions for only that subscription/aggregate blocked until success. |

## Residual risks and operator duties

An acknowledged side effect can repeat if acknowledgement state is lost. API
pagination is a bounded upper-watermark traversal, not a multi-request database
snapshot; consumers must upsert by public ID and perform overlap recovery after
updates, restores, or cursor loss. A
dead-lettered earlier version intentionally stops later versions for the same
subscription and application until investigated. DNS and certificate validity
can change after validation, so every real attempt rechecks policy. An HTTP
request already in flight at revoke cannot be recalled. Operators must monitor
pending, dead-letter, circuit, key-version, and heartbeat health; protect
diagnostic files and keyrings; limit Connector access; rotate or revoke after
suspected exposure; and preserve database/log evidence during an incident.

Schema validation does not authorize a connector. Deployers must separately
approve the destination, exact compatibility declaration, network trust
boundary, retention, and downstream access controls.
