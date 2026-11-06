# Campus Placement Engine in Codex Cloud

This repository supports Linux development without Cloud, Node, a database server, or production credentials. PHP and SQLite remain the default product stack.

In **Create environment**, select this GitHub repository and the intended branch. Ask setup to provision a PHP version supported by `README.md` and `app/Install/SystemRequirements.php`, including `mbstring`, `pdo_sqlite`, and `sqlite3`, plus Python 3.10+ with venv support. Use this **Install script**, from the repository root:

```bash
bash .codex/cloud/setup.sh
```

The script checks the existing PHP runtime, installs the committed schema-validation requirements in an ignored virtual environment, and validates the public schemas. It does not install PHP or change runtime policy. Allow package managers (PyPI and Python package downloads); no production secrets are required. Do not copy local databases, credentials, backups, or private archives into the environment.

Use these **Start skill** instructions: work from this checkout, export `CPE_TEST_SCHEMA_PYTHON="$PWD/.codex/cloud/.venv/bin/python"` in each task shell that runs PHP contracts, and use synthetic data only. No persistent service is needed for source checks. Re-run setup after changes to the Python requirement files; repository refresh does not reinstall them.

For a baseline development check:

```bash
export CPE_TEST_SCHEMA_PYTHON="$PWD/.codex/cloud/.venv/bin/python"
CPE_DB_DRIVER=sqlite php placement doctor
php tests/run.php
php tests/public_event_contract.php
php tests/api_http_contract.php
php placement publication-check
```

Start from an environment without deployment-specific `CPE_*` values. `doctor` is a preflight; an uninstalled synthetic checkout is normal. The test programs create temporary synthetic databases. Run the focused contracts and package verification required by `AGENTS.md` and CI for the actual change.

The optional `website/` frontend and `qa/browser/` harness each have their own `package-lock.json` and Node engine range. For work there, provision a matching Node version, run `npm ci --ignore-scripts --no-audit --no-fund` in the relevant directory, and follow that package's scripts. `npm run check --prefix website` covers the website. Browser QA additionally requires Playwright's browser/system dependencies and the synthetic server setup in `.github/workflows/production-hardening.yml` and `.github/workflows/ci.yml`; it is not enabled by this baseline.

PostgreSQL, concurrency, backup/restore, release-upgrade, and browser qualification have additional CI fixtures. Reproduce the relevant workflow with disposable databases, PostgreSQL 17 clients where required, and explicit test-only URLs; do not point tests at an institution database. Passing SQLite/schema checks does not verify those integrations or production readiness.

## Source publication prerequisites

The security work in this local checkout is not yet part of its current commit. Commit and push the reviewed, coherent security changes with these setup assets before creating the Cloud environment. Setup specifically requires the modified `app/Install/SystemRequirements.php` (including `minimumSecurePhp`) and the currently untracked `tests/requirements-security-bootstrap.txt`. It fails with an explicit message if either prerequisite is missing; it does not fall back to the older PHP policy. The setup assets alone do not publish these dependencies.

For optional browser QA, the current local hardening also includes modified `qa/browser/package.json`, `qa/browser/package-lock.json`, and `qa/browser/playwright.config.js`, plus untracked `qa/browser/security/network-policy.js`, `qa/browser/security/network-policy.test.js`, and `qa/browser/tests/fixtures.js`/`password-security.spec.js`. Publish that reviewed browser change coherently before using its `test:security` hook, or follow only the CI/browser commands present in the selected revision. Preserve the broader security change's dependencies; this list identifies setup-related prerequisites and is not a replacement for reviewing that change.

Save and publish the tested Cloud environment, then verify these commands in a new task. This directory is repository guidance; Codex does not automatically execute `setup.sh` just because it exists.

[Current Cloud environment setup](https://learn.chatgpt.com/docs/environments/cloud-environments)
