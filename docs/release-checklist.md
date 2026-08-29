# Release Checklist

Use this before a public alpha tag or downloadable archive.

## Code And Tests

- Run `php placement doctor` and confirm it exits cleanly with `OK` for PHP,
  SQLite, and writable data/database locations.
- Run `php placement serve --help` and confirm it documents the PHP built-in
  server wrapper without starting a long-running process.
- Run `php placement setup --check` and confirm the one-command guided-install
  preflight exits without creating an installation or starting a server.
- Run `php tests/run.php`.
- Run `php tests/backup_restore_contract.php` and confirm checksum/metadata
  tamper and wrong-identity inputs are rejected before safety backup or target
  mutation, while a same-identity restore creates checksummed safety evidence.
- Run `php tests/legacy_backup_compatibility_contract.php` and confirm legacy
  upgrade ownership is claimed before backup, conversion exclusively creates a
  new current-format archive, originals remain unchanged, legacy import
  manifests require explicit conversion, and restore-staging cleanup failures
  reach the protected incident record. Confirm alpha.1 PostgreSQL import
  manifests expose only the fixed isolated-validation status and refuse direct
  conversion/restore without modifying the original dump.
- Run the mandatory external `php tests/alpha1_release_acceptance.php` gate with
  `CPE_ALPHA1_DATABASE_FIXTURE` and `CPE_ALPHA1_BACKUP_FIXTURE` produced by an
  unmodified public `v0.1.0-alpha.1` artifact. Confirm it passes rather than
  skips, and record the private fixture provenance and hashes outside Git. CI
  and release must also export that exact tag and generate/run the fixtures on
  every workflow execution.
- Run `php tests/setup_authorization_contract.php` and confirm the browser and
  CLI setup authorization boundaries pass.
- Run `php tests/install_concurrency_contract.php` and confirm SQLite produces
  exactly one internally consistent hosted installation winner from two
  distinct tenant identities and payloads. The PostgreSQL 17 release job must
  run the same contract against its fresh dedicated database.
- Run `php tests/hosted_install_atomicity_contract.php` and confirm every
  reviewed durable stage rolls back to the exact unbound state, a clean retry
  completes once, and check-plus-identity uncertain-success recovery is a
  no-op. The PostgreSQL 17 release job must run the same contract against a
  fresh dedicated database.
- Run `php tests/hosted_install_preflight_contract.php` and confirm installed
  pre-current schemas reject same-tenant and wrong-tenant direct retries without
  changing schema, migration, ownership, identity, or product state.
- Run `php tests/database_lock_release_contract.php` and confirm its local typed
  failure companion passes. The PostgreSQL 17 release job must fault checked
  unlock for ownership, migration, and installation locks and prove every next
  cached connection uses a different backend session.
- Run `php tests/database_connection_cleanup_contract.php` and confirm typed
  ownership/migration cleanup failures retain both causes, discard the provider,
  preserve rollback truth, and permit a fresh connection. The PostgreSQL 17
  release job must run the same contract against a fresh dedicated database.
- Run `php tests/hosted_install_contract.php` and confirm managed-hosting
  contract version 2 exposes no post-install rebinding method and that exact
  database snapshots remain unchanged when intended-tenant, wrong-tenant, and
  malformed-identity attempts target an installed self-hosted database. The
  PostgreSQL 17 release job must run it against a fresh dedicated database.
- Run `php tests/database_contract.php` against SQLite and a fresh PostgreSQL 17
  database.
- Run `php tests/managed_hosting_contract.php` and confirm the external resolver
  seam, tenant database identity, module entitlements, and session binding fail
  closed as documented.
- Run PHP syntax lint over app, config, public, tests, and `placement`.
- Run `php placement publication-check` and investigate any forbidden-file or
  potential-secret finding before packaging.
- Run `php placement package --target=dist --force`.
- Confirm the ZIP and tarball each have a `.sha256` sidecar, the combined
  `SHA256SUMS` contains both archives, and run `php placement verify-package`
  against both formats.
- Run CLI first-run install against a throwaway database with
  `CPE_ADMIN_PASSWORD=... php placement install ...`.
