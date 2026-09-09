import type { Page } from 'playwright';

export const WASIM_ORDERS_VIEWPORT = {
  width: 1920,
  height: 1080,
} as const;

export type WasimOrdersTab = 'cancelled' | 'completed' | 'new';

/**
 * Live Wasim (2026-09-07): New tab renders #responsiveDataTable;
 * Completed/Cancelled render #responsiveDataTable2 with date filter + #btn-Transaction.
 */
export function wasimOrdersTableId(tab: WasimOrdersTab): 'responsiveDataTable' | 'responsiveDataTable2' {
  return tab === 'new' ? 'responsiveDataTable' : 'responsiveDataTable2';
}

export function startDateThreeYearsAgo(): string {
  const date = new Date();
  date.setFullYear(date.getFullYear() - 3);

  return date.toISOString().slice(0, 10);
}

export async function ensureWasimOrdersViewport(page: Page): Promise<void> {
  await page.setViewportSize(WASIM_ORDERS_VIEWPORT);
}

export async function setWasimStartDate(page: Page, dateValue: string): Promise<void> {
  const startDate = page.locator('#startDate').first();

  await startDate.scrollIntoViewIfNeeded().catch(() => undefined);

  const isVisible = await startDate.isVisible().catch(() => false);

  if (isVisible) {
    await startDate.fill(dateValue);

    return;
  }

  const applied = await page.evaluate((value) => {
    const input = document.querySelector('#startDate');

    if (!(input instanceof HTMLInputElement)) {
      return false;
    }

    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));

    return input.value === value;
  }, dateValue);

  if (!applied) {
    throw new Error('Wasim #startDate input was not found in the DOM.');
  }
}

export async function waitForWasimOrdersTable(page: Page, tab: WasimOrdersTab): Promise<void> {
  const tableId = wasimOrdersTableId(tab);
  const table = page.locator(`#${tableId}`).first();

  await table.waitFor({ state: 'visible', timeout: 30_000 });
  await page.locator(`#${tableId}_processing`).waitFor({ state: 'hidden', timeout: 30_000 }).catch(() => undefined);
}

/**
 * Refresh strategy by tab:
 * - New: table auto-loads after tab click; no #btn-Transaction (hidden).
 * - Completed/Cancelled: set date window then click #btn-Transaction.
 */
export async function reloadWasimOrdersTable(page: Page, tab: WasimOrdersTab = 'completed'): Promise<void> {
  if (tab === 'new') {
    await waitForWasimOrdersTable(page, 'new');

    return;
  }

  const reloadButton = page.locator('#btn-Transaction').first();

  await reloadButton.waitFor({ state: 'visible', timeout: 15_000 });
  await reloadButton.scrollIntoViewIfNeeded().catch(() => undefined);
  await reloadButton.click();

  await waitForWasimOrdersTable(page, tab);
}
