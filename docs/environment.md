# Environment Variables

The application reads process environment variables directly. It has no `.env`
loader, Composer package, or frontend build step. Set variables in your shell,
PHP-FPM pool, web host control panel, or secret manager. Never commit live
passwords, tokens, database URLs, phone routes, or gateway credentials.

The default self-hosted install needs no variables. Start from
`examples/env/local.env.example` only when an override is useful. Managed-service
control-plane and infrastructure variables live outside this public repository.

## Local Setup

| Variable | Purpose |
|---|---|
| `CPE_DB_PATH` | SQLite path. Defaults to `data/app.sqlite`. |
| `CPE_SERVE_ADDRESS` | Address for `php placement serve`. Defaults to `127.0.0.1:8000`. |
| `CPE_ADMIN_PASSWORD` | First CLI-installed administrator password. Prefer this over a command argument. |
| `CPE_COLLEGE_NAME`, `CPE_ADMIN_NAME`, `CPE_ADMIN_EMAIL`, `CPE_TIMEZONE`, `CPE_WORKFLOW` | Optional CLI install defaults. |

## Database And Recovery

| Variable | Purpose |
|---|---|
| `CPE_DB_DRIVER` | Optional `sqlite` or `pgsql` selector. A database URL also selects PostgreSQL. |
| `CPE_DATABASE_URL` | PostgreSQL data-plane URL. Keep it in a secret manager. |
| `CPE_BACKUP_DIR` | Backup and restore-safety destination. Defaults to `data/backups`. |
| `CPE_PG_DUMP_BINARY` | Absolute `pg_dump` override when it is not in a known Homebrew/system location. |
| `CPE_PG_RESTORE_BINARY` | Absolute `pg_restore` override. |
| `CPE_IMPORT_ROLLBACK_DIR` | Import rollback snapshots. |
| `CPE_CONFIG_SNAPSHOT_DIR` | Config import safety copies. |
| `CPE_PRIVACY_SNAPSHOT_DIR` | Privacy erasure safety copies. |

PostgreSQL requires `pdo_pgsql`; backup and restore additionally require
`pg_dump` and `pg_restore`. SQLite requires `pdo_sqlite`, `sqlite3`, and writable
data/database directories.

## Web Security And Sessions

| Variable | Purpose |
|---|---|
| `CPE_SESSION_SECURE` | `auto`, `force`, or `never`. Use `force` behind an always-TLS proxy; `never` is local HTTP only. |
| `CPE_TRUST_PROXY_HEADERS` | Trust the first `X-Forwarded-Proto` value for HTTPS detection. Enable only behind a sanitizing trusted proxy. |
| `CPE_SESSION_DRIVER` | `files` or `database`. Hosted mode defaults to `database`; self-hosting defaults to `files`. |
| `CPE_SESSION_LIFETIME` | Session lifetime in seconds, bounded from 300 to 86400. |
| `CPE_SESSION_LOCK_SECONDS` | Stale database-session lock threshold. Defaults to 120. |
| `CPE_SESSION_LOCK_WAIT_SECONDS` | Maximum wait for a busy database session. Defaults to 2. |

Cookies are HttpOnly, SameSite=Lax, and host-only. HSTS is sent only when HTTPS
is detected. Forwarded protocol headers are ignored unless explicitly trusted.

## Institutional SSO

| Variable | Purpose |
|---|---|
| `CPE_SSO_ENABLED` | Enables the signed identity-proxy assertion endpoint. |
| `CPE_SSO_PROVIDER_KEY` | Stable provider key, normally `oidc_proxy`. |
| `CPE_SSO_SHARED_SECRET` | HMAC secret of at least 32 characters, shared only with the trusted identity proxy. |
| `CPE_SSO_AUTO_LINK_EMAIL` | Optional automatic link to an existing active user by verified email. Off by default. |

The app does not implement OIDC authorization flows. Terminate OIDC in a
reviewed proxy and use `php placement sso-link` for explicit subject links.

## Observability

| Variable | Purpose |
|---|---|
| `CPE_LOG_PATH` | Optional JSONL structured log path. PHP error logging is the fallback. |
| `CPE_METRICS_TOKEN` | Bearer token of at least 24 characters for `public/metrics.php`. |

`public/health.php` needs no token. Readiness is selected with `?ready=1`.

## Domain Event Outbox

Configure at most one sink:

