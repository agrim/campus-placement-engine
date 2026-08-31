import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

export async function signIn(page, email = 'admin@example.test') {
  await page.goto('/?r=login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill('password123');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible();
}

export async function assertNoSeriousAccessibilityViolations(page) {
  const result = await new AxeBuilder({ page }).analyze();
  const violations = result.violations.filter(({ impact }) => impact === 'critical' || impact === 'serious');
  expect(
    violations.map(({ id, impact, nodes }) => ({
      id,
      impact,
      nodes: nodes.map(({ target, failureSummary }) => ({ target, failureSummary })),
    })),
    'serious or critical accessibility violations',
  ).toEqual([]);
}

export async function assertNoPageOverflow(page) {
  const dimensions = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(dimensions.scrollWidth, `page overflow: ${JSON.stringify(dimensions)}`).toBeLessThanOrEqual(
    dimensions.clientWidth + 1,
  );
}

export function watchBrowserErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') {
      errors.push(`console: ${message.text()}`);
    }
  });
  return errors;
}
