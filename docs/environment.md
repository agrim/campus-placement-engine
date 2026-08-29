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

`php placement setup` is the local browser path. It accepts only
`127.0.0.1:PORT` or `localhost:PORT`, prints a one-time code to the trusted local
terminal, and passes the same capability only to its PHP child server. The code
expires after 20 minutes and must be entered on the unlock-only page; loopback
topology alone never authorizes the installer. `CPE_INTERNAL_SETUP_CAPABILITY`,
`CPE_INTERNAL_SETUP_ADDRESS`, and `CPE_INTERNAL_SETUP_EXPIRES` are reserved
implementation variables; never set or forward them in a service, shell
profile, proxy, or container definition.

## Database And Recovery

| Variable | Purpose |
|---|---|
| `CPE_DB_DRIVER` | Optional `sqlite` or `pgsql` selector. A database URL also selects PostgreSQL. |
| `CPE_DATABASE_URL` | PostgreSQL data-plane URL. Keep it in a secret manager. Production requires `sslmode=verify-full`, `sslrootcert`, and `connect_timeout`. |
| `CPE_POSTGRES_POOL_MODE` | PostgreSQL endpoint mode: `direct` or session-affine `session`. Transaction pooling is unsupported. Defaults to `direct`. |
| `CPE_POSTGRES_ALLOW_INSECURE_LOOPBACK` | Local/test-only opt-in. Set exactly `1` to allow `sslmode=disable` for `localhost`, `127.0.0.0/8`, or `::1`; never set it in production. |
| `CPE_PG_HOST`, `CPE_PG_PORT`, `CPE_PG_DATABASE`, `CPE_PG_USER`, `CPE_PG_PASSWORD` | Component-style PostgreSQL configuration when `CPE_DATABASE_URL` is absent. |
| `CPE_PG_SSLMODE` | Component-style TLS mode. Production accepts only `verify-full`. |
| `CPE_PG_SSLROOTCERT` | Explicit absolute trusted CA/root-certificate path. The PHP service account must be able to read it. |
| `CPE_PG_CONNECT_TIMEOUT` | PostgreSQL connection timeout in seconds, bounded from 1 to 30 and required in production. |
| `CPE_BACKUP_DIR` | Backup and restore-safety destination. Defaults to `data/backups`. |
| `CPE_PG_DUMP_BINARY` | Absolute `pg_dump` override when it is not in a known Homebrew/system location. |
| `CPE_PG_RESTORE_BINARY` | Absolute `pg_restore` override. |
| `CPE_IMPORT_ROLLBACK_DIR` | Import rollback snapshots. |
| `CPE_CONFIG_SNAPSHOT_DIR` | Config import safety copies. |
| `CPE_PRIVACY_SNAPSHOT_DIR` | Privacy erasure safety copies. |

All supported modes require `mbstring`. PostgreSQL requires `pdo_pgsql`; backup
and restore additionally require `pg_dump` and `pg_restore`. SQLite requires
`pdo_sqlite`, `sqlite3`, and writable data/database directories.

The strict Engine runtime parser accepts only `sslmode`, `sslrootcert`, and
`connect_timeout` URL query parameters. Unknown or duplicate parameters,
fragments, invalid percent encodings, and DSN/control-character injection are
rejected. Credentials and root-certificate paths are omitted from diagnostics;
PDO persistence is disabled. After a production connection succeeds, Engine
also verifies the current backend row in `pg_stat_ssl` reports negotiated TLS.

Production example:

```bash
export CPE_POSTGRES_POOL_MODE=direct
export CPE_DATABASE_URL='postgresql://USER:PASSWORD@db.example.edu/CAREER_SERVICES?sslmode=verify-full&sslrootcert=%2Fetc%2Fssl%2Fcerts%2Finstitution-ca.pem&connect_timeout=10'
```

For a disposable loopback-only PostgreSQL test service:

```bash
export CPE_POSTGRES_POOL_MODE=direct
export CPE_POSTGRES_ALLOW_INSECURE_LOOPBACK=1
export CPE_DATABASE_URL='postgresql://USER:PASSWORD@127.0.0.1/EMPTY_TEST_DATABASE?sslmode=disable'
```

Do not use the local-test opt-in with a remote endpoint. During ordinary Engine
operations, `pg_dump` and `pg_restore` receive a password-free URI plus the same
policy-resolved TLS mode, trusted root, and bounded timeout as PDO. Their child
environment removes ambient `PG*` connection settings before installing those
validated values, and carries the password only in `PGPASSWORD`, never in argv.
The legacy explicit constructor URL remains compatible and does not independently
enforce the new runtime policy. Its non-secret libpq query options are retained
with canonical encoding, while password-bearing query options are rejected;
never supply a different weaker URL to that compatibility seam.

## Web Security And Sessions

