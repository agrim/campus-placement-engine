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

## Preferences server-return ambiguity (source 7ac218d)

A synthetic probe exercised the real ControllerFailure::flash boundary with reviewed
UserVisibleException messages, then rendered the Preferences view. Create-invalid
and resolve-forbidden cases both returned the same page title, body focus, an
unidentified plain flash, and no form description. No authenticated POST or database
mutation was performed; the synthetic view had no request rows.

PreferenceController::create and ::resolve both redirect to Preferences and pass
only safe text/type into Flash. Consequently an error cannot reliably be associated
with the create form, a specific existing request or a particular field. Wanted
create/resolve has the same structural ambiguity. Permission/CSRF/incident errors
must not be presented as a malformed candidate or company selection.

A follow-up should add optional trusted action/form identity at the controller
boundary, retain only non-sensitive safe context, and render a form-level summary
for the matching surface. Resolve failures need authorization-safe request identity
and a fallback when the original row is no longer visible. Preserve current escaping,
anti-enumeration, fresh CSRF and one-time flash behavior. Never infer a field from
message wording. No server-return behavior changed in this pass; real AT response
remains unverified. The bounded login association is already implemented separately.

## Trusted Preferences action-origin follow-up

Preferences controller failures now pass a constant `preference.create` or
`preference.resolve` origin through the existing failure boundary into Flash.
The optional metadata is backward compatible: other callers still store only
type/message. It contains no submitted candidate, company, request ID, note,
credential, or field value. Reviewed public messages and opaque incident
references are unchanged; authorization-unavailable still rethrows, and
CSRF/capability/service checks are unchanged.

The Preferences view consumes flashes once and recognizes only these two origins
on errors. Create failures describe the named create form. Resolve failures
describe the named Requests section, deliberately not the create form or a
guessed option button. Each action has an escaped, focusable error group; native
autofocus targets the first group only, without requiring JavaScript or blaming
an individual field. Unknown origins and all success notices remain in the
shared layout once. A subsequent clean visit has no stale error or autofocus.

Synthetic rendered Chromium tests verify create/resolve association and focus,
no product script fallback, native required focus, fresh CSRF presence, 320px
with 200% root-font reflow, and axe. The new narrow test exposed a preexisting
keyboard-inaccessible horizontally scrolling Requests panel at enlarged text.
The table now has a named keyboard-scrollable wrapper and column scopes;
ArrowRight scrolling is directly tested. No live POST or authenticated data
was used.

Still partial: no exact resolve-row association (requires authorization-safe
context, including rows/options no longer rendered); no submitted-value
retention; no real screen-reader speech, Safari/Firefox, or OS text-scale proof.
This does not resolve server-return feedback in other module forms.

Validation for this follow-up: 146 PHP contracts pass, 10 Chromium field/error
interaction tests pass, 5 browser-control Node contracts pass, PHP lint and
`git diff --check` pass. The browser test uses local response fulfillment and
blocks all other requests; its generic error has an asserted accessible
name/description rather than a claim about actual screen-reader output.
