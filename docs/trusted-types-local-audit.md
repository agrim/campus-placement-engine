# Trusted Types report-only compatibility probe

Source revision: 9e54b69. Read the upstream `trusted-types` guide in full.

An isolated Chromium context loaded the built marketing app and synthetic rendered
login, Wanted and empty CSV import pages. Requests were fulfilled from local
artifacts or blocked; no live service, saved browser session, credentials, report
collector or deployment was used. Each document received only a test response
header `Content-Security-Policy-Report-Only: require-trusted-types-for 'script'`.
A pre-navigation securitypolicyviolation listener recorded directive, disposition,
source file and line, not DOM contents or input values.

- Marketing mount and guided mission completion: zero observed TT violations.
- Login, Wanted and empty CSV native invalid submissions: zero observed TT violations.
- Each context then performed one harmless detached innerHTML positive-control
  write. All four emitted one report-only violation, proving instrumentation active.

This advances the guide from wholly unverified to **partially assessed**. It does
not certify all React paths, all PHP pages, injected module UI, browser versions,
third-party extensions or deployment behavior. It does not establish enforcement
compatibility, acceptable policy names or sanitization policy. No default/passthrough
policy, enforcement header, sanitizer dependency or telemetry was added. Full sink
inventory and realistic route/interaction coverage remain prerequisites.

## Server-error return boundary

A synthetic rendered login with the existing `Invalid login.` server flash focused
its autofocus email input; the flash had no alert role and email had no error
description. This is a genuine association/return-focus gap, distinct from local
required-field feedback. AuthController uses a deliberately generic error, and
ControllerFailure/Flash preserve only message/type, not field IDs or failed form
identity. Do not mark email or password individually wrong from this result.

A safe follow-up needs a form-level server-error summary, focus policy that wins
against initial autofocus only on error returns, and typed field/error metadata for
non-auth failures. Preserve generic authentication and incident boundaries, never
retain passwords, and do not map exception text to a guessed field. This probe did
not change authentication semantics or introduce global auto-focus.
