# Testing

The test strategy keeps the production dependency budget small while exercising
the two supported database shapes and real HTTP behavior.

## Fast Local Gates

```bash
php placement doctor
php tests/run.php
php tests/backup_restore_contract.php
php tests/database_connection_cleanup_contract.php
php tests/incident_boundary_contract.php
php tests/legacy_backup_compatibility_contract.php
php tests/worker_delivery_contract.php
php tests/postgres_connection_policy_contract.php
php tests/postgres_tls_contract.php
php tests/database_contract.php
php tests/mutation_concurrency_contract.php
php tests/database_lock_release_contract.php
php tests/install_concurrency_contract.php
php tests/hosted_install_atomicity_contract.php
php tests/hosted_install_preflight_contract.php
php tests/setup_authorization_contract.php
php tests/hosted_install_contract.php
php tests/managed_hosting_contract.php
php placement publication-check
```

`tests/run.php` is the broad SQLite integration suite.
`tests/migration_lock_contract.php` proves unknown pre-existing registry rows
stop pending DDL and that callback insertions and deletions cannot let a run
return success. Its fileless SQLite cases prove the outer transaction restores
the exact registry, while file-backed SQLite and conditional PostgreSQL cases
prove callback mutations remain committed evidence rather than claiming a
rollback that did not occur.
`tests/incident_boundary_contract.php` is the mandatory sentinel gate for
browser, CLI, protected-log, audit, recovery, and persisted-error boundaries.
It must pass before release; its synthetic secrets must be absent from every
public response, result, export, flash, and log surface it exercises.
`tests/worker_delivery_contract.php` is the mandatory worker state-machine gate.
It runs two independent workers against one queue, verifies token-fenced
acknowledgement and failure mutations, stale-claim recovery, stable idempotency
keys, dead-letter thresholds, dry-run immutability, and destination concealment.
CI runs it against both SQLite and PostgreSQL.
`tests/postgres_connection_policy_contract.php` is a no-network parser and
policy gate. It freezes the Cloud-compatible constructor and raw `fromUrl()`
signatures while proving strict runtime TLS, pool-mode, timeout, redaction,
duplicate/unknown-query, component-environment, and injection behavior.
`tests/postgres_tls_contract.php` is a conditional local and pull-request gate:
it skips unless `CPE_POSTGRES_TLS_TEST_URL` names a disposable production-shaped endpoint with
`verify-full`, a readable root certificate, and a bounded timeout. When enabled,
it requires post-connect `pg_stat_ssl` negotiated-TLS evidence. Pull-request CI
maps the optional secret of the same name, so forks without it record an explicit
skip rather than claiming live TLS proof. The tag release workflow requires the
secret and fails before testing or publication when it is absent.
`tests/mutation_concurrency_contract.php` releases paired independent processes
against one database. It proves same-key board retries return one stored result,
different-key stale moves cannot both mutate the card, and repeated concurrent
module toggles emit one lifecycle event. CI and release run it against SQLite
and a fresh dedicated PostgreSQL database.
The installation
concurrency contract releases two independent processes together against one
fresh database. Each performs a hosted install with a distinct tenant identity
and payload; the contract proves exactly one complete, internally consistent
winner and no losing identity, administrator, settings, seed, or audit state.
It always runs on SQLite and also runs against PostgreSQL when
`CPE_DATABASE_URL` identifies a fresh dedicated database. The setup authorization
contract includes real loopback HTTP cases for concealed denial, explicit local
terminal-code possession, token unlock, hosted refusal, grant consumption, and
installed route closure. The hosted-install contract proves managed-hosting
contract version 2 exposes no post-install identity-rebinding method and uses
exact schema and row snapshots to show that intended-tenant, wrong-tenant, and
malformed-identity attempts cannot relabel an installed self-hosted database.
The hosted-install atomicity contract interrupts each reviewed durable install
stage from inside the transaction. It proves the reserved identity and all
settings, administrator, demo, product, synchronizer, audit, and installed-marker
state roll back exactly, then proves a clean retry and the documented
check-plus-identity uncertain-success path, including the positive fresh
`installHosted()` flow and immutable same-tenant retry verification. CI and release run it against both
SQLite and a fresh dedicated PostgreSQL database. The database contract is
the same behavioral slice run against SQLite or PostgreSQL. The managed-hosting
contract proves that an external resolver can activate an isolated institution
context while missing adapters, mismatched identities, and cross-tenant sessions
fail closed. Control-plane and fleet-operation tests belong to the separate
management-plane repository.

The hosted-install preflight contract builds an installed schema at migrations
001–041 with no ownership claim and proves both same-tenant and wrong-tenant
direct install retries are read-only refusals. The checked lock-release contract
uses typed fixed failures locally and, in its mandatory PostgreSQL CI/release
run, deliberately releases the ownership, migration, and installation advisory
locks inside their critical sections. It proves each checked unlock fails and
the next cached database connection uses a new backend session.

`tests/database_connection_cleanup_contract.php` fault-injects ownership and
migration rollback cleanup failures. It proves the typed failure retains both
the primary and cleanup causes, the cached provider is discarded only after the
lock boundary returns, no partial schema survives, and a fresh connection can
complete migration. CI and release also run it against a fresh dedicated
PostgreSQL database. `tests/legacy_backup_compatibility_contract.php` is the
hermetic SQLite contract for ownership-before-backup upgrade, legacy checksum
conversion, no-overwrite/original preservation, import-manifest conversion,
explicit metadata-free PostgreSQL rollback refusal, path concealment, and
protected restore-staging cleanup reporting.

