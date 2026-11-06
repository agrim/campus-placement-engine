const loopbackHosts = new Set(['127.0.0.1', 'localhost', '[::1]']);

export function qualificationOrigin(baseURL) {
  const url = new URL(baseURL);
  if (!['http:', 'https:'].includes(url.protocol)
      || !loopbackHosts.has(url.hostname)
      || url.username || url.password) {
    throw new Error('Engine browser qualification requires a literal loopback HTTP(S) base URL without credentials.');
  }
  return url.origin;
}

export function isQualificationRequest(url, origin) {
  try {
    const target = new URL(url);
    return ['http:', 'https:'].includes(target.protocol)
      && !target.username && !target.password && target.origin === origin;
  } catch {
    return false;
  }
}

export async function restrictQualificationContext(context, baseURL) {
  const origin = qualificationOrigin(baseURL);
  await context.route('**/*', async (route) => {
    // This checks direct intercepted requests only. Playwright does not
    // re-intercept redirect hops after continue(); this is not an egress
    // sandbox. Preserve real auth redirects and use trusted synthetic fixtures.
    if (isQualificationRequest(route.request().url(), origin)) {
      await route.continue();
    } else {
      await route.abort('blockedbyclient');
    }
  });
  // No Engine qualification journey uses WebSockets. Close them before a server
  // connection is made, including sockets initiated by an injected test page.
  await context.routeWebSocket('**/*', (socket) => socket.close());
}
