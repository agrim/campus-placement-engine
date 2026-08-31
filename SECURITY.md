# Security Policy

Campus Placement Engine handles operational placement-day data. Treat real
candidate, company, recruiter, account, audit, and movement data as sensitive.

## Supported Versions

The project is pre-1.0 alpha. Security fixes are prepared against the current
`main` branch and, when necessary, released as a new tagged alpha. The latest
published alpha is the only prerelease line receiving fixes. Earlier alphas
remain supported only as documented upgrade sources; they do not receive
independent security patches.

Do not infer a production support commitment from an alpha tag. Each release
note states its deployment boundary, and institutions must complete their own
security, privacy, backup, and operational review before using real data.

## Reporting A Vulnerability

Do not open public issues containing sensitive data, exploit details, database
dumps, screenshots with real names, or credentials.

Use GitHub's private vulnerability-reporting form when it is available for this
repository:

`https://github.com/agrim/campus-placement-engine/security/advisories/new`

If that form is unavailable, contact the repository maintainer privately and do
not include real institutional data. The maintainer's response targets are an
initial acknowledgement within three business days and a severity/coordination
update within seven business days; complex reports may require a longer fix
window. Include:

- A short description of the issue.
- Steps to reproduce using synthetic data.
- Affected release, files, routes, commands, or roles.
- Whether data exposure, unauthorized mutation, tenant-boundary failure, or
  denial of service is possible.
- Any suggested embargo or coordinated-disclosure constraints.

The project will assign an opaque tracking reference, avoid reflecting sensitive
report contents into public logs, and publish an advisory and fixed release when
user action is required.

## Data Handling

- Do not commit real placement data.
- Do not attach real CSV, SQL, spreadsheet, document, or backup files to public
  issues.
- Use synthetic fixtures when demonstrating bugs.
- Run `php placement publication-check` before preparing a public release.
- Use `php placement privacy-person-report` and the confirmation-gated
  `privacy-person-erase` for portal-wide retention cleanup where locally
  appropriate. The candidate anonymization command remains available for
  placement-only compatibility.
- Review role-scoped demo and production accounts before live use. Company
  users should only see their scoped company applications, and restricted live
  roles should not receive private candidate tags, custom fields, cross-company
  routes, or private event notes.
- Review page-level access with non-admin demo accounts. Restricted live roles
  should not be able to open Admin, Import, Records, Reports, Preferences, or
  System surfaces unless the role matrix permits it.
- Keep anonymous placement results aggregate-only. Candidate status lookup,
  candidate traces, the live board, and notification detail require an
  authenticated role with the relevant placement capability.
- Run the app over HTTPS for real operations. Session cookies are HttpOnly and
  SameSite=Lax; set `CPE_SESSION_SECURE=force` when TLS terminates before PHP.
  Enable `CPE_TRUST_PROXY_HEADERS` only behind a trusted proxy that replaces
  forwarded headers.
- Hosted mode should use database-backed sessions and separate PostgreSQL
  databases per institution. Do not place student or placement records in the
  hosted control plane.
- Treat SSO shared secrets, metrics tokens, tenant database URLs, notification
  gateway authorization, and support grants as privileged operational material.
- Outbound notification and domain-event webhooks require HTTPS, reject URL
  credentials, redirects, non-success responses, and private/reserved network
  destinations by default. Use local HTTP/private-network overrides only in a
  reviewed deployment environment; they expand the server-side request-forgery
  trust boundary.
- Database-configured notification file outboxes must be `.jsonl` files under
  `data/`. Only a deployer-controlled `CPE_NOTIFICATION_FILE_OUTBOX_PATH`
  environment override may intentionally write elsewhere.
- Keep every backup beside its required `.sha256` sidecar. Restore refuses a
  missing or mismatched checksum and writes a safety backup before mutation.
- Keep `/metrics.php` token-protected, monitor outbox dead letters, and preserve
  request IDs plus structured logs during incident response.
- Keep the default browser security headers enabled. The app emits a self-only
  content security policy, same-origin frame protection, MIME-sniffing
  protection, a restrained referrer policy, and disabled camera/microphone/
  geolocation permissions. Dynamic responses are marked private and no-store so
  placement data is not retained by ordinary browser or intermediary caches.
- Keep local `.env` files, gateway tokens, phone routes, and administrator
  passwords out of Git. Use `examples/env/local.env.example` only as a
  synthetic template.
- Treat any `php placement publication-check` potential-secret finding as a
  release blocker until the value is moved to the environment or replaced with
  a synthetic placeholder.

## Deployment Responsibility

Self-hosting institutions are responsible for local access control, backups,
retention policies, legal compliance, secure transport configuration, and
retention cleanup for files already exported or backed up outside the live
database. Hosted operators additionally own tenant isolation, secret management,
support-access audit, fleet backup verification, and restore drills.
