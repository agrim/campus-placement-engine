import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { readFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
const root = fileURLToPath(new URL('../../../', import.meta.url));
const php = process.env.CPE_TEST_PHP || 'php';
const css = readFileSync(root + 'public/assets/app.css', 'utf8');
function fixture(origin) {
 return execFileSync(php, ['-r', `putenv('CPE_DB_PATH=/tmp/cpe-browser-preference-absent.sqlite'); require ${JSON.stringify(root + 'app/bootstrap.php')}; \\App\\Support\\ControllerFailure::flash(new \\App\\Core\\Http\\UserVisibleException('SYNTHETIC', 'Synthetic request failed.'), 'CPE_TEST_FAILURE', '${origin}', '${origin}'); $requests=[]; $candidates=[]; $companies=[]; require ${JSON.stringify(root + 'app/Views/preferences.php')};`], {encoding:'utf8'});
}
for (const action of ['create', 'resolve']) test(`${action} return focuses only its generic error and retains native fallback`, async ({browser}) => {
 const context = await browser.newContext({viewport:{width:320,height:800}, serviceWorkers:'block'});
 await context.route('**/*', async route => {
  if (route.request().resourceType() === 'document') return route.fulfill({contentType:'text/html',body:fixture('preference.' + action)});
  if (route.request().url().endsWith('/assets/app.css')) return route.fulfill({contentType:'text/css',body:css});
  return route.abort();
 });
 const page = await context.newPage(); await page.goto('http://audit.invalid/');
 const summary = page.locator(`#preference-${action}-errors`);
 await expect(summary).toBeFocused(); await expect(summary).toHaveText('Synthetic request failed.'); await expect(summary).toHaveAccessibleDescription('Synthetic request failed.');
 const create = page.locator('form[data-field-validation]');
 if (action === 'create') await expect(create).toHaveAttribute('aria-describedby','preference-create-errors');
 else { await expect(create).not.toHaveAttribute('aria-describedby', /.+/); await expect(page.locator('section[aria-describedby="preference-resolve-errors"]')).toBeVisible(); }
 await page.addStyleTag({content:':root{font-size:200%}'});
 expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 const scroll = page.locator('.table-scroll');
 await scroll.focus(); await page.keyboard.press('ArrowRight');
 await expect.poll(() => scroll.evaluate(el => el.scrollLeft)).toBeGreaterThan(0);
 expect((await new AxeBuilder({page}).analyze()).violations).toEqual([]);
 await expect(create.locator('input[name="_token"]')).toHaveCount(1);
 await create.evaluate(form => form.requestSubmit());
 await expect(page.locator('#preference_candidate_id')).toBeFocused();
 await context.close();
});
