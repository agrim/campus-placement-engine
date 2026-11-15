# Browser form error announcement follow-up

Assessment: 2026-11-15, source revision `c753fd3`. Scope: app/Views/login.php and public/assets/app.js.

## Verified baseline and remaining gap

The synthetic sign-in form retains native required/email constraints and an explicit submit path. Isolated Chromium `requestSubmit()` with an empty email focuses that field and sets native `:user-invalid`. It does not submit data. At that point authored `aria-invalid` and `aria-describedby` are absent, and the page has no polite validation announcer. This confirms native focus works, not full assistive-technology compliance.

The [Google accessible-error-announcement guide](https://github.com/GoogleChrome/modern-web-guidance/blob/main/skills/modern-web-guidance/guides/accessibility/accessible-error-announcement.md) also requires interaction-timed error state, associated field messages, and delayed announcements. These remain open. A full-document server error page is a separate flow; putting a live region on its initial markup is not proof that its error is announced.

## Why no superficial ARIA patch was applied

A permanent `aria-invalid="false"` would conceal subsequent errors. A global handler that rewrites every input could overwrite server-invalid state, lose existing hint references, mark untouched fields invalid, mishandle checkbox/change/reset events, or expose credential values in announcements. Adding `novalidate` would remove the currently verified native focus behavior. None of these changes is justified merely to satisfy a source scan.

## Coherent implementation and acceptance requirements

1. Scope progressive enhancement to opted-in forms, retaining native constraints, submit buttons, server validation and no-JavaScript operation. Do not weaken CSP, CSRF, token handling, rate limits, or account-enumeration protections.
2. Provide field-specific text and unique error IDs. Use `textContent` for announcements; never include password, token, or submitted identity values. Preserve existing `aria-describedby` hint IDs and independently authored server-invalid states.
3. Synchronize visual and programmatic error state on input, blur, change, invalid and reset, including an appropriate unsupported-selector fallback. Initialize only enhanced fields; do not leave false state when the enhancement fails.
4. Use one polite announcer for blur/change errors; cancel stale timers after correction/reset. Retain native first-invalid focus on submit and avoid duplicate submit announcements.
5. Test empty/email/pattern/minlength failures, untouched fields, checkbox changes, repeated submits, corrections, reset, existing hints/server errors, no-JavaScript, and unsupported selectors. Cover keyboard and touch.
6. Validate Chromium, Firefox and Safari with actual screen-reader output before calling announcement behavior verified. Current isolated Chromium DOM checks are not a substitute.

No product behavior or security policy was changed by this audit record.

## Bounded sign-in enhancement follow-up

The two-field Engine sign-in form now explicitly opts into progressive field-error handling. Fixed DOM messages are associated only while invalid. A single polite announcer uses labels/messages, never values. Native constraints, submit focus, no-script operation and server authentication are unchanged. The event-based validity bridge does not depend on `:user-invalid` support, and reset handling honors cancellation. Other product forms are not opted in.

Three isolated Chromium tests verify untouched fields, malformed email, correction, hint retention, native submit focus, password non-disclosure, reset/stale timer cancellation, cancelled reset, no-script behavior and preexisting server-invalid preservation. These are DOM/behavior tests, not screen-reader speech evidence. Firefox/WebKit binaries are not installed locally; real AT and those engines remain unverified. Cloud remains a separate follow-up and must not inherit this product source automatically.