| Variable | Purpose |
|---|---|
| `CPE_DOMAIN_EVENT_OUTBOX_PATH` | Local JSONL event sink. |
| `CPE_DOMAIN_EVENT_WEBHOOK_URL` | HTTPS event webhook. |
| `CPE_DOMAIN_EVENT_WEBHOOK_SECRET` | Optional 32+ character HMAC signing secret. |
| `CPE_DOMAIN_EVENT_ALLOW_HTTP` | Allows HTTP only for localhost webhook testing. |
| `CPE_OUTBOUND_ALLOW_PRIVATE_NETWORK` | Allows reviewed notification/domain-event delivery to private networks. Off by default; expands the SSRF trust boundary. |
| `CPE_DOMAIN_EVENT_TIMEOUT` | Webhook timeout seconds, bounded from 1 to 30. |
| `CPE_DOMAIN_EVENT_LOCK_SECONDS` | Stale claim threshold, default 300. |
| `CPE_DOMAIN_EVENT_MAX_ATTEMPTS` | Attempts before dead letter, default 10. |

Run `php placement work-outbox` from cron or the hosted scheduler. With no
external sink, the command acknowledges events as internal after in-process
module subscribers have run.

## Managed-hosting adapter

| Variable | Purpose |
|---|---|
| `CPE_HOSTED_MODE` | Enables the registered managed-hosting tenant resolver. |
| `CPE_PLATFORM_BOOTSTRAP` | Readable absolute path to the management plane's Engine adapter. |

These variables are not used by ordinary self-hosted installations. Tenant,
domain, database-secret, provisioning, and fleet configuration belongs to the
separate management plane. See `managed-hosting-contract.md`.

## Imports And QA

| Variable | Purpose |
|---|---|
| `CPE_IMPORT_MAX_BYTES` | Maximum pasted CSV size. |
| `CPE_IMPORT_MAX_ROWS` | Maximum non-empty CSV data rows. |
| `CPE_SMOKE_BASE_URL`, `CPE_SMOKE_EMAIL`, `CPE_SMOKE_PASSWORD` | Admin HTTP smoke values. |
| `CPE_SMOKE_RESTRICTED_EMAIL`, `CPE_SMOKE_RESTRICTED_PASSWORD` | Optional restricted account for access-control smoke. |
| `CPE_LOAD_BASE_URL` | Default base URL for `php placement load-smoke`. |
| `CPE_QA_BASE_URL` | Default URL for `php placement browser-qa-plan`. |

## Optional Notification Handoff

| Variable | Purpose |
|---|---|
| `CPE_NOTIFICATION_WEBHOOK_URL` | Webhook destination for queued notifications. |
| `CPE_NOTIFICATION_EMAIL_TO`, `CPE_NOTIFICATION_EMAIL_FROM` | Email route overrides. |
| `CPE_NOTIFICATION_FILE_OUTBOX_PATH` | Deployer-controlled `.jsonl` file destination; may intentionally be outside `data/`. |
| `CPE_NOTIFICATION_EMAIL_OUTBOX_PATH` | Local email JSONL outbox. |
| `CPE_NOTIFICATION_SMS_GATEWAY_URL`, `CPE_NOTIFICATION_SMS_AUTHORIZATION`, `CPE_NOTIFICATION_SMS_TO` | SMS endpoint, authorization header, and recipient route. |
| `CPE_NOTIFICATION_WHATSAPP_GATEWAY_URL`, `CPE_NOTIFICATION_WHATSAPP_AUTHORIZATION`, `CPE_NOTIFICATION_WHATSAPP_TO` | WhatsApp endpoint, authorization header, and recipient route. |
| `CPE_NOTIFICATION_MESSAGE_OUTBOX_PATH` | Shared local SMS/WhatsApp JSONL outbox. |
| `CPE_NOTIFICATION_ALLOW_HTTP` | Allows HTTP only for local notification/gateway testing. |

Outbound webhooks and message gateways require HTTPS and public-network
destinations by default, pin the verified address through PHP `ext-curl`,
inherit no proxy, follow no redirects, and require a 2xx response. Keep
`CPE_OUTBOUND_ALLOW_PRIVATE_NETWORK` disabled unless the process is
intentionally trusted to call an internal gateway.

Run `php placement certify-notifications --channel=sms` or `--channel=whatsapp`
before connecting an approved live provider.
