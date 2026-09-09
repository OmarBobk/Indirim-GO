/**
 * One-off local evidence capture for C1.2.1 — private only.
 * Does not print credentials or row cell text.
 */
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { sessionStatePath, hasSessionState } from '../src/browser/sessionStore.js';
import { WASIM_ORDERS_URL } from '../src/drivers/wasim/urls.js';

const sessionKey = 'wasim-main';
const outDir = path.resolve('storage', 'private-evidence', 'c1.2.1-orders');
fs.mkdirSync(outDir, { recursive: true });

if (!hasSessionState(sessionKey)) {
  console.error(JSON.stringify({ ok: false, error: 'no_session_state' }));
  process.exit(1);
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ storageState: sessionStatePath(sessionKey) });
const page = await context.newPage();

await page.setViewportSize({ width: 1920, height: 1080 });
await page.goto(WASIM_ORDERS_URL, { waitUntil: 'domcontentloaded', timeout: 60_000 });
await page.waitForTimeout(2500);

const url = page.url();
const screenshotPath = path.join(outDir, 'orders-page.png');
await page.screenshot({ path: screenshotPath, fullPage: false });

const inventory = await page.evaluate(`(() => {
  const pick = (el) => {
    return {
      tag: el.tagName.toLowerCase(),
      id: el.id || null,
      name: el.getAttribute('name'),
      type: el.getAttribute('type'),
      role: el.getAttribute('role'),
      ariaControls: el.getAttribute('aria-controls'),
      ariaLabel: el.getAttribute('aria-label'),
      classTokenSample: (el.getAttribute('class') || '')
        .split(/\\s+/)
        .filter(Boolean)
        .slice(0, 6),
      visible: !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length),
      textSample: (el.innerText || '').replace(/\\s+/g, ' ').trim().slice(0, 40) || null,
    };
  };

  const bySelector = (selector) =>
    Array.from(document.querySelectorAll(selector)).slice(0, 30).map(pick);

  const known = {
    btnNew: !!document.querySelector('#btn-new'),
    btnCompleted: !!document.querySelector('#btn-Completed'),
    btnCancelled: !!document.querySelector('#btn-Cancelled'),
    responsiveDataTable2: !!document.querySelector('#responsiveDataTable2'),
    btnTransaction: !!document.querySelector('#btn-Transaction'),
    startDate: !!document.querySelector('#startDate'),
  };

  return {
    known,
    tables: bySelector('table'),
    wrappers: bySelector('.dataTables_wrapper, .table-responsive, [id*="DataTable" i], [id*="Order" i]'),
    buttons: bySelector('button, a.btn, input[type="button"], input[type="submit"], a[id^="btn-"]'),
    inputs: bySelector('input, select, textarea'),
  };
})()`);

const diagnostic = {
  captured_at: new Date().toISOString(),
  url_path: (() => {
    try {
      const u = new URL(url);
      return { host: u.hostname, pathname: u.pathname };
    } catch {
      return { host: null, pathname: null };
    }
  })(),
  screenshot: 'orders-page.png',
  inventory,
};

fs.writeFileSync(path.join(outDir, 'orders-diagnostic.json'), JSON.stringify(diagnostic, null, 2));

console.log(JSON.stringify({
  ok: true,
  outDir,
  url_path: diagnostic.url_path,
  known: inventory.known,
  table_ids: inventory.tables.map((t) => t.id).filter(Boolean),
  wrapper_ids: inventory.wrappers.map((t) => t.id).filter(Boolean),
  button_ids: inventory.buttons.map((b) => b.id).filter(Boolean),
  input_ids: inventory.inputs.map((i) => i.id).filter(Boolean),
  input_aria_controls: inventory.inputs.map((i) => i.ariaControls).filter(Boolean),
  visible_tables: inventory.tables.filter((t) => t.visible).map((t) => ({ id: t.id, classTokenSample: t.classTokenSample })),
  visible_searchish: inventory.inputs.filter((i) => i.visible && (
    (i.type === 'search')
    || (i.ariaControls && String(i.ariaControls).length > 0)
    || (i.id && /search|filter/i.test(i.id))
    || (i.name && /search|filter/i.test(i.name))
  )).map((i) => ({ id: i.id, name: i.name, ariaControls: i.ariaControls, type: i.type })),
}, null, 2));

await context.close();
await browser.close();
