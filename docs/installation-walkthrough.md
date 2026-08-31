# Installation walkthrough

This walkthrough gets a university evaluation running with synthetic data. Keep
real candidate information out until the institution has completed its security,
privacy, backup, accessibility, and operating review.

## 1. Download and verify

Download the newest ZIP plus `SHA256SUMS` from
[GitHub Releases](https://github.com/agrim/campus-placement-engine/releases).
Verify the checksum before extracting it:

```bash
sha256sum --check SHA256SUMS
```

On macOS, use `shasum -a 256 -c SHA256SUMS` when `sha256sum` is unavailable.

## 2. Check the host

The supported release window is PHP 8.2 through 8.4. The default SQLite setup
also needs `mbstring`, `pdo_sqlite`, and `sqlite3`, plus a writable `data/`
directory.

```bash
php placement setup --check
```

Resolve every `ERROR` before continuing. An uninstalled application is normal
at this point.

## 3. Run guided setup

```bash
php placement setup
```

Open the loopback address printed in the terminal and enter the one-time setup
code. The guided flow collects the university name, placement cycle, local
terminology, workflow, first administrator, and whether to include synthetic
demo records.

For a remote university server, configure the Apache or Nginx document root to
`public/`, provision the one-time remote setup authority described in
[deployment.md](deployment.md), and remove it after installation.

## 4. Rehearse before importing

Sign in, switch between placement-team roles, resolve the synthetic scheduling
clash, and review Candidate opportunities, Records, Reports, Admin, and System.
For a denser rehearsal:

```bash
php placement seed-large-demo 80 10
php placement readiness
```

Clear synthetic records from System before importing real information.

## 5. Establish recovery

Create an initial backup, copy the archive and both sidecars to encrypted
off-machine storage, and perform an isolated restore drill:

```bash
php placement backup
php placement readiness
```

Document the release version, archive checksum, PHP/database versions, restore
duration, named operators, and recovery decision.

## 6. Schedule the workers

Only schedule the workers that correspond to features the institution enables.
The integration worker is required for signed webhooks; notification delivery is
required only for configured external channels. See
[integration-worker.md](operations/integration-worker.md) and
[notifications.md](notifications.md).

## 7. Upgrade safely

Preserve `data/`, configuration, backups, and the old release directory. Replace
application files with a verified newer archive, then run:

```bash
php placement upgrade
php placement doctor
php placement readiness
```

The upgrade writes a backup before migrations. Never delete migration or
database-ownership evidence to force an upgrade.
