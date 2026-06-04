import type { Page } from 'playwright';
import type { DriverResult, RunPayload } from '../../types.js';
import type { RunLogger } from '../../logging/runLogger.js';
import {
  isSupplierOrderCompleted,
  isSupplierOrderRejected,
  parseSwalPurchaseContent,
} from './parseSwalPurchase.js';

type ProductContext = {
  productApi: string | null;
  productUrl: string | null;
  playerId: string | null;
  unitPrice: number | null;
  lineTotal: number | null;
  customQuantity: number | null;
  supplierTotal: number | null;
  productAmountMode: string | null;
};

export async function submitWasimPurchase(
  page: Page,
  payload: RunPayload,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
  context: ProductContext,
): Promise<DriverResult> {
  const buyButton = page.locator('#product-request-buyid, a:has-text("إتمام الشراء")').first();

  try {
    await buyButton.waitFor({ state: 'visible', timeout: 15_000 });
  } catch {
    await screenshot('purchase_button_missing');

    return {
      outcome: 'failed',
      errorCode: 'purchase_button_missing',
      message: 'Wasim product page did not show the purchase button.',
      deliveredPayload: baseDeliveredPayload(context, page.url(), 'product'),
    };
  }

  logger.log('submit_purchase', 'Clicking Wasim purchase button');

  await buyButton.click();

  const swalContainer = page.locator(
    '.swal2-container.swal2-backdrop-show .swal2-popup.swal2-show',
  ).first();

  try {
    await swalContainer.waitFor({ state: 'visible', timeout: 30_000 });
  } catch {
    await screenshot('purchase_response_missing');

    return {
      outcome: 'failed',
      errorCode: 'purchase_response_missing',
      message: 'Wasim did not show a purchase confirmation dialog.',
      deliveredPayload: baseDeliveredPayload(context, page.url(), 'purchase'),
    };
  }

  await screenshot('purchase_response');

  const htmlContainer = swalContainer.locator('.swal2-html-container').first();
  const htmlContent = (await htmlContainer.innerHTML().catch(() => ''))
    || (await htmlContainer.innerText().catch(() => ''));

  const parsed = parseSwalPurchaseContent(htmlContent);

  logger.log(
    'purchase_parsed',
    `order=${parsed.supplierOrderId ?? 'n/a'} status=${parsed.supplierStatus ?? 'n/a'} price=${parsed.supplierEntryPrice ?? 'n/a'}`,
  );

  const confirmButton = swalContainer.locator('.swal2-confirm').first();

  if (await confirmButton.isVisible().catch(() => false)) {
    await confirmButton.click();
    await swalContainer.waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => undefined);
  }

  const deliveredBase = {
    ...baseDeliveredPayload(context, page.url(), 'purchase'),
    supplier_order_id: parsed.supplierOrderId,
    supplier_product_id: parsed.supplierProductId,
    supplier_entry_price: parsed.supplierEntryPrice,
    supplier_status: parsed.supplierStatus,
    supplier_reply: parsed.supplierReply,
  };

  if (isSupplierOrderCompleted(parsed.supplierStatus)) {
    if (parsed.supplierOrderId === null) {
      return {
        outcome: 'failed',
        errorCode: 'purchase_response_incomplete',
        message: 'Wasim reported success but no supplier order id was found.',
        deliveredPayload: deliveredBase,
      };
    }

    return {
      outcome: 'success',
      externalOrderId: parsed.supplierOrderId,
      message: 'Wasim order completed successfully.',
      deliveredPayload: {
        ...deliveredBase,
        checkpoint: 'purchase_completed',
      },
    };
  }

  if (isSupplierOrderRejected(parsed.supplierStatus, parsed.supplierReply)) {
    return {
      outcome: 'failed',
      errorCode: 'supplier_order_rejected',
      message: parsed.supplierReply ?? 'Wasim order rejected (pending).',
      deliveredPayload: {
        ...deliveredBase,
        checkpoint: 'purchase_rejected',
      },
    };
  }

  return {
    outcome: 'failed',
    errorCode: 'purchase_response_unexpected',
    message: `Unexpected Wasim order status: ${parsed.supplierStatus ?? 'unknown'}.`,
    deliveredPayload: deliveredBase,
  };
}

function baseDeliveredPayload(
  context: ProductContext,
  url: string,
  checkpoint: string,
): Record<string, unknown> {
  return {
    checkpoint,
    url,
    product_api: context.productApi,
    product_url: context.productUrl,
    player_id: context.playerId,
    unit_price: context.unitPrice,
    line_total: context.lineTotal,
    custom_quantity: context.customQuantity,
    supplier_total: context.supplierTotal,
    product_amount_mode: context.productAmountMode ?? 'fixed',
  };
}
