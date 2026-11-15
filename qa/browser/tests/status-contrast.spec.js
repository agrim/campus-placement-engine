import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../../../public/assets/app.css', import.meta.url), 'utf8');
function luminance(channels) { const a=channels.map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4); return a[0]*.2126+a[1]*.7152+a[2]*.0722; }
function rgb(value) { return value.match(/[\d.]+/g).slice(0,3).map(Number); }
const samples=['#000000','#ffffff','#ff0000','#0000ff','#00ff00','#777777','#ffff00','#663399'];
for (const fallbackOnly of [false,true]) {
  test(`workflow headings contrast with configurable backgrounds (${fallbackOnly?'fallback':'progressive'})`,async({page})=>{
    await page.route('**/*',route=>route.abort());
    const css=fallbackOnly?source.replace('color: contrast-color(var(--status-bg, #ffffff));',''):source;
    const headings=samples.map(color=>{const channels=color.slice(1).match(/../g).map(v=>parseInt(v,16));const l=luminance(channels);const foreground=(l+.05)/.05>=1.05/(l+.05)?'#000000':'#ffffff';return `<section class="column"><h2 style="--status-bg:${color};--status-text:${foreground}">Status ${color}</h2></section>`;}).join('');
    await page.setContent(`<style>${css}</style>${headings}`);
    for(const heading of await page.locator('h2').all()){
      const colors=await heading.evaluate(e=>({fg:getComputedStyle(e).color,bg:getComputedStyle(e).backgroundColor}));
      const a=luminance(rgb(colors.fg)),b=luminance(rgb(colors.bg));
      expect((Math.max(a,b)+.05)/(Math.min(a,b)+.05)).toBeGreaterThanOrEqual(4.5);
    }
  });
}
