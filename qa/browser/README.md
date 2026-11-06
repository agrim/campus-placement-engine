# Browser qualification boundaries

Run qualification and promotion capture only against the synthetic Engine fixture
on a literal loopback HTTP(S) origin. `CPE_BROWSER_BASE_URL` accepts `127.0.0.1`,
`localhost`, or `[::1]` without URL credentials. Default test contexts, additional
contexts, and promotion capture reject direct requests to other origins, block
service workers, and close WebSockets.

This is a direct-request safeguard, not a network sandbox. Playwright does not
re-intercept later HTTP redirect hops after `route.continue()`. A permitted local
endpoint can redirect the browser to another origin. Do not use these contexts
with untrusted content, real institution data, external identity providers, or
arbitrary websites. Redirect behavior remains unchanged for authentic application
qualification.

The pinned Playwright package currently has no npm audit findings, but its bundled
Chromium, Firefox, and WebKit versions lag vendor security releases. The direct
request safeguard does not patch those binaries. Upstream compatible browser
rolls and a fresh browser qualification matrix remain required before claiming
the browser runtimes are patched.

Run `npm run test:security` for the URL-validation checks, and `npm run test:matrix`
against the synthetic fixture for the application journeys.
