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
New and reset passwords must contain 8–72 bytes. Bcrypt cannot distinguish later
bytes, so existing bcrypt users with longer passwords must have their password
reset before signing in; entering an alternate suffix is never accepted.
User email addresses are trimmed, lowercased, and unique under that same rule.
Before upgrading, check legacy accounts for collisions using the verified newer
application files in a staging directory and the intended database configuration:

```bash
php /path/to/staged-release/placement pre-upgrade-check
```

For SQLite, set `CPE_DB_DRIVER=sqlite` and `CPE_DB_PATH` to the absolute path of
the existing database or an isolated restored copy. For PostgreSQL, use the same
`CPE_DATABASE_URL` or `CPE_PG_*` configuration, TLS policy, and credentials as the
intended target. The check inspects all users with the database's own
`lower(trim(email))` expression, matching the new unique index. It supports
unowned legacy databases without adopting ownership. It does not run migrations,
create backups, change accounts or permissions, or write application logs. It
bypasses hosted/platform bootstrap; managed operators must select the intended
tenant database explicitly rather than relying on host resolution.

Normal output is JSON containing aggregate counts only:

```json
{"collision_groups":0,"conflicting_users":0}
```

Exit status `0` means no collision was found, `2` means collisions were found,
and `1` means the arguments or database could not be checked. A missing,
unreadable, or incompatible database is an error, not a clean result. This is an
email-identity check, not certification that every upgrade requirement is met.

To identify affected accounts, an administrator may explicitly run
`php /path/to/staged-release/placement pre-upgrade-check --show-user-ids` in a
private administrative session. This adds `user_id_groups` containing the user
IDs in each collision group; email addresses, names, and credentials are never
printed. Treat these IDs as private account information and keep any captured
output in restricted local storage, away from ordinary logs, CI output, and
shared support reports. Review those accounts through authorized administrative
tools and deliberately correct their email identities. A normalization match
alone is not evidence that accounts should be merged or deleted.

Run the check early against the intended target or a verified restored copy to
allow time for resolution. Before the actual upgrade, stop application and
worker writes, take a verified backup, and rerun the check against the target.
A result describes one database snapshot; it does not reserve email identities
or prevent a later writer from creating a collision. The migration's unique
index remains the final enforcement boundary. If an upgrade still reports a
`users_email_normalized` collision, resolve it deliberately and rerun the check
before retrying. The migration fails closed without merging accounts or
partially normalizing their email identities.
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
