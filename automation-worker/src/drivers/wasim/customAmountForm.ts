import type { Page } from 'playwright';
import type { RunPayload } from '../../types.js';
import type { RunLogger } from '../../logging/runLogger.js';
import { assertLineTotalCoversSupplierTotal, type PriceCheckFailure } from './priceCheck.js';
import { fillPlayerId } from './productForm.js';

export function resolveCustomAmount(payload: RunPayload): number | null {
  const amount = payload.custom_amount?.amount;

  if (typeof amount === 'number' && Number.isFinite(amount) && amount > 0) {
    return amount;
  }

  return null;
}

export async function fillCustomAmountQuantity(
  page: Page,
  payload: RunPayload,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
): Promise<{ ok: true; quantity: number } | { ok: false; errorCode: string; message: string }> {
  const quantity = resolveCustomAmount(payload);

  if (quantity === null) {
    return {
      ok: false,
      errorCode: 'payload_missing_custom_amount',
      message: 'Custom-amount fulfillment is missing custom_amount.amount in worker payload.',
    };
  }

  const quantityField = page.locator(
    '#product-request-quantity, input[name="quantity"], input[placeholder="الكمية"]',
  ).first();

  try {
    await quantityField.waitFor({ state: 'visible', timeout: 15_000 });
  } catch {
    await screenshot('quantity_field_missing');

    return {
      ok: false,
      errorCode: 'quantity_field_missing',
      message: 'Wasim product page did not show the quantity input field.',
    };
  }

  const quantityText = Number.isInteger(quantity) ? String(quantity) : String(quantity);

  logger.log('fill_quantity', `Entering custom amount quantity ${quantityText}`);

  await quantityField.fill(quantityText);
  await quantityField.blur();

  await waitForSupplierTotalRecalculation(page);

  await screenshot('quantity_filled');

  return {
    ok: true,
    quantity,
  };
}

async function waitForSupplierTotalRecalculation(page: Page): Promise<void> {
  const totalPriceField = page.locator(
    '#product-request-TotalPrice, input[name="TotalPrice"], input[placeholder="الاجمالي"]',
  ).first();

  await totalPriceField.waitFor({ state: 'visible', timeout: 15_000 }).catch(() => undefined);

  await page.waitForTimeout(500);

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
    await page.waitForTimeout(1500);
  }
}

export async function runCustomAmountProductSteps(
  page: Page,
  payload: RunPayload,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
): Promise<
  | {
    ok: true;
    quantity: number;
    lineTotal: number;
    supplierTotal: number;
    playerId: string;
  }
  | PriceCheckFailure
> {
  const quantityResult = await fillCustomAmountQuantity(page, payload, logger, screenshot);

  if (!quantityResult.ok) {
    return quantityResult;
  }

  const priceResult = await assertLineTotalCoversSupplierTotal(page, payload, logger, screenshot);

  if (!priceResult.ok) {
    return {
      ...priceResult,
      customQuantity: quantityResult.quantity,
    };
  }

  const playerResult = await fillPlayerId(page, payload, logger, screenshot);

  if (!playerResult.ok) {
    return playerResult;
  }

  return {
    ok: true,
    quantity: quantityResult.quantity,
    lineTotal: priceResult.lineTotal,
    supplierTotal: priceResult.supplierTotal,
    playerId: playerResult.playerId,
  };
}
