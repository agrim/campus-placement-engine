# Contributing

Thank you for helping make placement-day operations safer and easier for
colleges.

## Project Shape

This project deliberately keeps the v1 stack small:

- Plain PHP.
- SQLite by default.
- Server-rendered HTML.
- Vanilla CSS and tiny vanilla JavaScript.
- No Node build, framework, queue worker, Redis, image assets, or database
  server required for the core app.

Prefer small, testable changes that preserve that shape.

## Before Opening A Pull Request

Run:

```bash
php placement doctor
php tests/run.php
rg --files -g '*.php' app config public tests | xargs -n 1 php -l
php -l placement
php placement publication-check
```

Also run `php placement readiness` against a local demo install when the change
touches live-day operations.

Pull requests should keep the GitHub Actions CI workflow green. CI intentionally
uses the same plain PHP commands instead of Composer, Node, containers, or a
database server.

## Data Safety

Do not commit real candidate, recruiter, company, audit, account, or movement
data. Do not add legacy archive files, database dumps, spreadsheets, screenshots,
or documents unless they are synthetic and intentionally public.

Use the CSV templates under `examples/csv-templates/` and the demo installer for
fixtures.

## Contribution Areas

Good first areas:

- More dry-run acceptance scenarios.
- Import validation improvements.
- Documentation for small-college deployment.
- Accessibility and low-power-device usability fixes.
- Additional starter workflow configurations.

Avoid:

- Adding a frontend framework or build step to the core app.
- Replacing SQLite/PHP with heavier infrastructure for v1.
- Publishing private historical archive material.
- Introducing side effects without audit events or tests.
