# Deployment examples

These are starting shapes, not universal production claims. Use only a
combination named in the release's verified support matrix.

## Small university or shared host

- PHP 8.2–8.4 with `mbstring`, `pdo_sqlite`, and `sqlite3`.
- Apache or Nginx document root set to `public/`.
- SQLite database and backup directories outside public web access.
- HTTPS at the host, secure session cookies, daily encrypted off-machine backup,
  and scheduled workers only for enabled integrations.

This is the smallest self-hosted shape. Start with the supplied
[Apache](../examples/deployment/apache-vhost.conf) or
[Nginx](../examples/deployment/nginx-server.conf) example and the
[installation walkthrough](installation-walkthrough.md).

## University VPS with PostgreSQL

- Nginx plus PHP-FPM on a modest Linux VM.
- A distinct least-privilege PostgreSQL role and database.
- `sslmode=verify-full`, an exact database hostname, trusted CA, bounded connect
  timeout, and direct or session-affine pooling.
- `pg_dump` and `pg_restore` from the supported PostgreSQL client major.
- A process supervisor or scheduler for enabled notification and integration
  workers.

Keep the web runtime stateless apart from institution-owned configuration and
resolve database credentials through the host's secret facility. Validate the
exact release archive with install, authenticated HTTP, backup, isolated
restore, and upgrade checks before using real data.

## Local production-policy rehearsal

Apple Container is the fastest supported development path on an Apple-silicon
Mac when it is already installed. Docker is the portable fallback. The private
Cloud workspace includes `scripts/local-production-lab`, which runs the public
Engine against TLS PostgreSQL, distinct Cloud/tenant roles, HTTP authorization,
backup/upgrade, advisory-lock, and load checks without making a paid provider
claim.

## Managed service

The public Engine remains unchanged. A separate Cloud control plane selects the
institution from an exact active hostname and resolves one institution database.
Cloud must not store or proxy candidate, company, application, advising, or
placement records. See [managed-hosting-contract.md](managed-hosting-contract.md).

Before selecting a public host, record region/residency, database and backup
model, DNS/TLS automation, secret storage, monitoring, restore objectives,
expected institution size, and who owns incidents. Cost alone is not a
production-readiness decision.
