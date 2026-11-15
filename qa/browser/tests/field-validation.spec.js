import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../../../public/assets/app.js', import.meta.url), 'utf8');
const html = `<form data-field-validation><label for="email">Email</label><input id="email" type="email" required data-error="email-error" aria-describedby="hint"><span id="hint">Use your account email</span><p id="email-error" hidden>Error: Enter a valid email address.</p><label for="password">Password</label><input id="password" type="password" required data-error="password-error"><p id="password-error" hidden>Error: Enter your password.</p><button>Sign in</button></form>`;

test.beforeEach(async ({ page }) => { await page.route('**/*', route => route.abort()); await page.setContent(html); });

test('errors follow interaction, retain hints, clear stale announcements and native focus', async ({ page }) => {
  await page.addScriptTag({ content: source });
  const email = page.locator('#email'); const password = page.locator('#password');
  const live = page.locator('[aria-live]');
  await expect(email).toHaveAttribute('aria-invalid', 'false');
  await email.focus(); await password.focus();
  await expect(page.locator('#email-error')).toBeHidden();
  await email.fill('bad'); await password.focus();
  await expect(email).toHaveAttribute('aria-invalid', 'true');
  await expect(email).toHaveAttribute('aria-describedby', 'hint email-error');
  await expect(live).toHaveText('Email: Error: Enter a valid email address.');
  await email.fill('synthetic@example.test');
  await expect(email).toHaveAttribute('aria-invalid', 'false');
  await expect(email).toHaveAttribute('aria-describedby', 'hint');
  await expect(live).toBeEmpty();
  await page.locator('form').evaluate(form => form.requestSubmit());
  await expect(password).toBeFocused();
  await expect(password).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('#password-error')).toBeVisible();
  await expect(live).toBeEmpty();
  await password.fill('synthetic-not-a-real-password');
  await expect(password).toHaveAttribute('aria-invalid', 'false');
  await expect(live).not.toContainText('synthetic-not-a-real-password');
});

test('reset clears pending state but cancelled reset preserves it', async ({ page }) => {
  await page.addScriptTag({ content: source });
  await page.locator('#email').fill('bad'); await page.locator('#password').focus();
  await page.locator('form').evaluate(form => form.reset());
  await expect(page.locator('#email')).toHaveAttribute('aria-invalid', 'false');
  await page.waitForTimeout(600); await expect(page.locator('[aria-live]')).toBeEmpty();
  await page.locator('#email').fill('bad'); await page.locator('#password').focus();
  await page.locator('form').evaluate(form => { form.addEventListener('reset', e => e.preventDefault(), {once:true}); form.reset(); });
  await page.waitForTimeout(50);
  await expect(page.locator('#email')).toHaveAttribute('aria-invalid', 'true');
});

test('no script retains native validation; preexisting server errors are not replaced', async ({ page }) => {
  await page.locator('form').evaluate(form => form.requestSubmit());
  await expect(page.locator('#email')).toBeFocused();
  await page.locator('#email').evaluate(e => { e.setAttribute('aria-invalid','true'); e.setAttribute('aria-describedby','server-error'); });
  await page.addScriptTag({ content: source });
  await page.locator('#email').fill('synthetic@example.test'); await page.locator('#password').focus();
  await expect(page.locator('#email')).toHaveAttribute('aria-invalid','true');
  await expect(page.locator('#email')).toHaveAttribute('aria-describedby','server-error');
});
