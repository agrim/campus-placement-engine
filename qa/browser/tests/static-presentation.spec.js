import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const css=readFileSync(new URL('../../../public/assets/app.css',import.meta.url),'utf8');
const cases=[
 ['<section class="panel" style="max-width:420px">Sign in</section>','<section class="panel login-panel">Sign in</section>'],
 ['<section class="panel" style="max-width:720px">Status</section>','<section class="panel student-panel">Status</section>'],
 ['<input type="checkbox" style="width:auto">','<input type="checkbox" class="checkbox-natural">'],
 ...[['#6b3b00','accommodation-note'],['#9f1d1d','urgent-note'],['#7a4c00','attention-note']].map(([color,cls])=>[`<div class="card-meta" style="color:${color};font-weight:700">Status note</div>`,`<div class="card-meta ${cls}">Status note</div>`])
];
test('static CSS classes preserve old inline presentation across size and contrast modes',async({page})=>{
 await page.route('**/*',r=>r.abort());
 for(const width of [320,1280])for(const scale of ['100%','200%'])for(const forcedColors of ['none','active']){
 await page.setViewportSize({width,height:900});await page.emulateMedia({forcedColors});
 for(const [before,after] of cases){await page.setContent(`<style>${css} html{font-size:${scale}}</style><main><div id="before">${before}</div><div id="after">${after}</div></main>`);
 const values=await page.locator('#before > *,#after > *').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e);return{width:r.width,height:r.height,color:s.color,fontWeight:s.fontWeight,border:s.borderLeft,maxWidth:s.maxWidth};}));expect(values[0]).toEqual(values[1]);}
 }
});
