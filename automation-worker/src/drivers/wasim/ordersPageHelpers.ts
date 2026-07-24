import type { Page } from 'playwright';

export const WASIM_ORDERS_VIEWPORT = {
  width: 1920,
  height: 1080,
} as const;

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

export async function reloadWasimOrdersTable(page: Page): Promise<void> {
  const reloadButton = page.locator('#btn-Transaction').first();

  await reloadButton.scrollIntoViewIfNeeded().catch(() => undefined);
  await reloadButton.click();

  await page.locator('#responsiveDataTable2').waitFor({ state: 'visible', timeout: 30_000 });

  await page.locator('#responsiveDataTable2_processing').waitFor({ state: 'hidden', timeout: 30_000 }).catch(() => undefined);
}