## Exact Public Alpha.1 Acceptance

The hermetic legacy contract is not evidence about bytes produced by the old
release. Before publishing a compatibility release, use an unmodified public
`v0.1.0-alpha.1` checkout/package to create a genuinely installed database and
backup, retain the backup's adjacent old checksum, and run:

```bash
CPE_ALPHA1_DATABASE_FIXTURE=/private/test/alpha1-installed.sqlite \
CPE_ALPHA1_BACKUP_FIXTURE=/private/test/alpha1-backups/app.sqlite \
php tests/alpha1_release_acceptance.php
```

This external/manual gate copies the fixtures before testing, verifies upgrade
ownership and metadata-backed backup evidence, verifies explicit backup
conversion, and proves the supplied artifacts remain unchanged. A `SKIP`
because either fixture variable is absent is not a release pass.

CI and release make this gate durable: both fetch full history, export the exact
`v0.1.0-alpha.1` tree, use that tagged CLI to create the installed database and
one-line-checksum backup, and pass those artifacts to the current acceptance
test. The manual form remains available for validating a downloaded release
package independently of GitHub Actions.

## PostgreSQL Contract

Point an empty disposable database at the contract:

```bash
export CPE_POSTGRES_POOL_MODE=direct
export CPE_POSTGRES_ALLOW_INSECURE_LOOPBACK=1
export CPE_DATABASE_URL='postgresql://USER:PASSWORD@127.0.0.1:5432/EMPTY_DATABASE?sslmode=disable'
php tests/database_contract.php
```

Run the mutation concurrency contract against a separate fresh database because
it installs and mutates its own fixture:

```bash
export CPE_DATABASE_URL='postgresql://USER:PASSWORD@127.0.0.1:5432/EMPTY_CONCURRENCY_DATABASE?sslmode=disable'
php tests/mutation_concurrency_contract.php
```

CI runs this against PostgreSQL 17. Locally, Apple Container is a good optional
way to host disposable PostgreSQL while the app itself continues to run directly
under PHP. It is not required for ordinary SQLite development.

## Real HTTP Gates

Start the app in one terminal:

```bash
php placement serve
```

Then run:

```bash
php placement smoke-http --base-url=http://127.0.0.1:8000
php placement load-smoke --base-url=http://127.0.0.1:8000 --requests=50 --concurrency=5
```

HTTP smoke covers authentication, CSRF/session attributes, security headers,
critical routes, role denial, and assets. Load smoke targets a read-only public
route, uses `curl_multi` when available, and otherwise uses PHP streams. Treat
its local latency as a regression signal, not a hosted capacity guarantee.

PHP `ext-curl` is optional for the default app but required when notification or
domain-event HTTP delivery is configured. Those paths use it to pin a verified
destination address and are separate from the stream fallback in the read-only
load probe.

Check operations endpoints directly:

```bash
curl -fsS http://127.0.0.1:8000/health.php
curl -fsS 'http://127.0.0.1:8000/health.php?ready=1'
```

The second command is the unchanged self-hosted contract. For hosted-mode
testing, send both the tenant Host and the operational Bearer token through the
same proxy route used in production:

```bash
curl -fsS \
  --header 'Host: tenant.example.edu' \
  --header "Authorization: Bearer $CPE_METRICS_TOKEN" \
  'https://hosting.example.test/health.php?ready=1'
curl -fsS \
  --header 'Host: tenant.example.edu' \
  --header "Authorization: Bearer $CPE_METRICS_TOKEN" \
  'https://hosting.example.test/metrics.php'
```

`php tests/managed_hosting_contract.php` runs the corresponding real-HTTP
regression checks, including concealed unauthorized responses that must not
load the platform adapter.

Use `php placement browser-qa-plan --format=markdown` for desktop, phone-width,
and cross-browser manual coverage. Browser automation is a development tool, not
a runtime dependency.

## Recovery Gate

`tests/backup_restore_contract.php` validates checksum-bound metadata, exact
target identity, pre-safety non-mutation for mismatch/tamper, SQLite read-only
archive identity inspection, same-identity restore, and safety-backup evidence.
CI and release also run it against a fresh PostgreSQL database, where
`pg_restore --list` is a structural preflight rather than an isolated restore
drill. A real PostgreSQL recovery drill still restores into an isolated target
and runs readiness plus HTTP smoke. See `disaster-recovery.md`.

## Release Gate

```bash
php placement package --target=dist --force
php placement verify-package dist/campus-placement-engine-0.1.0-alpha.3.tar.gz
php placement verify-package dist/campus-placement-engine-0.1.0-alpha.3.zip
```

Extract the package into a clean directory, run `php placement
publication-check`, install a throwaway database, and repeat doctor, readiness,
export, HTTP smoke, and restore. The broad suite also injects a runtime file
under the extracted package's `data/` tree and proves the Git-free publication
check rejects its deterministic relative path. Do not use the
historical private archive or real institutional records as fixtures.
