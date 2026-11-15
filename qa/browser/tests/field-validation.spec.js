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
  await email.fill('still-typing');
  await expect(email).toHaveAttribute('aria-invalid', 'false');
  await email.fill('synthetic@example.test');
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

test('required multi-select feedback follows submit/change and resets without values in messages', async ({page}) => {
 await page.setContent(`<form data-field-validation><label for="choices">Company options</label><select id="choices" multiple required data-min-selections="2" data-error="choices-error"><option value="fictional-a">Fictional A</option><option value="fictional-b">Fictional B</option></select><p id="choices-error" hidden>Error: Select at least two company options.</p><button>Create request</button></form>`);
 await page.addScriptTag({content:source});const choices=page.locator('#choices');
 await expect(choices).toHaveAttribute('aria-invalid','false');await choices.focus();await page.locator('button').focus();await expect(page.locator('#choices-error')).toBeHidden();
 await page.locator('form').evaluate(f=>f.requestSubmit());await expect(choices).toBeFocused();await expect(choices).toHaveAttribute('aria-describedby','choices-error');
 await choices.selectOption(['fictional-a']);await expect(choices).toHaveAttribute('aria-invalid','true');
 await choices.selectOption(['fictional-a','fictional-b']);await expect(choices).toHaveAttribute('aria-invalid','false');await expect(page.locator('#choices-error')).toBeHidden();
 await choices.selectOption([]);await expect(choices).toHaveAttribute('aria-invalid','true');await expect(page.locator('[aria-live]')).toHaveText('Company options: Error: Select at least two company options.');
 await page.locator('form').evaluate(f=>f.reset());await expect(choices).toHaveAttribute('aria-invalid','false');await expect(page.locator('[aria-live]')).toBeEmpty();
});

test('empty candidate source and script-free multi-select retain native validation', async ({page}) => {
 await page.setContent(`<form data-field-validation><label for="candidate">Candidate</label><select id="candidate" required data-error="candidate-error"></select><p id="candidate-error" hidden>Error: Select an available candidate.</p><label for="companies">Companies</label><select id="companies" required multiple data-min-selections="2" data-error="companies-error"><option value="a">A</option><option value="b">B</option></select><p id="companies-error" hidden>Error: Select two companies.</p><button>Create</button></form>`);
 await page.locator('form').evaluate(f=>f.requestSubmit());await expect(page.locator('#candidate')).toBeFocused();
 expect(await page.locator('#companies').evaluate(e=>e.validity.valueMissing)).toBe(true);
 await page.addScriptTag({content:source});await page.locator('form').evaluate(f=>f.requestSubmit());await expect(page.locator('#candidate')).toBeFocused();await expect(page.locator('#candidate')).toHaveAttribute('aria-describedby','candidate-error');await expect(page.locator('#companies')).toHaveAttribute('aria-invalid','true');
});

test('CSV textarea preserves native submitter actions and never announces pasted data', async ({page}) => {
 await page.setContent(`<form data-field-validation><label for="csv">CSV (required)</label><textarea id="csv" name="csv" required data-error="csv-error"></textarea><p id="csv-error" hidden>Error: Paste CSV data before previewing or importing.</p><button name="action" value="preview">Preview CSV</button><button name="action" value="import">Import CSV</button></form>`);
 await page.addScriptTag({content:source});await page.evaluate(()=>{window.submittedActions=[];document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.submittedActions.push(e.submitter.value);});});
 await page.getByRole('button',{name:'Preview CSV'}).click();await expect(page.locator('#csv')).toBeFocused();await expect(page.locator('#csv')).toHaveAttribute('aria-describedby','csv-error');
 await page.locator('#csv').fill('fictional_id,name\nSYN1,Synthetic Candidate');await expect(page.locator('#csv')).toHaveAttribute('aria-invalid','false');
 await page.getByRole('button',{name:'Preview CSV'}).click();await page.getByRole('button',{name:'Import CSV',exact:true}).click();expect(await page.evaluate(()=>window.submittedActions)).toEqual(['preview','import']);
 await expect(page.locator('[aria-live]')).not.toContainText('Synthetic Candidate');
 await page.locator('#csv').fill('');await page.getByRole('button',{name:'Preview CSV'}).focus();await expect(page.locator('[aria-live]')).toHaveText('CSV (required): Error: Paste CSV data before previewing or importing.');
});

test('wanted table overflow remains keyboard reachable at narrow enlarged text', async ({page}) => {
 const css=readFileSync(new URL('../../../public/assets/app.css',import.meta.url),'utf8');
 await page.setViewportSize({width:320,height:900});await page.setContent(`<style>${css}</style><section class="panel"><h2 id="alerts">Alerts</h2><div class="table-scroll" role="region" aria-labelledby="alerts" tabindex="0"><table class="table"><thead><tr>${['Candidate','Status','Reason','Created','Action'].map(x=>`<th scope="col">${x}</th>`).join('')}</tr></thead><tbody><tr><td>SYN1</td><td>Open</td><td>Fictional callback</td><td>2026-01-01</td><td>None</td></tr></tbody></table></div></section>`);await page.evaluate(()=>document.documentElement.style.fontSize='200%');
 const region=page.getByRole('region',{name:'Alerts'});await region.focus();await expect(region).toBeFocused();await region.press('ArrowRight');await expect.poll(()=>region.evaluate(e=>e.scrollLeft)).toBeGreaterThan(0);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
});

test('generic server error remains associated through local correction without credential blame', async ({page}) => {
 await page.setContent(`<form data-field-validation aria-describedby="login-server-errors"><div id="login-server-errors"><p class="flash error">Invalid login.</p></div><label for="email">Email</label><input id="email" type="email" required autofocus aria-describedby="login-server-errors" data-error="email-error"><p id="email-error" hidden>Error: Enter a valid email address.</p><label for="password">Password</label><input id="password" type="password" required data-error="password-error"><p id="password-error" hidden>Error: Enter your password.</p><button>Sign in</button></form>`);
 await page.addScriptTag({content:source});await expect(page.locator('#email')).toBeFocused();await expect(page.locator('#email')).toHaveAttribute('aria-invalid','false');await expect(page.locator('#password')).toHaveAttribute('aria-invalid','false');
 await expect(page.locator('#email')).toHaveAccessibleDescription('Invalid login.');
 await page.locator('#email').fill('bad');await page.locator('#password').focus();await expect(page.locator('#email')).toHaveAttribute('aria-describedby','login-server-errors email-error');
 await page.locator('#email').fill('fictional@example.test');await expect(page.locator('#email')).toHaveAttribute('aria-describedby','login-server-errors');await expect(page.locator('#login-server-errors')).toHaveText('Invalid login.');
});
