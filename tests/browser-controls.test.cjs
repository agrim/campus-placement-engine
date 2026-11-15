const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { runInNewContext } = require('node:vm');
const source = readFileSync(join(__dirname, '../public/assets/app.js'), 'utf8');

function fixture({ seconds = 12, enabled = true, confirmed = true } = {}) {
  const documentListeners = {};
  const clickListeners = {};
  const attributes = {};
  const button = { textContent: '', setAttribute: (name, value) => attributes[name] = value, addEventListener: (name, fn) => clickListeners[name] = fn };
  const countdown = { textContent: '' };
  const announcement = { textContent: '' };
  const nodes = { '[data-board-refresh-toggle]': button, '[data-board-refresh-countdown]': countdown, '[data-board-refresh-announcement]': announcement };
  const control = { getAttribute: () => String(seconds), querySelector: selector => nodes[selector] };
  const document = { hidden: false, addEventListener: (name, fn) => documentListeners[name] = fn, querySelector: () => enabled ? control : null };
  let tick; let reloads = 0;
  runInNewContext(source, { document, window: { confirm: () => confirmed, location: { reload: () => reloads++ }, setInterval: fn => { tick = fn; } } });
  return { document, button, countdown, announcement, attributes, click: () => clickListeners.click(), tick: () => tick?.(), submit: event => documentListeners.submit(event), reloads: () => reloads, hasTimer: () => Boolean(tick) };
}

test('automatic refresh pauses while hidden and supports explicit pause/resume', () => {
  const ui = fixture();
  ui.tick(); assert.match(ui.countdown.textContent, /11 seconds/);
  ui.document.hidden = true; ui.tick(); assert.match(ui.countdown.textContent, /11 seconds/);
  ui.document.hidden = false; ui.tick(); assert.match(ui.announcement.textContent, /10 seconds/);
  ui.click(); assert.equal(ui.attributes['aria-pressed'], 'true');
  const paused = ui.countdown.textContent; ui.tick(); assert.equal(ui.countdown.textContent, paused);
  assert.equal(ui.reloads(), 0);
  ui.click(); assert.equal(ui.attributes['aria-pressed'], 'false'); assert.match(ui.countdown.textContent, /12 seconds/);
  for (let i = 0; i < 12; i++) ui.tick();
  assert.equal(ui.reloads(), 1);
});

test('ordinary pages and malformed refresh intervals do not schedule reloads', () => {
  for (const options of [{ enabled: false }, { seconds: 0 }, { seconds: 'invalid' }]) {
    const ui = fixture(options); assert.equal(ui.hasTimer(), false); assert.equal(ui.reloads(), 0);
  }
});

test('confirmation cancellation blocks submission without disabling native forms', () => {
  for (const confirmed of [false, true]) {
    const ui = fixture({ enabled: false, confirmed }); let prevented = false;
    ui.submit({ target: { matches: () => true, getAttribute: () => 'Confirm synthetic action?' }, preventDefault: () => prevented = true });
    assert.equal(prevented, !confirmed);
    prevented = false;
    ui.submit({ target: { matches: () => false }, preventDefault: () => prevented = true });
    assert.equal(prevented, false);
  }
});