- Run `php placement upgrade` against a throwaway installed database and confirm
  it writes an upgrade backup before migrations.
- Start `php placement setup` against a throwaway `CPE_DB_PATH`, or exercise the
  HTTPS `CPE_SETUP_TOKEN` unlock flow, and confirm installation fields and
  system checks appear only after authorization.
- Confirm a second installer run against the same database is refused and does
  not create another administrator or overwrite settings.
- Run `php placement readiness` on a fresh synthetic demo install.
- Confirm the Admin page can freeze and unfreeze configuration changes, and that
  `php placement readiness` reports the intended configuration-freeze state.
- Confirm the anonymous Public page exposes aggregate counts only, contains no
  candidate name or ID, and that candidate lookup redirects an anonymous user
  to login.
- Run `php placement placement-report` on a fresh synthetic demo install.
- Run `php placement export --profile=summary` and confirm only aggregate CSVs
  are produced.
- Run `php placement export --profile=custom` after setting
  `export_profile_custom_datasets` to a reviewed dataset list.
- Run `php placement deliver-notifications --dry-run` on a fresh synthetic demo
  install.
- If `email` notifications are enabled, test them with
  `CPE_NOTIFICATION_EMAIL_OUTBOX_PATH=... php placement deliver-notifications --channel=email`.
- If `sms` or `whatsapp` notifications are enabled, test them with
  `CPE_NOTIFICATION_MESSAGE_OUTBOX_PATH=... php placement deliver-notifications --channel=sms`
  or `--channel=whatsapp` before connecting a real gateway.
- Run `php placement certify-notifications --channel=sms` and
  `php placement certify-notifications --channel=whatsapp` with local test
  routes/outboxes or the approved gateway environment.
- If custom notification templates are configured, inspect the JSONL outbox and
  confirm the rendered copy and JSON field names match the campus gateway
  contract.
- On a throwaway database, run `php placement seed-large-demo 80 10` before
  manual dense-board QA.
- Run `php placement browser-qa-plan --format=markdown` and use the output as
  the cross-browser/manual visual QA checklist for the release candidate.
- Run a browser smoke against `php -S localhost:8000 -t public`.
- Run `php placement smoke-http --base-url=http://localhost:8000` and confirm
  it checks hardened session cookies plus browser security headers. On demo or
  staffed test installs, include `--restricted-email=atlas@example.test` or an
  equivalent non-admin account so sensitive-page redirects are covered over
  HTTP.
- Check public `/health.php`, self-hosted `/health.php?ready=1`, and
  token-protected `/metrics.php`. For managed hosting, check readiness again
  through the real proxy with both the tenant `Host` and
  `Authorization: Bearer <CPE_METRICS_TOKEN>`; confirm missing or invalid
  credentials return the concealed 404 before the platform adapter loads.
- Run `php placement load-smoke --base-url=http://localhost:8000` and record the
  release-candidate baseline rather than treating it as a production capacity
  guarantee.
- Run `php placement work-outbox` with a local JSONL sink; confirm internal
  fanout, observer, and external sink counts are all reported, and that a second
  run does not redeliver acknowledged work. Exercise both audited replay commands
  against isolated dead-letter fixtures.
- Follow `docs/browser-qa.md` for dense-board desktop and phone-width checks.
- Confirm the GitHub Actions CI workflow is green.
- For PostgreSQL, run the fresh-database backup/restore contract, then separately
  perform an isolated staging restore drill and verify readiness plus HTTP smoke.
  `pg_restore --list` structural validation is not a substitute for that drill.

## Data Boundary

- Confirm `.legacy-private/` is ignored and not staged.
- Confirm `.env`, `.env.*`, `config/local.php`, and other local secret files
  are ignored and not staged.
- Confirm no API key, webhook token, authorization header, private key, or
  provider credential appears in public source, docs, examples, or tests.
- Confirm `data/*.sqlite`, backups, config snapshots, exports, import rollback
  snapshots, privacy safety copies, restore staging, and restore-safety copies
  are not staged. Only `data/.gitkeep` and `data/.htaccess` may be packaged or
  tracked.
