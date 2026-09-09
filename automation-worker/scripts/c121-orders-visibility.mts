/**
 * Follow-up visibility probe for C1.2.1 — private only.
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
await page.waitForTimeout(2000);

const snapshotFn = `async (labelValue) => {
  const info = (selector) => {
    const el = document.querySelector(selector);
    if (!el) return { exists: false };
    const style = window.getComputedStyle(el);
    const rect = el.getBoundingClientRect();
    return {
      exists: true,
      id: el.id || null,
      display: style.display,
      visibility: style.visibility,
      opacity: style.opacity,
      offsetParent: el.offsetParent !== null,
      rect: { w: Math.round(rect.width), h: Math.round(rect.height) },
      ariaControls: el.getAttribute('aria-controls'),
      classTokenSample: (el.getAttribute('class') || '').split(/\\s+/).filter(Boolean).slice(0, 8),
    };
  };
  return {
    label: labelValue,
    path: location.pathname,
    responsiveDataTable: info('#responsiveDataTable'),
    responsiveDataTable2: info('#responsiveDataTable2'),
    btnTransaction: info('#btn-Transaction'),
    btnNew: info('#btn-new'),
    btnCompleted: info('#btn-Completed'),
    btnCancelled: info('#btn-Cancelled'),
    searchButton: info('#searchButton'),
    searchFilter: info('#search-Filter'),
    startDate: info('#startDate'),
    dtFilterSearch: info('.dataTables_filter input[type="search"]'),
    ariaSearchForMain: info('input[aria-controls="responsiveDataTable"][type="search"]'),
    ariaSearchFor2: info('input[aria-controls="responsiveDataTable2"][type="search"]'),
  };
}`;

async function snapshot(label: string) {
  return page.evaluate(`(${snapshotFn})(${JSON.stringify(label)})`);
}

const before = await snapshot('initial');

await page.locator('#btn-new').click().catch(() => undefined);
await page.waitForTimeout(1500);
const afterNew = await snapshot('after_btn_new');

await page.locator('#btn-Completed').click().catch(() => undefined);
await page.waitForTimeout(1500);
const afterCompleted = await snapshot('after_btn_completed');

await page.locator('#btn-Cancelled').click().catch(() => undefined);
await page.waitForTimeout(1500);
const afterCancelled = await snapshot('after_btn_cancelled');

const result = { before, afterNew, afterCompleted, afterCancelled };
fs.writeFileSync(path.join(outDir, 'orders-visibility.json'), JSON.stringify(result, null, 2));
console.log(JSON.stringify(result, null, 2));

await context.close();
await browser.close();
