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

4. Open the address printed in the terminal and complete the guided setup.

`setup` checks the computer first and then starts PHP's local web server. It
does not silently create a database or administrator. To run only the checks:

```bash
php placement setup --check
```

## Install on university web hosting

1. Confirm the host provides PHP 8.2 or newer with `pdo_sqlite` and `sqlite3`.
2. Upload the extracted release folder.
3. Point the domain's document root at the release's `public/` folder.
4. Make the `data/` folder writable by PHP.
5. Open the domain over HTTPS and complete the guided setup.

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
