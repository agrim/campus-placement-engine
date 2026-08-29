# Security And Operations

This document covers deployment controls around the application. It does not
replace an institution's risk assessment, data policy, or identity-provider
review.

## TLS And Proxy Trust

Use HTTPS for real data. Direct HTTPS is detected from the server connection.
Only set `CPE_TRUST_PROXY_HEADERS=1` when requests can reach PHP solely through a
trusted reverse proxy that replaces `X-Forwarded-Proto`. Otherwise forwarded
protocol headers are ignored. `CPE_SESSION_SECURE=force` is the simpler option
when TLS always terminates upstream.

HTTPS responses add HSTS. All web responses retain the self-only content
security policy, same-origin frame protection, MIME-sniffing protection,
referrer policy, and disabled camera/microphone/geolocation permissions.

## Authentication And Sessions

Password login uses PHP password hashing, session-ID rotation, CSRF-protected
login state changes, and database-backed throttling by hashed identity/network.
Hosted mode defaults to database sessions; self-hosting defaults to PHP file
sessions. Set `CPE_SESSION_DRIVER=database` to opt in elsewhere.

Database sessions hash browser session IDs before storage and hold a bounded
database lock for each request. Tune only when needed:

```text
CPE_SESSION_LIFETIME=7200
CPE_SESSION_LOCK_SECONDS=120
CPE_SESSION_LOCK_WAIT_SECONDS=2
```

Institutional OIDC should terminate in a reviewed identity-aware proxy or
gateway. The app accepts only the gateway's short-lived signed identity
assertion; it does not implement OAuth/OIDC itself. Configure a 32+ character
shared secret and link subjects explicitly:

```bash
export CPE_SSO_ENABLED=1
export CPE_SSO_PROVIDER_KEY=oidc_proxy
export CPE_SSO_SHARED_SECRET='use-a-secret-manager-value'
php placement sso-link --provider=oidc_proxy --subject=SUBJECT --user-email=user@example.edu
```

The signed assertion binds provider, subject, email, name, timestamp, nonce, and
institution public ID. Nonces are one-use and assertions expire quickly. Keep
`CPE_SSO_AUTO_LINK_EMAIL` disabled unless the institution has reviewed email
ownership and account-linking risks.

## Health And Metrics

- `GET /health.php` is a process liveness check and touches no operational data.
- `GET /health.php?ready=1` checks tenant resolution, installation, database
  access, and pending migrations without returning record counts. Ordinary
  self-hosting does not require a token. Hosted mode, or a configured platform
  bootstrap, requires the metrics Bearer token before the platform adapter,
  tenant Host resolution, or database access.
- `GET /metrics.php` requires `Authorization: Bearer ...` with a
  `CPE_METRICS_TOKEN` of at least 24 characters; absent or invalid access returns
  404.

Metrics expose migration, outbox, notification, advising-task, and module-state
gauges. Hosted-readiness and metrics credentials are accepted only from the
HTTP `Authorization` header. Do not place the token in a URL, cookie,
`X-Forwarded-*` header, or client-address allowlist. Missing, malformed, invalid,
or weakly configured operational credentials receive the same concealed 404
text response and no authentication challenge.

## Structured Logs

Set `CPE_LOG_PATH` to write JSONL request and exception logs. Otherwise logs use
the PHP error log. Each web request receives `X-Request-ID`; user-visible errors
refer to that ID. Secret-named fields, bearer values, common key assignments,
and PostgreSQL URL passwords are redacted, but operators must still avoid
logging personal payloads.

## Domain-Event Delivery

`php placement work-outbox` first expands durable per-module declaration work,
then processes source-bundled observer deliveries and the external event outbox.
These paths are independent: a declaration or observer cannot block its
neighbors or the external sink.
The external worker claims only rows with a complete governed public projection
and serializes only public columns through the public envelope. It never selects
or decodes the private `DomainEvent` payload, and it never promotes legacy rows
by inference. The current catalog contains only
`application.status_changed` schema 1.
Without an external sink, the external outbox records `delivered_to=internal` to
mean that no out-of-process destination was configured; it does not represent
observer completion. Configure one optional external sink:

```text
CPE_DOMAIN_EVENT_OUTBOX_PATH=/secure/path/events.jsonl
# or
CPE_DOMAIN_EVENT_WEBHOOK_URL=https://integration.example.edu/events
CPE_DOMAIN_EVENT_WEBHOOK_SECRET=at-least-32-characters
```

HTTPS is mandatory. Localhost HTTP is available only with
`CPE_DOMAIN_EVENT_ALLOW_HTTP=1`. Delivery rejects URL credentials, fragments,
redirects, non-2xx responses, and private/reserved network destinations. It
requires PHP `ext-curl`, pins the verified destination address, and disables
proxy inheritance. A reviewed internal integration additionally needs
`CPE_OUTBOUND_ALLOW_PRIVATE_NETWORK=1`; this is a process-wide trust-boundary
override and should stay off for ordinary internet delivery. Claims are
concurrency-safe, stale locks can be recovered, failures back off, and terminal
failures become dead letters after `CPE_DOMAIN_EVENT_MAX_ATTEMPTS` (default 10).
Monitor pending and dead-lettered work. Internal observers and external
consumers must be idempotent because a crash between a side effect and its
token-fenced acknowledgement can produce at-least-once delivery.
Later versions for one application wait for its unresolved earlier version;
unrelated applications can progress, and there is no global-order promise. A
database or portability restore breaks stream continuity and requires connector
resynchronization before delivery resumes. See `integrations/events.md` and
`security/integration-threat-model.md`.

Replay only an investigated dead letter, by exact stable identity:

```text
php placement replay-public-event --event=event_ID --actor-user-id=USER_ID
php placement replay-internal-fanout --event=event_ID --module=MODULE_KEY --actor-user-id=USER_ID
php placement replay-internal-delivery --event=event_ID --subscription=internal.module.name.v1 --actor-user-id=USER_ID
```

Public replay accepts only an explicit unresolved public dead letter and refuses
retained lease state. The row lock and status-guarded update reset delivery
state in one transaction without changing the envelope; later versions of that
application remain blocked until the replay succeeds. Internal replay follows
the corresponding queue status guards. Every replay requires the supplied actor
to be an active local administrator and writes a fixed, payload-free audit event
with that administrator as actor. Missing, inactive, nonexistent, and non-admin
actors are rejected with redacted failures; possession of a local shell is not
authorization to attribute an action to another user. Repeating an unchanged
replay is idempotent.

## Incident First Steps

1. Stop mutating workers and place the site in maintenance mode.
2. Preserve structured logs, request IDs, control-plane audit, and current
   database state.
3. Revoke affected SSO links, support grants, or gateway secrets.
4. Create and verify a backup before remediation unless doing so would destroy
   evidence.
5. Restore or migrate only through the documented commands.
6. Validate tenant identity, readiness, HTTP access control, and outbox backlog
   before reopening.
