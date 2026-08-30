# Install Campus Placement Engine

Get your university's placement workspace running in a few minutes, then use
the guided setup to choose your placement cycle, workflow, terminology, first
administrator, and whether to begin with sample data.

> This is an alpha release. Evaluate it with synthetic data before using it for
> real placement operations, and keep verified backups outside the web server.

## Fastest way to try it

1. Download the newest `campus-placement-engine-<version>.zip` and its
   `.sha256` file from the
   [GitHub Releases page](https://github.com/agrim/campus-placement-engine/releases).
2. Verify the checksum, then extract the ZIP.
3. Open a terminal in the extracted folder and run:

   ```bash
   php placement setup
   ```

4. Open the address printed in the terminal, enter the one-time setup code
   printed beside it, and complete the guided setup. The code is valid for 20
   minutes and is consumed by the setup grant; do not put it in a URL.

`setup` checks the computer first and then starts PHP's local web server. It
does not silently create a database or administrator. To run only the checks:

```bash
php placement setup --check
```

## Fastest noninteractive start

For automation, a university-managed server, or a local terminal workflow that
must not pause for prompts, use the existing preflight and installer directly:

```bash
php placement setup --check
CPE_ADMIN_PASSWORD='replace-with-a-strong-secret' php placement install \
  --college='Example University' \
  --admin-name='Placement Administrator' \
  --admin-email='placements@example.edu'
php placement serve
```

The install command is noninteractive, consumes the password only from its
process environment, and refuses an already installed target. Omit `serve` when
Apache, Nginx, PHP-FPM, or a hosting control panel already serves `public/`.
This path is still the same installer and migration system as guided setup; it
does not add a second setup mechanism. The SQLite default requires no Redis,
Kafka, RabbitMQ, Node.js, container runtime, or Cloud service.

## Install on university web hosting

1. Confirm the host provides PHP 8.2 or newer with `mbstring`, `pdo_sqlite`, and `sqlite3`.
2. Upload the extracted release folder.
3. Point the domain's document root at the release's `public/` folder.
4. Make the `data/` folder writable by PHP.
5. Generate a 32-byte base64url setup token and set it as `CPE_SETUP_TOKEN` in
   the host's environment or secret manager before allowing public traffic:

   ```bash
   php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "="), PHP_EOL;'
   ```

6. Open `/install.php` over HTTPS, enter that token on the authorization page,
   and complete the guided setup. Never put the token in a query string or URL.
7. Remove `CPE_SETUP_TOKEN` from the runtime after installation.

Browser setup uses a file-backed preinstall session so the new administrator
stays logged in across the transition. If the deployment explicitly configures
`CPE_SESSION_DRIVER=database`, install from SSH with `php placement install`
instead. Loopback source addresses do not authorize an environment-token
exchange. A TLS-terminating proxy must set `CPE_SESSION_SECURE=force`;
forwarded headers do not authorize setup.

On Apache shared hosting where the document root cannot be changed, the root
`.htaccess` provides a fallback. The `public/` document root remains the safer
and preferred configuration. Nginx, PHP-FPM, PostgreSQL, environment variables,
and production hardening are covered in [docs/deployment.md](docs/deployment.md).

## Verify the download

On macOS or Linux:

```bash
shasum -a 256 -c campus-placement-engine-<version>.zip.sha256
```

The release also includes `SHA256SUMS` for both the ZIP and tarball. The Engine
can inspect either archive and its adjacent checksum before extraction:

```bash
php placement verify-package /path/to/campus-placement-engine-<version>.zip
```

## Before using real candidate data

- Start empty, or clear all sample data from **System** before importing.
- Use HTTPS and restrict server access to the intended placement team.
- Run `php placement readiness` and resolve every failing check.
- Run `php placement backup` and move the database plus its checksum to
  institution-controlled, encrypted storage.
- Never commit candidate records, runtime databases, exports, or credentials to
  Git.

## Upgrade an existing installation

Back up first, replace the application files while preserving your runtime
configuration and `data/`, then run:

```bash
php placement upgrade
```

The upgrade command writes another backup before applying migrations. Review
[docs/disaster-recovery.md](docs/disaster-recovery.md) before upgrading a live
installation.

For a public `v0.1.0-alpha.1` installation, `upgrade` first validates the
complete installed legacy Engine signature and immutable institution identity,
then permanently claims Engine database ownership before creating the
metadata-backed pre-migration backup. Mixed, partial, opposite-plane, or
identity-drifted databases fail closed.

Old alpha.1 SQLite backup files use a one-line checksum and cannot be restored
directly by current releases. Preserve the original archive and checksum, then
create a new current-format copy explicitly:

```bash
php placement convert-legacy-backup /secure/path/to/old.sqlite \
  --confirm=CONVERT --target-dir=/secure/path/to/converted
```

The converted archive has ownership evidence, metadata, and a two-entry
checksum. Legacy PostgreSQL archives require an isolated restore validation and
are intentionally not converted by this command.
