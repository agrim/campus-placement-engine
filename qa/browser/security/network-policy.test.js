import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isQualificationRequest, qualificationOrigin } from './network-policy.js';

// These tests cover URL validation for direct intercepted requests. Redirect
// hops are not re-intercepted by Playwright and are not an egress guarantee.
test('qualification origin accepts only literal loopback HTTP(S) hosts', () => {
  for (const url of ['http://127.0.0.1:8010', 'http://localhost:9000', 'https://[::1]:8443',
    'http://127.1:8010', 'http://2130706433:8010', 'http://0x7f000001:8010']) {
    assert.equal(qualificationOrigin(url), new URL(url).origin);
    assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(qualificationOrigin(url)).hostname));
  }
  for (const url of ['https://example.test', 'http://localhost.example.test', 'file:///tmp/index.html',
    'http://user:password@127.0.0.1:8010', 'http://0.0.0.0:8010',
    'http://127.0.0.1.evil.test:8010', 'http://localhost.:8010',
    'http://127.0.0.1:8010@evil.test/', 'http://[::ffff:127.0.0.1]:8010',
    'http://%00127.0.0.1:8010', 'http://127.0.0.1\n.evil.test:8010']) {
    assert.throws(() => qualificationOrigin(url));
  }
});

test('qualification requests stay on the exact configured origin', () => {
  const origin = qualificationOrigin('http://127.0.0.1:8010');
  assert.equal(isQualificationRequest(`${origin}/register?token=synthetic`, origin), true);
  for (const url of ['https://example.test/', 'http://127.0.0.1:8021/', 'http://localhost:8010/',
    'http://127.0.0.1:8010@evil.test/', 'http://user:pass@127.0.0.1:8010/',
    'file:///etc/passwd', 'data:text/html,test', 'not a URL']) {
    assert.equal(isQualificationRequest(url, origin), false);
  }
});
