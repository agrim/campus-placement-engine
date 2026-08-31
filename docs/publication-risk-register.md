# Publication Risk Register

Date: 2026-07-15

This register tracks the main risks in turning the historical campus placement
engine into an open-source project.

| Risk | Severity | Current control | Release requirement |
|---|---|---|---|
| Raw candidate, company, student, or user data enters Git | Critical | `.legacy-private/`, runtime SQLite files, exports, backups, generated `dist/` archives, spreadsheets, docs, and SQL dumps are ignored | Run `php placement publication-check`, verify the package separately, and inspect `git status --short` before release |
| Local tokens, API keys, webhook credentials, or private keys enter Git | Critical | `.env`, `.env.*`, and `config/local.php` are ignored; `publication-check` scans public text files for common secret patterns | Keep real credentials in the environment only and investigate any potential-secret failure before release |
| Legacy SQL dumps expose passwords, emails, phone numbers, or operational rows | Critical | SQL dumps remain under `.legacy-private/` or ignored archive patterns; only reviewed institution data-plane migration SQL is allowed | Never publish SQL dumps; document schema through migrations only |
| Institution-specific language makes the app feel like an IIMB-only clone | High | Generic app name, configurable college name/timezone, workflow templates, glossary | Keep docs and UI names institution-neutral |
| Old password hashing or auth assumptions survive modernization | High | New app uses `password_hash()` and `password_verify()` | Keep auth tests green and avoid importing legacy password hashes |
| Browser UI depends on heavy frontend tooling | Medium | Server-rendered HTML, vanilla CSS, tiny vanilla JS only | No Node/build step in v1 runtime |
| Deployment becomes harder than the old PHP app | Medium | PHP built-in-server quickstart, SQLite default, browser installer, CLI installer | Keep Composer, Redis, frontend build tooling, and containers optional; PostgreSQL is required only for hosted operation |
| Placement-day operators act on stale screens or duplicate submits | High | Stale-board guard, idempotency keys, persisted stale thresholds, pausable board-only refresh, readiness checks | Continue browser QA with the generated refresh and stale-screen checklist |
| External messages are sent to wrong recipients | High | Delivery channels are optional, auditable, and support dry-run/outbox modes plus local SMS/WhatsApp certification preflight | Require institution-specific provider approval and controlled first live probe before live SMS/WhatsApp |
| Complex scheduling promises exceed the current planner | Medium | Slot planner documents bounded exact small-scope behavior and greedy fallback | Keep complex institutional solver work listed as future scope |
| Live database retains candidate identity after retention window | High | `privacy-report` and `anonymize-candidate` provide a safety-copy-backed anonymization path | Document institution responsibility for backups, exports, screenshots, and external copies |
| Private archive duplicates are deleted prematurely | Medium | Private hash ledger records archive checksums | Compare duplicates before deleting any private material |

## Pre-Release Checks

Run these before packaging or pushing a release:

```bash
git status --short
php placement publication-check
php placement doctor
php tests/run.php
php placement readiness
```

The release is not publication-safe if any unexpected raw archive, SQL, CSV,
spreadsheet, document, runtime database, backup, or export appears outside the
ignored private/runtime paths. It is also not publication-safe if
`publication-check` reports a potential secret pattern in public source, docs,
or examples; replace the value with a synthetic placeholder or move it to the
local environment before packaging.
