import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';

const baseURL = process.env.CPE_BROWSER_BASE_URL || 'http://127.0.0.1:8010';
const websiteAssets = resolve('../../website/public/demo');
await mkdir(websiteAssets, { recursive: true });

const browser = await chromium.launch();
const context = await browser.newContext({
  baseURL,
  viewport: { width: 1440, height: 900 },
  recordVideo: { dir: resolve('../../output/playwright/promotion-video'), size: { width: 1440, height: 900 } },
});
const page = await context.newPage();
await page.goto('/?r=login');
await page.getByLabel('Email').fill('admin@example.test');
await page.getByLabel('Password').fill('password123');
await page.getByRole('button', { name: 'Sign in' }).click();
await page.goto('/?r=board');
await page.waitForTimeout(1200);
await page.screenshot({ path: resolve(websiteAssets, 'board-desktop.png') });

await page.getByLabel('Compact').check();
await page.getByLabel('Flag').selectOption('wanted');
await page.getByRole('button', { name: 'Filter' }).click();
await page.waitForTimeout(1200);
await page.goto('/?r=operations');
await page.waitForTimeout(1200);
await page.goto('/?r=records');
await page.waitForTimeout(1200);
await page.goto('/?r=system');
await page.waitForTimeout(1200);
await page.goto('/?r=public');
await page.waitForTimeout(1200);

const video = page.video();
await page.close();
if (video) {
  await video.saveAs(resolve(websiteAssets, 'campus-placement-engine-demo.webm'));
}
await context.close();

const mobileContext = await browser.newContext({ baseURL, viewport: { width: 390, height: 844 } });
const mobile = await mobileContext.newPage();
await mobile.goto('/?r=login');
await mobile.getByLabel('Email').fill('admin@example.test');
await mobile.getByLabel('Password').fill('password123');
await mobile.getByRole('button', { name: 'Sign in' }).click();
await mobile.goto('/?r=board&compact=1');
await mobile.screenshot({ path: resolve(websiteAssets, 'board-mobile.png') });
await mobileContext.close();
await browser.close();

console.log(`Promotion assets written to ${websiteAssets}`);
