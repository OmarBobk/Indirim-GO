import type { Page } from 'playwright';
import type { RunPayload } from '../../types.js';
import type { RunLogger } from '../../logging/runLogger.js';
import type { ProgressReporter } from '../../progress/ProgressReporter.js';
import { parseMoneyString } from '../../utils/parseMoney.js';

export type PriceCheckFailure = {
  ok: false;
  errorCode: string;
  message: string;
  supplierTotal?: number;
  supplierTotalRaw?: string;
  customQuantity?: number;
};

export type PriceCheckSuccess = {
  ok: true;
  orderAmount: number;
  supplierTotal: number;
};

export function resolveUnitPrice(payload: RunPayload): number | null {
  const value = payload.unit_price;

  if (value === null || value === undefined) {
    return null;
  }

  return Number.isFinite(value) ? value : null;
}

export function resolveLineTotal(payload: RunPayload): number | null {
  const value = payload.line_total;

  if (value === null || value === undefined) {
    return null;
  }

  return Number.isFinite(value) ? value : null;
}

export async function readSupplierTotalFromPage(
  page: Page,
  screenshot: (label: string) => Promise<void>,
  progress?: ProgressReporter,
): Promise<
  | { ok: true; supplierTotal: number; displayedRaw: string }
  | { ok: false; errorCode: string; message: string }
> {
  progress?.step('reading_supplier_price');

  const totalPriceField = page.locator(
    '#product-request-TotalPrice, input[name="TotalPrice"], input[placeholder="الاجمالي"]',
  ).first();

  try {
    await totalPriceField.waitFor({ state: 'visible', timeout: 15_000 });
  } catch {
    await screenshot('supplier_total_field_missing');

    return {
      ok: false,
      errorCode: 'supplier_total_field_missing',
      message: 'Wasim product page did not show the supplier total price field.',
    };
  }

  const displayedRaw = (await totalPriceField.inputValue()).trim()
    || (await totalPriceField.getAttribute('value'))?.trim()
    || '';

  const supplierTotal = parseMoneyString(displayedRaw);

  if (supplierTotal === null) {
    await screenshot('supplier_total_unparseable');

    return {
      ok: false,
      errorCode: 'supplier_total_unparseable',
      message: `Could not parse supplier total price from Wasim field: "${displayedRaw}".`,
    };
  }

  progress?.step('supplier_price_read');

  return {
    ok: true,
    supplierTotal,
    displayedRaw,
  };
}

async function assertOrderAmountCoversSupplierTotal(
  page: Page,
  orderAmount: number | null,
  amountLabel: string,
  missingErrorCode: string,
  missingMessage: string,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
  progress?: ProgressReporter,
): Promise<PriceCheckSuccess | PriceCheckFailure> {
  if (orderAmount === null) {
    return {
      ok: false,
      errorCode: missingErrorCode,
      message: missingMessage,
    };
  }

  const supplierResult = await readSupplierTotalFromPage(page, screenshot, progress);

  if (!supplierResult.ok) {
    return supplierResult;
  }

  const { supplierTotal } = supplierResult;

  logger.log(
    'price_check',
    `Comparing ${amountLabel} ${orderAmount} with Wasim total ${supplierTotal}`,
  );

  progress?.step('validating_supplier_price');

  if (orderAmount <= supplierTotal) {
    await screenshot('margin_insufficient');

    return {
      ok: false,
      errorCode: 'margin_insufficient',
      message: `Order ${amountLabel} (${orderAmount}) must be greater than Wasim total (${supplierTotal}).`,
      supplierTotal,
      supplierTotalRaw: supplierResult.displayedRaw,
    };
  }

  await screenshot('price_check_ok');
  progress?.step('supplier_price_validated');

  return {
    ok: true,
    orderAmount,
    supplierTotal,
  };
}

export async function assertUnitPriceCoversSupplierTotal(
  page: Page,
  payload: RunPayload,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
  progress?: ProgressReporter,
): Promise<
  | { ok: true; unitPrice: number; supplierTotal: number }
  | PriceCheckFailure
> {
  const result = await assertOrderAmountCoversSupplierTotal(
    page,
    resolveUnitPrice(payload),
    'unit price',
    'payload_missing_unit_price',
    'Worker payload is missing order item unit_price for margin check.',
    logger,
    screenshot,
    progress,
  );

  if (!result.ok) {
    return result;
  }

  return {
    ok: true,
    unitPrice: result.orderAmount,
    supplierTotal: result.supplierTotal,
  };
}

export async function assertLineTotalCoversSupplierTotal(
  page: Page,
  payload: RunPayload,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
  progress?: ProgressReporter,
): Promise<
  | { ok: true; lineTotal: number; supplierTotal: number }
  | PriceCheckFailure
> {
  const result = await assertOrderAmountCoversSupplierTotal(
    page,
    resolveLineTotal(payload),
    'line total',
    'payload_missing_line_total',
    'Worker payload is missing order item line_total for margin check.',
    logger,
    screenshot,
    progress,
  );

  if (!result.ok) {
    return result;
  }

  return {
    ok: true,
    lineTotal: result.orderAmount,
    supplierTotal: result.supplierTotal,
  };
}
