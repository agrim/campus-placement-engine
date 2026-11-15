import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const css = readFileSync(new URL('../../../website/src/styles.css', import.meta.url), 'utf8');
function luminance(rgb) {
  const channels = rgb.match(/[\d.]+/g).slice(0,3).map(Number).map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; });
  return channels[0] * .2126 + channels[1] * .7152 + channels[2] * .0722;
}
function contrast(a,b) { const x=luminance(a),y=luminance(b); return (Math.max(x,y)+.05)/(Math.min(x,y)+.05); }

test('focus ring contrasts with light pages and dark action/footer panels', async ({ page }) => {
  await page.route('**/*', route => route.abort());
  await page.setContent(`<style>${css}</style><main><button class="button">Light action</button></main><section class="action-panel"><button class="button">Dark action</button></section><footer><a href="#">Footer link</a></footer>`);
  for (const selector of ['main button','.action-panel button','footer a']) {
    const control=page.locator(selector); await control.focus();
    const colors=await control.evaluate(e => { let p=e.parentElement; while(p && getComputedStyle(p).backgroundColor==='rgba(0, 0, 0, 0)') p=p.parentElement; return {ring:getComputedStyle(e).outlineColor,background:getComputedStyle(p).backgroundColor,style:getComputedStyle(e).outlineStyle}; });
    expect(colors.style).toBe('solid'); expect(contrast(colors.ring,colors.background)).toBeGreaterThanOrEqual(3);
  }
  await page.emulateMedia({forcedColors:'active'});
  await page.locator('main button').focus();
  expect(await page.locator('main button').evaluate(e=>getComputedStyle(e).outlineStyle)).toBe('solid');
});
