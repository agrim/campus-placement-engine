# Apple Container Testing Server

This is an optional local testing shape. It is not required for normal
development or for the public v1 stack.

Recommendation: use `php placement serve` for the normal edit-test loop. Use
Apple Container when testing a disposable PostgreSQL service, a clean packaged
runtime, or container-specific deployment behavior. That keeps the common path
fast while preserving a reproducible isolation tool when it adds evidence.

The source app remains in the repo. The ignored `http/` directory is a local
deployment sandbox that can be edited, broken, reset, or deleted without
touching tracked source files.

```text
repo source -> ignored http/ sandbox -> Apple Container -> http://127.0.0.1:8080/
```

## Commands

Refresh the ignored sandbox from the current repo:

```bash
scripts/http-container sync
```

Start the local Apple Container web server:

```bash
scripts/http-container start
```

Open:

```text
http://127.0.0.1:8080/
```

Stop it:

```bash
scripts/http-container stop
```

Restart it:

```bash
scripts/http-container restart
```

Show logs or status:

```bash
scripts/http-container logs
scripts/http-container status
```

## What Gets Served

The container mounts only:

```text
http/ -> /site
```

The PHP document root remains:

```text
/site/public
```

The sandbox keeps its own SQLite database at:

```text
http/data/app.sqlite
```

The `sync` command refreshes app source, docs, config, migrations, examples,
and public files, while preserving `http/data/`.

## Overrides

Useful environment overrides:

```bash
CPE_HTTP_PORT=8081 scripts/http-container start
CPE_HTTP_CONTAINER=cpe-http-test-2 scripts/http-container start
CPE_HTTP_IMAGE=docker.io/library/php:8.3-cli scripts/http-container start
```

## Notes

- `http/` is ignored by Git.
- Apple Container must be installed and its system service must be running.
- The helper starts the Apple Container system automatically if needed.
- The first Apple Container run may install Apple's recommended default kernel.
- Normal development can still use `php placement serve`.
- Codex operating notes live in `docs/codex-container-ops.md`; the ignored
  sandbox also carries `http/CODEX_CONTAINER_MANUAL.md`.
