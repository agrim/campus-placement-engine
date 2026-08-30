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

Metrics expose migration, outbox, notification, signed-webhook aggregate
health, advising-task, and module-state gauges. Webhook metrics contain counts
and heartbeat age only, never endpoint URLs, event/application IDs, bodies, or
diagnostics. Hosted-readiness and metrics credentials are accepted only from the
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

## Event And Integration Workers

`php placement work-outbox` expands durable Module declaration work, processes
source-bundled internal observer deliveries, and optionally writes the governed
public envelope to a diagnostics-only JSONL path. It never selects or decodes
private `DomainEvent` payloads for that export. The former environment URL and
secret sink is disabled.

Production webhook delivery is institution-facing Integration state managed on
the server-rendered Integrations page and processed separately:

```text
php placement work-integrations --limit=100
```

Run the short worker every minute from cron or the hosted scheduler. Give it the
same institution database, `CPE_WEBHOOK_ENCRYPTION_KEYS`, and
`CPE_WEBHOOK_ACTIVE_KEY_VERSION` as the web process. Do not run a busy loop.
Readiness and metrics report aggregate lifecycle, backlog, dead-letter,
heartbeat, private-policy mode, and key-version health without endpoint URLs,
event IDs, bodies, or raw failures.

Engine captures one delivery row for every eligible endpoint in the source
event transaction and reconstructs the exact immutable public body only after
commit. Endpoint leases, retries, circuits, health, and replay are isolated.
Delivery is at least once; Connectors must verify signatures and make event-ID
side effects idempotent. Ordering is per subscription and application aggregate,
so unrelated applications and endpoints continue independently.

HTTPS, peer/hostname verification, approved ports, fresh all-address DNS
validation, connection pinning, no proxy, no redirect, bounded headers,
1 MiB request/response caps, and connect/total timeouts are mandatory. An
explicit self-hosted per-subscription private policy admits only RFC 1918/ULA;
managed hosting is public-egress only. Invalid TLS is terminal and never
downgraded.

Replay only an investigated dead letter by exact stable identity:

```text
php placement replay-webhook-delivery --delivery=whdel_ID --actor-user-id=USER_ID
php placement replay-public-event --event=event_ID --actor-user-id=USER_ID
php placement replay-internal-fanout --event=event_ID --module=MODULE_KEY --actor-user-id=USER_ID
php placement replay-internal-delivery --event=event_ID --subscription=internal.module.name.v1 --actor-user-id=USER_ID
```

Webhook replay targets one endpoint delivery, preserves the immutable envelope,
fences stale claims, and attributes a fixed payload-free audit entry to an
active local administrator. Source public-event and internal Module replay keep
their existing exact-row guards. Shell access alone does not authorize false
administrator attribution. See `integrations/webhooks.md`,
`integrations/events.md`, and `security/integration-threat-model.md`.

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
