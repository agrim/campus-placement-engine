import { test as base, expect } from '@playwright/test';
import { restrictQualificationContext } from '../security/network-policy.js';

export const test = base.extend({
  context: async ({ context, baseURL }, use) => {
    await restrictQualificationContext(context, baseURL);
    await use(context);
  },
});

export { expect };

export async function newQualificationContext(browser, baseURL, options = {}) {
  const context = await browser.newContext({ ...options, baseURL, serviceWorkers: 'block' });
  await restrictQualificationContext(context, baseURL);
  return context;
}
