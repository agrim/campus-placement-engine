import { randomUUID } from 'node:crypto';
import { expect, test, newQualificationContext } from './fixtures.js';
import { signIn } from './helpers.js';

test('admin passwords reject overlong input without truncation and accept 72-byte Unicode', async ({ page, browser, baseURL }) => {
  const email = `password-boundary-${randomUUID()}@example.test`;
  const overlong = 'a'.repeat(73);
  const initialPassword = '密'.repeat(24);
  const replacementPassword = '語'.repeat(24);
  expect(Buffer.byteLength(initialPassword, 'utf8')).toBe(72);
  expect(Buffer.byteLength(replacementPassword, 'utf8')).toBe(72);

  await signIn(page);
  await page.goto('/?r=admin');
  const createForm = page.locator('form').filter({ has: page.getByRole('button', { name: 'Create user', exact: true }) });
  const resetForm = page.locator('form').filter({ has: page.getByRole('button', { name: 'Reset password', exact: true }) });

  async function fillNewUser(password) {
    await createForm.getByLabel('Name', { exact: true }).fill('Synthetic password boundary');
    await createForm.getByLabel('Email', { exact: true }).fill(email);
    await createForm.getByLabel('Role', { exact: true }).selectOption('auditor');
    await createForm.getByLabel('Password', { exact: true }).fill(password);
  }

  async function submitAndCheckBytes(form, button, route, password) {
    const submission = page.waitForRequest((request) => request.method() === 'POST'
      && new URL(request.url()).searchParams.get('r') === route);
    await form.getByRole('button', { name: button, exact: true }).click();
    const request = await submission;
    expect(new URLSearchParams(request.postData()).get('password')).toBe(password);
  }

  await fillNewUser(overlong);
  await expect(createForm.getByLabel('Password', { exact: true })).toHaveValue(overlong);
  await submitAndCheckBytes(createForm, 'Create user', 'admin-user', overlong);
  await expect(page.locator('.flash.error')).toContainText('8–72 byte password');
  await expect(page.getByRole('cell', { name: email, exact: true })).toHaveCount(0);

  await fillNewUser(initialPassword);
  await submitAndCheckBytes(createForm, 'Create user', 'admin-user', initialPassword);
  await expect(page.locator('.flash.success')).toHaveText('User created.');

  await resetForm.getByLabel('User', { exact: true }).selectOption({ label: `${email} / auditor` });
  await resetForm.getByLabel('New password', { exact: true }).fill(overlong);
  await expect(resetForm.getByLabel('New password', { exact: true })).toHaveValue(overlong);
  await submitAndCheckBytes(resetForm, 'Reset password', 'admin-password', overlong);
  await expect(page.locator('.flash.error')).toContainText('Password must be 8–72 bytes');

  const accountContext = await newQualificationContext(browser, baseURL);
  try {
    const accountPage = await accountContext.newPage();
    async function signInWithPassword(password) {
      await accountPage.goto('/?r=login');
      await accountPage.getByLabel('Email').fill(email);
      await accountPage.getByLabel('Password').fill(password);
      await accountPage.getByRole('button', { name: 'Sign in', exact: true }).click();
    }

    await signInWithPassword(initialPassword);
    await expect(accountPage.getByRole('button', { name: 'Sign out' })).toBeVisible();
    await accountPage.getByRole('button', { name: 'Sign out' }).click();

    await resetForm.getByLabel('User', { exact: true }).selectOption({ label: `${email} / auditor` });
    await resetForm.getByLabel('New password', { exact: true }).fill(replacementPassword);
    await submitAndCheckBytes(resetForm, 'Reset password', 'admin-password', replacementPassword);
    await expect(page.locator('.flash.success')).toHaveText('Password reset.');

    await signInWithPassword(initialPassword);
    await expect(accountPage.getByText('Invalid login.', { exact: true })).toBeVisible();
    await signInWithPassword(replacementPassword);
    await expect(accountPage.getByRole('button', { name: 'Sign out' })).toBeVisible();
  } finally {
    await accountContext.close();
  }
});
