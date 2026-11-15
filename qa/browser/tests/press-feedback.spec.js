import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
for (const [name,path] of [['application','../../../public/assets/app.css'],['marketing','../../../website/src/styles.css']]) {
  test(`${name} press feedback survives reduced motion and forced colors`, async ({page}) => {
    await page.route('**/*', route => route.abort());
    await page.setContent(`<style>${readFileSync(new URL(path, import.meta.url),'utf8')}</style><button class="button primary">Enabled</button><a class="button" href="#target">Navigate</a><button disabled>Disabled</button><a href="#target" aria-disabled="true">Unavailable</a><main id="target">Target</main>`);
    for (const forcedColors of ['none','active']) {
      await page.emulateMedia({reducedMotion:'reduce',forcedColors});
      for (const selector of ['button:not([disabled])','a.button']) {
        const control=page.locator(selector); await control.hover(); await page.mouse.down();
        expect(await control.evaluate(e=>getComputedStyle(e).textDecorationStyle)).toBe('double');
        await page.mouse.up();
        expect(await control.evaluate(e=>getComputedStyle(e).textDecorationStyle)).not.toBe('double');
      }
      const button=page.locator('button:not([disabled])'); await button.focus(); await page.keyboard.down('Space');
      expect(await button.evaluate(e=>getComputedStyle(e).textDecorationStyle)).toBe('double');
      expect(await button.evaluate(e=>getComputedStyle(e).outlineStyle)).not.toBe('none');
      await page.keyboard.up('Space');
    }
    for (const selector of ['button[disabled]','a[aria-disabled]']) {
      const control=page.locator(selector); await control.hover(); await page.mouse.down();
      expect(await control.evaluate(e=>getComputedStyle(e).textDecorationStyle)).not.toBe('double');
      await page.mouse.up();
    }
  });
}
