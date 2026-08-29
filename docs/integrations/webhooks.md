# Signed webhook integrations

Status: institution-local delivery for `application.status_changed` version 1

Signed webhooks are configured by an institution administrator on the
**Integrations** page. Engine stores subscription and delivery state in the
institution data-plane database and sends directly from that Engine runtime.
Self-hosted Engine does not require Cloud. In managed hosting, Cloud may report
aggregate desired state or health, but it must never receive endpoint URLs,
signing secrets, event bodies, application or aggregate IDs, delivery rows, or
raw diagnostics.

In product language, an **Integration** is the institution-facing connection.
A **Connector** is the out-of-process receiver. A **Module** remains trusted
in-process Engine code; webhooks do not load modules, plugins, or arbitrary
code.

## Administrator workflow

Only an active local administrator with `portal.integrations.manage` can open
the page or mutate an integration. Every browser mutation is POST-only and
CSRF-protected.

The visible lifecycle contains only action-changing states:

| State | Meaning and administrator action |
|---|---|
| `disabled` | Delivery and future event capture are off. Validate again before activation. |
| `setup_required` | Complete external encryption-key or signing-secret setup, then validate. |
| `validating` | The separate signed validation challenge succeeded or is being retried. Activate within 24 hours of success. |
| `active` | Future selected events are captured for this endpoint. |
| `degraded` | Delivery failed or dead-lettered. Review the redacted reference and backlog; disable before revalidating. |

Create and name an endpoint, review its permission, and select
`application.status_changed` version 1. The first signing secret is generated
with at least 256 bits of randomness and displayed exactly once. Copy it to the
Connector's secret manager; it cannot be recovered from Engine. If the external
encryption keyring is missing, creation safely remains `setup_required` and the
base Engine install continues to work.

Validation sends a separately typed `webhook.validation` JSON challenge. It
contains no placement example, event body, application ID, or aggregate ID and
must not be processed as `application.status_changed`. Activation requires an
endpoint, selected supported event, encrypted signing secret, a validation that
succeeded within the previous 24 hours, and the outbound TLS/network policy.

The page reports last validation, success and failure, backlog, dead-letter
count, worker heartbeat, encryption-key version, and opaque support references.
It shows only a redacted endpoint origin. Disable pauses retained backlog and
stops capture of future events. Revoke immediately clears both encrypted secret
slots, fences claimed work, and dead-letters unresolved deliveries. An HTTP
request already in flight cannot be recalled; the Connector must still verify
and deduplicate it.

## Wire contract and signing

The request body is the exact `PublicEventEnvelope` JSON documented in
[`events.md`](events.md). Engine sends these headers:

```text
CPE-Webhook-Id: event_ID
CPE-Webhook-Timestamp: UNIX_SECONDS
CPE-Webhook-Signature: v1=HEX_HMAC_SHA256
CPE-Webhook-Schema: application.status_changed;version=1
```

The signature input is exactly, with literal full stops and no newline:

```text
<event-id>.<timestamp>.<raw-request-body>
```

The HMAC key is the complete revealed `whsec_...` value. The digest is lowercase
hexadecimal HMAC-SHA256. During the 24-hour signing-secret rotation overlap,
the signature header contains the current signature followed by the previous
signature:

```text
CPE-Webhook-Signature: v1=<current-hex>,v1=<previous-hex>
```

A Connector should accept any one valid `v1` value while it retains the
corresponding current or previous secret. Engine removes the previous encrypted
secret after the overlap. Rotation cannot be stacked during an existing
overlap. Revoke is the immediate-compromise action.

Verify before decoding or acting:

1. Read and retain the raw request bytes; do not re-encode parsed JSON.
2. Require the exact event ID, integer timestamp, schema header, and one or two
   bounded `v1` signatures.
3. Reject timestamps more than five minutes from the receiver's trusted clock.
4. Compute HMAC-SHA256 over the exact input and use a constant-time comparison
   against each supplied `v1` digest.
5. Parse the JSON only after verification, require the body `event_id` to match
   the header, and validate the governed version 1 schema.
6. Transactionally deduplicate by event ID before any side effect. Return 2xx
   for an already completed event.

A dependency-light PHP receiver skeleton is in
[`examples/integrations/verify-webhook.php`](../../examples/integrations/verify-webhook.php).
It reads at most 1 MiB plus one rejection sentinel byte, so an oversized request
is rejected without buffering the rest of its attacker-controlled body.

## Delivery behavior

Delivery is at least once. A lost acknowledgement can repeat a completed side
effect. Engine treats any 2xx response as success and never reads or logs the
response body. Requests and responses are capped at 1 MiB; headers and timeouts
are bounded.

Network timeouts and HTTP 408, 425, 429, 500, 502, 503, and 504 retry. Most
other 4xx responses receive one bounded retry and then become terminal. HTTP
410, redirect responses, invalid TLS, outbound-policy failures, and oversized
responses are terminal and degrade the integration for review. TLS verification
is never downgraded.

The backoff bases are approximately 1 minute, 5 minutes, 15 minutes, 1 hour,
4 hours, 12 hours, and 24 hours, each with bounded 20 percent jitter; later
attempts remain at the 24-hour base. The default maximum is 10 attempts. Three
consecutive failures open a bounded per-endpoint circuit. Endpoint and
institution concurrency limits prevent one receiver from consuming all worker
capacity. Claim accounting is serialized inside a short database transaction,
including across PostgreSQL worker processes; no network request runs while
that coordination is held. The singleton worker heartbeat also stores the last
successfully claimed subscription. Heartbeat updates preserve that cursor, so
repeated short `work(1)` runs rotate durably rather than restarting at the
lowest subscription ID. Concurrent failure completions lock their endpoint
health row, so increments and circuit opening cannot overwrite one another.
One failing endpoint cannot block another endpoint.

