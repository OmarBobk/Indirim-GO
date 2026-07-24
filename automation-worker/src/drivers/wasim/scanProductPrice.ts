import type { Page } from 'playwright';
import { readSupplierTotalFromPage } from './priceCheck.js';
import type { PriceScanItem } from '../../types.js';

const noopScreenshot = async (): Promise<void> => undefined;

function isCustomAmountMode(amountMode: string): boolean {
  return amountMode.trim().toLowerCase() === 'custom';
}

async function waitForSupplierTotalRecalculation(page: Page): Promise<void> {
  const totalPriceField = page.locator(
    '#product-request-TotalPrice, input[name="TotalPrice"], input[placeholder="الاجمالي"]',
  ).first();

  await totalPriceField.waitFor({ state: 'visible', timeout: 15_000 }).catch(() => undefined);
  await page.waitForTimeout(400);

  try {
    await page.waitForFunction(() => {
      const input = document.querySelector<HTMLInputElement>(
        '#product-request-TotalPrice, input[name="TotalPrice"]',
      );

      if (!input) {
        return false;
      }

      const value = (input.value || input.getAttribute('value') || '').trim();

      return value !== '' && value !== '0' && value !== '0.0' && value !== '0,0';
    }, { timeout: 10_000 });
  } catch {
    await page.waitForTimeout(1000);
  }
}

async function fillReferenceQuantity(page: Page, quantity: number): Promise<
  | { ok: true }
  | { ok: false; errorCode: string; message: string }
> {
  const quantityField = page.locator(
    '#product-request-quantity, input[name="quantity"], input[placeholder="الكمية"]',
  ).first();

  try {
    await quantityField.waitFor({ state: 'visible', timeout: 15_000 });
  } catch {
    return {
      ok: false,
      errorCode: 'quantity_field_missing',
      message: 'Wasim product page did not show the quantity input field.',
    };
  }

  const quantityText = Number.isInteger(quantity) ? String(quantity) : String(quantity);
  await quantityField.fill(quantityText);
  await quantityField.blur();
  await waitForSupplierTotalRecalculation(page);

  return { ok: true };
}

export async function scanWasimProductPrice(
  page: Page,
  item: PriceScanItem,
  defaultReferenceQuantity: number,
): Promise<
  | { ok: true; scannedPrice: number; displayedRaw: string }
  | { ok: false; errorCode: string; message: string }
> {
  if (isCustomAmountMode(item.amount_mode)) {
    const quantity = item.reference_quantity ?? defaultReferenceQuantity;

    if (!Number.isFinite(quantity) || quantity <= 0) {
      return {
        ok: false,
        errorCode: 'invalid_reference_quantity',
        message: 'Custom amount scan requires a positive reference quantity.',
      };
    }

    const quantityResult = await fillReferenceQuantity(page, quantity);

    if (!quantityResult.ok) {
      return quantityResult;
    }
  }

  const supplierResult = await readSupplierTotalFromPage(page, noopScreenshot);

  if (!supplierResult.ok) {
    return supplierResult;
  }

  if (isCustomAmountMode(item.amount_mode)) {
    const quantity = item.reference_quantity ?? defaultReferenceQuantity;
    const perUnit = supplierResult.supplierTotal / quantity;

    return {
      ok: true,
      scannedPrice: perUnit,
      displayedRaw: `${supplierResult.displayedRaw}@${quantity}`,
    };
  }

  return {
    ok: true,
    scannedPrice: supplierResult.supplierTotal,
    displayedRaw: supplierResult.displayedRaw,
  };
}
