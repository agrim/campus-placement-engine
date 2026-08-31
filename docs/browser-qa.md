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

## Automated Browser Qualification

The pinned harness under `qa/browser/` is a CI and release dependency only. It
does not add Node.js, a browser driver, or a frontend build to the packaged
Engine. Against the dense-board server above, run:

```bash
cd qa/browser
npm ci --ignore-scripts --no-audit --no-fund
npx playwright install chromium firefox webkit
CPE_BROWSER_BASE_URL=http://127.0.0.1:8010 npm test
CPE_BROWSER_BASE_URL=http://127.0.0.1:8010 npm run test:matrix
```

Every pull request runs Chromium. A tagged release runs Chromium, Firefox, and
WebKit. The synthetic journey covers authentication failure and success,
logout, SSO-disabled presentation, a restricted-role denial, all primary
administrator screens, public aggregates, board filters and saved defaults,
pausable board refresh, keyboard focus, narrow reflow, 200%/400% zoom,
reduced-motion, forced-colors, browser errors, and serious/critical axe
violations. Failure screenshots, video, and traces contain synthetic data only
and are retained by CI for seven days.

Local artifacts remain in ignored `output/playwright/`.

## Manual Assistive-Technology Pass

Automation does not replace a release-candidate screen-reader pass. Record the
browser, OS, assistive-technology version, release commit, viewport, operator,
date, and result outside the repository, then link the evidence in the release
decision. At minimum:

1. With Safari and VoiceOver, sign in, traverse the primary navigation, pause
   and resume board refresh, change a filter, open a candidate, and sign out.
2. Confirm headings, landmarks, form labels, validation errors, flash messages,
   tables, button purposes, and refresh status are announced in a useful order.
3. Repeat the consequential keyboard journey at 200% and 400% zoom and on a
   narrow portrait viewport. Confirm no keyboard trap or hidden action.
4. Repeat a representative journey in Firefox with the platform screen reader.
5. Treat any consequential blocker, serious/critical automated violation, or
   unexplained browser console error as a release blocker.

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

This historical pass is not a substitute for the current automated matrix and
manual Safari/VoiceOver plus Firefox sign-off before a public release.

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