| Variable | Purpose |
|---|---|
| `CPE_SETUP_TOKEN` | Deployer-provisioned remote browser-setup token. It must be canonical unpadded base64url, 43-128 characters, decode to at least 32 bytes, and remain in the process environment or secret manager rather than a URL. |
| `CPE_SESSION_SECURE` | `auto`, `force`, or `never`. Use `force` behind an always-TLS proxy; `never` is local HTTP only. |
| `CPE_TRUST_PROXY_HEADERS` | Trust the first `X-Forwarded-Proto` value for HTTPS detection. Enable only behind a sanitizing trusted proxy. |
| `CPE_SESSION_DRIVER` | `files` or `database`. Hosted mode defaults to `database`; self-hosting defaults to `files`. |
| `CPE_SESSION_LIFETIME` | Session lifetime in seconds, bounded from 300 to 86400. |
| `CPE_SESSION_LOCK_SECONDS` | Stale database-session lock threshold. Defaults to 120. |
| `CPE_SESSION_LOCK_WAIT_SECONDS` | Maximum wait for a busy database session. Defaults to 2. |

Cookies are HttpOnly, SameSite=Lax, and host-only. HSTS is sent only when HTTPS
is detected. Forwarded protocol headers are ignored unless explicitly trusted.

Before installation, `/install.php` uses a separate file-backed, host-only,
HttpOnly, SameSite=Strict session and a 20-minute non-sliding authorization
grant. Remote token unlock requires direct HTTPS, except that
`CPE_SESSION_SECURE=force` is the explicit assertion for TLS termination.
Loopback source addresses, forwarded headers, and proxy headers never grant
environment-token transport authority. The separate `php placement setup`
capability remains loopback-only and topology-bound. If
`CPE_SESSION_DRIVER=database` is configured before the schema exists, browser
setup refuses to proceed and directs the operator to `php placement install`.

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
| `CPE_METRICS_TOKEN` | Bearer token of at least 24 characters for `public/metrics.php` and managed-hosting readiness. |

`public/health.php` liveness needs no token and does not load a platform adapter,
tenant, session, or database. Readiness is selected with `?ready=1`. It remains
unauthenticated for ordinary self-hosting, but when `CPE_HOSTED_MODE` is enabled
or `CPE_PLATFORM_BOOTSTRAP` is non-empty it requires the same exact
`Authorization: Bearer ...` header as metrics. Query parameters, cookies,
forwarded identity headers, and source addresses never supply this credential.

## Signed Webhook Integrations

The administrator **Integrations** page owns endpoint URLs, selected events,
lifecycle, validation, and signing-secret rotation. These process variables own
only deployer-controlled cryptography and outbound policy:

| Variable | Purpose |
|---|---|
| `CPE_WEBHOOK_ENCRYPTION_KEYS` | One to eight semicolon-separated `version=key` entries. Each key is exactly 32 bytes in canonical unpadded base64url. Keep it outside the database. |
| `CPE_WEBHOOK_ACTIVE_KEY_VERSION` | Version used for new AES-256-GCM ciphertext. It must name one keyring entry. |
| `CPE_WEBHOOK_ALLOWED_PORTS` | Comma-separated approved ports, at most 16. Defaults to `443`. |
| `CPE_WEBHOOK_ALLOW_HTTP` | Self-hosted-only HTTP opt-in. The subscription must also explicitly allow a private-network endpoint and its port must be approved. Managed mode ignores this and remains public-egress only. |
| `CPE_WEBHOOK_LEASE_SECONDS` | Stale delivery-claim threshold, clamped to 30-3600 seconds; default 300. |
| `CPE_WEBHOOK_MAX_ATTEMPTS` | Attempts before dead letter, clamped to 1-20; default 10. |
| `CPE_WEBHOOK_ENDPOINT_CONCURRENCY` | Per-endpoint in-flight claim cap, clamped to 1-10; default 1. |
| `CPE_WEBHOOK_INSTITUTION_CONCURRENCY` | Per-institution in-flight claim cap, clamped to 1-100; default 5. |

Run `php placement work-integrations` every minute from cron or the hosted
scheduler. The same database and keyring must reach the web and worker
processes. Missing encryption keys do not break a base Engine install, but an
active integration whose referenced key is unavailable makes readiness fail
closed. See `docs/integrations/webhooks.md` for the exact keyring grammar,
signing contract, network policy, retry schedule, and shared-hosting example.

`CPE_DOMAIN_EVENT_DIAGNOSTIC_OUTBOX_PATH` is an optional institution-local
JSONL diagnostic export of governed public envelopes. The older
`CPE_DOMAIN_EVENT_OUTBOX_PATH` name is accepted as a diagnostics-only alias.
Configure at most one of those paths. The legacy
`CPE_DOMAIN_EVENT_WEBHOOK_URL`, `CPE_DOMAIN_EVENT_WEBHOOK_SECRET`, and
`CPE_DOMAIN_EVENT_ALLOW_HTTP` production sink is disabled; configure signed
webhooks in the administrator workflow instead.

`CPE_DOMAIN_EVENT_LOCK_SECONDS`, `CPE_DOMAIN_EVENT_MAX_ATTEMPTS`, and
`CPE_DOMAIN_EVENT_FANOUT_MAX_ATTEMPTS` continue to govern `work-outbox` source
and internal-module processing. `CPE_OUTBOUND_ALLOW_PRIVATE_NETWORK` applies to
legacy notification gateways, not signed Integration subscriptions.

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
