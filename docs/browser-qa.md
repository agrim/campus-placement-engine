# Browser QA

Browser QA is a release check, not a runtime dependency. The app should remain
plain PHP, server-rendered HTML, minimal CSS, and tiny vanilla JavaScript.

## Dense-Board Setup

Use a throwaway SQLite file:

```bash
export CPE_DB_PATH="$(mktemp -t cpe-browser-qa).sqlite"
php placement install-demo
php placement seed-large-demo 80 10
php placement readiness
php -S 127.0.0.1:8010 -t public
```

Open `http://127.0.0.1:8010/`.

Demo users all use `password123`:

- `admin@example.test`
- `control@example.test`
- `mobile@example.test`
- `floor@example.test`
- `placement@example.test`
- `auditor@example.test`

## Automated HTTP Smoke

From another terminal, run the dependency-free HTTP smoke before manual visual
QA:

```bash
php placement smoke-http \
  --base-url=http://127.0.0.1:8010 \
  --email=admin@example.test \
  --password=password123 \
  --restricted-email=atlas@example.test \
  --restricted-password=password123
```

The command checks login, hardened session cookie attributes, key authenticated
routes, public routes, restricted-role blocks for sensitive pages, and the CSS
asset. It does not replace manual cross-browser visual QA, but it catches broken
routing, auth, cookie hardening, access control, and static asset delivery with
no Node, browser driver, or container dependency.

## Manual QA Plan Artifact

Before cross-browser review, print the deterministic route and viewport matrix:

```bash
php placement browser-qa-plan --base-url=http://127.0.0.1:8010 --format=markdown
```

The command is read-only. It reports whether the current application database has
enough synthetic candidates/companies for dense-board QA, summarizes readiness
failures and warnings, and prints the screens, browser/viewport pairs, and
visual checks to cover manually. It does not install browser drivers or add a
frontend test dependency.

## Required Screens

Check these on desktop and a phone-width viewport:

- Login
- Board, all visible
- Board, compact mode
- Board, wanted flag
- Board, conflict flag
- Board, company filter
- Records
- Import preview
- Notifications
- Reports
- Admin
- System
- Public placements
- Candidate lookup, authenticated placement role

## Visual Checks

- No horizontal page overflow on phone-width viewport.
- Top navigation wraps instead of overlapping.
- Board columns remain readable with the large synthetic dataset.
- Board pages include the configured refresh interval; form-heavy pages such as
  Records, Import, and Admin do not auto-refresh.
- Compact board hides optional details but keeps candidate, company, status,
  flags, and actions readable.
- Buttons do not resize cards unexpectedly.
- Table and record grids scroll horizontally inside their own area instead of
  pushing the page wider.
- No browser console errors.

## Optional Playwright Smoke

The repository does not require Playwright, but maintainers can use it locally
through Codex or a one-off install. Do not commit Playwright packages just for
this smoke.

Suggested flow:

1. Start the dense-board setup above.
2. Open the login page.
3. Sign in as the demo admin.
4. Capture desktop and phone-width screenshots of Board, Records, Admin,
   System, Public, and Student pages.
5. Check the browser console for errors.

Artifacts from local browser QA should stay outside the public repository or in
an ignored scratch folder such as `.playwright-cli/` or `output/playwright/`.

## Current In-App Browser Pass

The 2026-07-18 release-candidate pass used the PHP built-in server, the installed
synthetic demo drive, and the Codex in-app browser. It covered a 1280x720 desktop
viewport and a 390x844 phone viewport without adding browser packages to the
repository.

Covered:

- Admin login and all 14 core authenticated routes, including Career Advising,
  Modules, candidate trace, maintenance pages, and public/status surfaces.
- Desktop and phone-width board layouts, compact mode, company filtering, wanted
  and conflict empty states, and a real non-mutating candidate CSV preview.
- Page-width, off-screen-content, interactive-control overlap, duplicate-ID,
  heading-order, accessible-control-name, and browser-console checks.
- Horizontal maintenance grids remaining contained in their own swipe/scroll
  areas while the phone-width board becomes a single-column flow.
- Zero image assets and one tiny local JavaScript confirmation helper.

The interactive pass exposed two defects that semantic HTTP smoke had not:

- The GET board-filter form omitted the `r=board` route field, so the portal
  redirect discarded company and compact filters. The form and regression test
  now retain the route and the browser recheck passes.
- Dynamic Admin user/workflow controls lacked programmatic names. Explicit
  labels and context-rich `aria-label` values now pass the browser semantic
  audit and an automated rendering contract.

This is current desktop/phone evidence for the in-app browser engine. It is not
a substitute for independent Safari and Firefox sign-off before a public
release.

## Historical Local Smoke

The 2026-06-28 local smoke used the PHP built-in server with a throwaway
SQLite database seeded by `install-demo` and `seed-large-demo 80 10`.

Covered:

- Admin login.
- Dependency-free HTTP smoke across login, authenticated pages, public pages,
  and CSS.
- Desktop board with dense synthetic queues.
- Phone-width compact board, Records, Admin, System, Reports, Notifications,
  Public, and authenticated candidate lookup.
- Authenticated Import route over HTTP.
- Page-width overflow checks on phone-width pages.
- Browser console error checks on the Playwright-driven pages.

This dated evidence must not be treated as the current visual pass; use the
2026-07-18 result above and rerun the matrix after UI or access-control changes.
