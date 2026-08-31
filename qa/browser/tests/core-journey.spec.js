import { expect, test } from '@playwright/test';
import {
  assertNoPageOverflow,
  assertNoSeriousAccessibilityViolations,
  signIn,
  watchBrowserErrors,
} from './helpers.js';

test('authentication, public access, role boundaries, and core administration remain usable', async ({ browser, page }) => {
  const browserErrors = watchBrowserErrors(page);

  await page.goto('/?r=login');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Institutional sign-in' })).toHaveCount(0);
  await page.getByLabel('Email').fill('admin@example.test');
  await page.getByLabel('Password').fill('incorrect-password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText(/invalid|failed|try again/i)).toBeVisible();

  await signIn(page);
  for (const route of [
    'portal',
    'operations',
    'board',
    'records',
    'reports',
    'import',
    'notifications',
    'preferences',
    'wanted',
    'advising',
    'modules',
    'integrations',
    'api-access',
    'admin',
    'system',
  ]) {
    await page.goto(`/?r=${route}`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await assertNoSeriousAccessibilityViolations(page);
  }
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

  const publicContext = await browser.newContext();
  const publicPage = await publicContext.newPage();
  await publicPage.goto('/?r=public');
  await expect(publicPage.getByRole('heading', { level: 1 })).toBeVisible();
  await assertNoSeriousAccessibilityViolations(publicPage);
  await publicContext.close();

  const restrictedContext = await browser.newContext();
  const restricted = await restrictedContext.newPage();
  await signIn(restricted, 'atlas@example.test');
  const denial = await restricted.goto('/?r=records');
  expect(denial?.status()).toBe(403);
  await expect(restricted.getByText('Access denied.')).toBeVisible();
  await restrictedContext.close();

  expect(browserErrors).toEqual([]);
});

test('board filters and saved defaults survive browser navigation', async ({ page }) => {
  const browserErrors = watchBrowserErrors(page);
  await signIn(page);
  await page.goto('/?r=board');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

  await page.getByLabel('Compact').check();
  await page.getByLabel('Flag').selectOption('wanted');
  await page.getByRole('button', { name: 'Filter' }).click();
  await expect(page).toHaveURL(/r=board/);
  await expect(page).toHaveURL(/compact=1/);
  await expect(page).toHaveURL(/flag=wanted/);
  await expect(page.locator('.board')).toHaveClass(/board-compact/);

  await page.getByRole('button', { name: 'Save as my default' }).click();
  await page.goto('/?r=board');
  await expect(page.getByText('Using your saved board default.')).toBeVisible();
  await expect(page.getByLabel('Compact')).toBeChecked();
  await page.getByRole('button', { name: 'Clear my default' }).click();
  expect(browserErrors).toEqual([]);
});

test('keyboard, responsive reflow, zoom, reduced motion, and forced colors preserve consequential controls', async ({ page, browserName }) => {
  const browserErrors = watchBrowserErrors(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await signIn(page);
  await page.goto('/?r=board');
  await assertNoPageOverflow(page);
  await expect(page.getByRole('button', { name: 'Pause automatic refresh' })).toBeVisible();
  await page.getByRole('button', { name: 'Pause automatic refresh' }).click();
  await expect(page.getByRole('status')).toHaveText('Automatic board refresh paused.');
  await page.getByRole('button', { name: 'Resume automatic refresh' }).click();
  await expect(page.getByRole('status')).toContainText('Next board refresh in');

  await page.keyboard.press('Home');
  await page.keyboard.press('Tab');
  const focused = page.locator(':focus');
  await expect(focused).toBeVisible();
  await expect(focused).toHaveCSS('outline-style', /^(?!none$).+/);

  for (const zoom of [2, 4]) {
    await page.evaluate((value) => {
      document.documentElement.style.zoom = String(value);
    }, zoom);
    await expect(page.getByRole('button', { name: 'Filter' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible();
  }
  await page.evaluate(() => {
    document.documentElement.style.zoom = '';
  });

  await page.emulateMedia({ reducedMotion: 'reduce' });
  await expect(page.getByRole('button', { name: 'Filter' })).toBeVisible();
  if (browserName === 'chromium') {
    await page.emulateMedia({ forcedColors: 'active', reducedMotion: 'reduce' });
    await expect(page.getByRole('button', { name: 'Filter' })).toBeVisible();
  }
  expect(browserErrors).toEqual([]);
});