- Confirm no real CSV, SQL, XLSX, DOCX, PDF, ZIP, RAR, 7z, screenshots, or
  historical archive files are staged.
- Confirm demo data is synthetic.
- Confirm a fresh browser install can start with the live dummy placement drive,
  and that System can clear dummy data while preserving admin/configuration
  state for actual imports.
- Confirm company/mobile/floor demo accounts do not expose private candidate
  tags, custom fields, event notes, or cross-company movement details beyond
  the role's operating need.
- Confirm company/mobile/floor demo accounts cannot open Admin, Import,
  Records, Reports, Preferences, or System unless the role matrix explicitly
  allows it.
- Confirm portability excludes control-plane metadata, sessions, password
  hashes, SSO secrets, notification credentials, and event delivery state.
- Round-trip a custom published workflow and every installed module into a clean
  target installation.

## Documentation

- README quickstart is current.
- `INSTALL.md` starts with the ZIP and guided browser setup path before server
  implementation details.
- Deployment docs keep PHP + SQLite as the default self-hosted stack and describe
  PostgreSQL/hosted operation as optional, separate infrastructure.
- Apache/Nginx examples and `.htaccess` files still point at or route through
  `public/` and do not expose `data/`.
- Environment variable docs and `examples/env/local.env.example` are synthetic
  and contain no real credentials or live routes.
- Functional spec, workflow matrix, glossary, configuration architecture,
  Indian-college template notes, migration-from-legacy guide, legacy inventory,
  and publication risk register are current.
- `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, and `LICENSE` are
  present.
- Implementation status lists known gaps honestly.
- Managed-hosting contract, disaster recovery, security operations, module
  development, and testing runbooks are current.

## Operational Smoke

- Build the ZIP and tarball and confirm both exclude `.legacy-private/` and
  runtime SQLite data.
- Verify the release package checksum sidecar with `php placement
  verify-package`.
- Extract the release package into a clean temp directory and run `doctor`,
  `install`, `readiness`, and `export` against a throwaway `CPE_DB_PATH`.
- Install a fresh demo database.
- Sign in as the demo admin.
- Open Board, Records, Reports, Import, Notifications, Admin, System, Public,
  and Student pages.
- Run `php placement backup`.
- Confirm the backup archive has `.metadata.json` and `.sha256` sidecars and that
  `restore` rejects missing/tampered metadata, corrupt checksums, wrong identity,
  and a relabeled SQLite archive before target mutation.
- Run `php placement privacy-report`.
- Run `php placement privacy-person-report` for a synthetic person and exercise
  `privacy-person-erase` only on a throwaway database, confirming all installed
  module handlers and one safety backup.
- Run `php placement rollback-import --list`.
- Run `php placement config-export /tmp/cpe-config.json`.
- Run `php placement config-validate /tmp/cpe-config.json`.
- Run `php placement config-validate examples/config-templates/engineering-multi-branch.json`.
- Run `php placement config-import /tmp/cpe-config.json`.
- Confirm pasted CSV imports reject oversized input when
  `CPE_IMPORT_MAX_BYTES` or `CPE_IMPORT_MAX_ROWS` is set low in a throwaway
  environment.
- If text identity settings are changed, confirm the top bar, Public page, and
  Student page render the configured copy without logo/image assets.
- If local terminology labels are changed, open Public, Student, and Records
  pages and confirm the words are rendered server-side without source edits.
- If non-operating weekday/date guardrails are set, create or import a synthetic
  round schedule on one of those days and confirm System/readiness warns.
- If audit request metadata retention is enabled, confirm the System audit log
  and `full` export show only the intended request metadata, then reset it if
  the release fixture should remain private by default.
- Validate any configured `import_header_aliases_json` against a small synthetic
  CSV before importing real institutional files.
- Run `php placement export`.
- Run `php placement placement-report`.
- Run `php placement suggest-slots`.
- Run `php placement optimize-slots`.

## Release Notes

State clearly that the project is alpha and that real placement data must not be
committed to the repository.