Ordering is per subscription and application aggregate. A later aggregate
version waits for an earlier unresolved delivery to that same subscription;
different applications and different subscriptions continue independently.
Candidate selection takes one eligible row per subscription before considering
deeper rows from any subscription, then starts cyclic subscription order just
after the persisted cursor. An old endpoint backlog therefore cannot monopolize
the bounded candidate window, including across separate worker invocations. No
global ordering is promised.

Public-event capture and revoke serialize on the subscription row. PostgreSQL
capture locks every eligible subscription with `FOR UPDATE` in ascending ID
order before inserting deliveries; SQLite retains its `BEGIN IMMEDIATE` write
serialization. Revoke and completion use the same subscription-then-delivery
order. Consequently revoke either fences a delivery committed ahead of it or
makes the subscription ineligible before capture, and a revocation-era delivery
cannot become deliverable merely because the subscription is configured again.

Dead-letter replay selects one exact `whdel_...` delivery and requires explicit
local-administrator attribution. It reconstructs the same immutable event body
from the public outbox projection, fences stale claims, and writes a fixed,
payload-free audit entry. Replay does not provide exactly-once semantics. A
dead-lettered earlier version intentionally continues to block later versions
for that subscription and aggregate until the replay succeeds. Revocation is
the deliberate exception: deliveries terminated with `subscription_revoked`
cannot be replayed, do not resume after reactivation, and do not block future
events captured after a clean validation and activation.

Completed and dead-letter delivery rows are retained for 90 days, longer than
the default retry horizon, then pruned in bounded batches. Event bodies are not
copied into delivery rows.

## Outbound network policy

HTTPS and approved port 443 are the defaults. At send time Engine resolves every
A and AAAA result, rejects any set containing loopback, link-local, multicast,
unspecified, private, documentation, benchmark, shared, or reserved space, and
pins the validated address into the connection. It follows no redirect,
inherits no proxy, verifies the TLS peer and hostname, and never downgrades the
protocol.

Self-hosted administrators may explicitly select a private-network endpoint.
That permits only RFC 1918 IPv4 or IPv6 ULA in addition to public addresses;
loopback, link-local, multicast, unspecified, and other reserved ranges remain
forbidden. Self-hosted HTTP additionally requires
`CPE_WEBHOOK_ALLOW_HTTP=1` and an approved HTTP port. Managed mode is always
public-egress only, regardless of those settings. Re-resolution and validation
happen for every attempt, so a changed or rebound DNS answer fails before the
connection.

## Encryption keyring

Signing secrets use AES-256-GCM authenticated encryption at rest. The database
stores ciphertext, nonce, authentication tag, and explicit key version only.
Associated data binds the institution public ID, subscription public ID, and
key version. There is no plaintext fallback.

The keyring remains outside the database:

```text
CPE_WEBHOOK_ENCRYPTION_KEYS=2026-08=<unpadded-base64url-32-bytes>;2026-11=<unpadded-base64url-32-bytes>
CPE_WEBHOOK_ACTIVE_KEY_VERSION=2026-11
```

Versions are 1-32 characters from `A-Z`, `a-z`, `0-9`, `_`, `.`, or `-`.
Configure one to eight unique entries. Each value must be canonical unpadded
base64url for exactly 32 bytes. The active version must name an entry. OpenSSL
AES-256-GCM availability is checked only when integration secrets are used, not
as a base-install requirement.

For external encryption-key rotation, add the new version, make it active,
rotate each integration's signing secret so new ciphertext uses that version,
wait through the signing overlap and outstanding retry horizon, verify health,
then remove the unused old encryption key. Removing a version still referenced
by active or overlap ciphertext makes readiness fail closed.

## Worker operations

Run one short worker command from the scheduler:

```bash
php placement work-integrations --limit=100
```

For ordinary shared hosting, schedule it every minute with the same PHP binary,
working directory, database configuration, and keyring as the web process. Do
not run it in a tight loop. The command records a heartbeat and returns a
non-zero result when a claimed delivery needs retry or review, allowing cron or
the hosted scheduler to alert without exposing an endpoint or body.

Readiness and `doctor` report aggregate lifecycle counts, backlog, oldest age,
dead letters, heartbeat age, network-policy mode, and encryption-key presence
and active version. Metrics are aggregate and bounded. Diagnostic and audit
records contain only fixed codes and opaque references, never signing secrets,
endpoint URLs, event bodies, aggregate IDs, or response bodies.
Catalog-confirmed absence of the webhook tables is reported as not installed;
catalog, permission, damaged-schema, and table-read failures remain unavailable
errors rather than being misreported as a healthy zero state.

`CPE_DOMAIN_EVENT_DIAGNOSTIC_OUTBOX_PATH` remains an optional institution-local
JSONL diagnostic export for the governed public envelope. It is not production
webhook delivery. The older `CPE_DOMAIN_EVENT_OUTBOX_PATH` name is accepted only
as a diagnostics compatibility alias; the old environment URL/secret webhook
sink is disabled.
