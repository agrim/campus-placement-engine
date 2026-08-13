# Codex Apple Container Ops

This note is for future Codex sessions. It explains how to operate the optional
Apple Container test server without disturbing the tracked source app.

## Mental Model

- Tracked source lives in the repo root.
- The ignored deployment sandbox lives at `http/`.
- The Apple Container named `cpe-http-test` mounts only `http/` at `/site`.
- The web document root is `/site/public`.
- The test URL is `http://127.0.0.1:8080/`.
- The sandbox database is `http/data/app.sqlite`.

Do not manually copy scattered files into the container. Use the helper script.

## Usual Requests

When the user asks to start the container:

```bash
scripts/http-container start
```

When the user asks to stop it:

```bash
scripts/http-container stop
```

When the user asks to restart it:

```bash
scripts/http-container restart
```

When the user asks to update or refresh the container from the repo:

```bash
scripts/http-container sync
scripts/http-container restart
```

`sync` refreshes `http/` from the repo while preserving `http/data/`, so the
sandbox SQLite database survives ordinary source refreshes.

## Verification

Check the URL:

```bash
scripts/http-container url
```

Check status:

```bash
scripts/http-container status
```

Check logs:

```bash
scripts/http-container logs
```

Run the app smoke test against the container:

```bash
php placement smoke-http \
  --base-url=http://127.0.0.1:8080 \
  --email=admin@example.test \
  --password=password123 \
  --restricted-email=atlas@example.test \
  --restricted-password=password123
```

The smoke may require sandbox escalation because it probes localhost.

## If The Sandbox Is Empty

Create or refresh it:

```bash
scripts/http-container sync
```

If no database exists and a demo install is desired:

```bash
cd http
php placement install-demo
```

Then start or restart:

```bash
cd ..
scripts/http-container restart
```

## If Apple Container Is Not Running

The helper starts Apple Container system services automatically through:

```bash
container system start --enable-kernel-install --timeout 120
```

The first run may install Apple's recommended default kernel. This is expected
on a fresh Apple Container installation.

## If Port 8080 Is Busy

Use a temporary alternate port:

```bash
CPE_HTTP_PORT=8081 scripts/http-container start
```

Or use a separate named container:

```bash
CPE_HTTP_CONTAINER=cpe-http-test-2 CPE_HTTP_PORT=8081 scripts/http-container start
```

## Guardrails

- Keep `http/` ignored and disposable.
- Do not commit `http/data/app.sqlite`.
- Do not use Apple Container as a required v1 dependency.
- Prefer `php placement serve` for normal source-development checks.
- Use the Apple Container server only when the user asks for the isolated
  testing deployment shape.
