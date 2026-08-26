# Testing

The test strategy keeps the production dependency budget small while exercising
the two supported database shapes and real HTTP behavior.

## Fast Local Gates

```bash
php placement doctor
php tests/run.php
php tests/database_contract.php
php tests/managed_hosting_contract.php
php placement publication-check
```

`tests/run.php` is the broad SQLite integration suite. The database contract is
the same behavioral slice run against SQLite or PostgreSQL. The managed-hosting
contract proves that an external resolver can activate an isolated institution
context while missing adapters, mismatched identities, and cross-tenant sessions
fail closed. Control-plane and fleet-operation tests belong to the separate
management-plane repository.

## PostgreSQL Contract

Point an empty disposable database at the contract:

```bash
export CPE_DATABASE_URL='postgresql://USER:PASSWORD@127.0.0.1:5432/EMPTY_DATABASE'
php tests/database_contract.php
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

Use `php placement browser-qa-plan --format=markdown` for desktop, phone-width,
and cross-browser manual coverage. Browser automation is a development tool, not
a runtime dependency.

## Recovery Gate

For both drivers, create a backup, mutate a known synthetic setting, restore the
backup, and verify the original value plus readiness. PostgreSQL drills require
`pg_dump` and `pg_restore`. See `disaster-recovery.md`.

## Release Gate

```bash
php placement package --target=dist --force
php placement verify-package dist/campus-placement-engine-0.1.0-alpha.1.tar.gz
php placement verify-package dist/campus-placement-engine-0.1.0-alpha.1.zip
```

Extract the package into a clean directory, install a throwaway database, and
repeat doctor, readiness, export, HTTP smoke, and restore. Do not use the
historical private archive or real institutional records as fixtures.
