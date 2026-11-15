import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const websiteCSS=readFileSync(new URL('../../../website/src/styles.css',import.meta.url),'utf8');
const appCSS=readFileSync(new URL('../../../public/assets/app.css',import.meta.url),'utf8');
test('edge demo focus outlines stay inside clipped controls',async({page})=>{
 await page.route('**/*',r=>r.abort());
 await page.setContent(`<style>${websiteCSS}</style><div class="demo-app"><div class="mode-controls"><button>Guided</button></div><div class="role-switcher"><button class="role-button">Control desk</button></div></div>`);
 for(const width of [320,1280]){await page.setViewportSize({width,height:900});for(const selector of ['.mode-controls button','.role-button']){const e=page.locator(selector);await e.focus();expect(await e.evaluate(el=>{const s=getComputedStyle(el);return parseFloat(s.outlineOffset)+parseFloat(s.outlineWidth);})).toBeLessThanOrEqual(0);}}
 await page.emulateMedia({forcedColors:'active'});await page.locator('.role-button').focus();expect(await page.locator('.role-button').evaluate(e=>getComputedStyle(e).outlineStyle)).toBe('solid');
});
test('semantic card heading preserves previous text geometry',async({page})=>{
 await page.route('**/*',r=>r.abort());await page.setContent(`<style>${appCSS}</style><article class="card"><div class="card-title" id="old"><a href="#">Fictional candidate</a></div><h3 class="card-title" id="new"><a href="#">Fictional candidate</a></h3></article>`);
 const sizes=await page.locator('.card-title').evaluateAll(es=>es.map(e=>({height:e.getBoundingClientRect().height,font:getComputedStyle(e).font,line:getComputedStyle(e).lineHeight,margin:getComputedStyle(e).margin})));expect(sizes[0]).toEqual(sizes[1]);await expect(page.getByRole('heading',{level:3,name:'Fictional candidate'})).toBeVisible();
});
