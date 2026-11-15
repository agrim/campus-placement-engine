import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const css = readFileSync(new URL('../../../website/src/styles.css', import.meta.url), 'utf8');

test('candidate and opportunity labels wrap instead of hiding enlarged text', async ({ page }) => {
  await page.route('**/*', route => route.abort());
  await page.setViewportSize({ width: 320, height: 900 });
  await page.setContent(`<style>${css}</style><main style="width: 230px"><button class="candidate-row"><span class="candidate-avatar">DM</span><span class="candidate-name"><strong>Dev Malhotra</strong><small>Mechanical Engineering</small></span><span class="candidate-company"><strong>11:50</strong><small>Atlas Systems</small></span></button><div class="opportunity-strip"><article><div><strong>Atlas Systems</strong><small>Interview opportunity</small></div></article></div></main>`);
  await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
  const labels = page.locator('.candidate-name strong,.candidate-name small,.candidate-company strong,.candidate-company small,.opportunity-strip strong');
  for (const label of await labels.all()) {
    expect(await label.evaluate(e => e.scrollWidth <= e.clientWidth + 1)).toBe(true);
    expect(await label.evaluate(e => getComputedStyle(e).whiteSpace)).toBe('normal');
  }
  await page.locator('.candidate-row').focus();
  await expect(page.locator('.candidate-row')).toBeFocused();
  expect(await page.locator('.candidate-row').evaluate(e => getComputedStyle(e).outlineStyle)).not.toBe('none');
});
