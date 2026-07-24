import type { RunDriver } from '../types.js';
import { runCustomAmountProductSteps } from './customAmountForm.js';
import { openWasimProductPage } from './login.js';
import { assertUnitPriceCoversSupplierTotal } from './priceCheck.js';
import { fillPlayerId, isFixedQuantityMode } from './productForm.js';
import { reconcileWasimOrder } from './reconcileOrder.js';
import { submitWasimPurchase } from './submitPurchase.js';

export const wasimDriver: RunDriver = {
  supplierKey: 'wasim',

  async execute(ctx) {
    const { page, payload, logger } = ctx;

    if (payload.automation_phase === 'reconcile') {
      return reconcileWasimOrder(page, payload, logger, ctx.screenshot);
    }

    const productResult = await openWasimProductPage(page, payload, logger, ctx.screenshot);

    if (!productResult.ok) {
      return {
        outcome: 'failed',
        errorCode: productResult.errorCode,
        message: productResult.message,
        deliveredPayload: {
          checkpoint: 'product',
          url: page.url(),
          product_api: payload.product_api ?? null,
        },
      };
    }

    let playerId: string | null = null;
    let unitPrice: number | null = null;
    let lineTotal: number | null = null;
    let supplierTotal: number | null = null;
    let customQuantity: number | null = null;

    if (isFixedQuantityMode(payload)) {
      const priceResult = await assertUnitPriceCoversSupplierTotal(
        page,
        payload,
        logger,
        ctx.screenshot,
      );

      if (!priceResult.ok) {
        return {
          outcome: 'failed',
          errorCode: priceResult.errorCode,
          message: priceResult.message,
          deliveredPayload: {
            checkpoint: 'price_check',
            url: page.url(),
            product_api: productResult.productApi,
            product_url: productResult.productUrl,
            ...(priceResult.supplierTotal !== undefined
              ? {
                supplier_total: priceResult.supplierTotal,
                supplier_total_raw: priceResult.supplierTotalRaw ?? null,
              }
              : {}),
          },
        };
      }

      unitPrice = priceResult.unitPrice;
      supplierTotal = priceResult.supplierTotal;

      const fillResult = await fillPlayerId(page, payload, logger, ctx.screenshot);

      if (!fillResult.ok) {
        return {
          outcome: 'failed',
          errorCode: fillResult.errorCode,
          message: fillResult.message,
          deliveredPayload: {
            checkpoint: 'product',
            url: page.url(),
            product_api: productResult.productApi,
            product_url: productResult.productUrl,
          },
        };
      }

      playerId = fillResult.playerId;
    } else {
      const customResult = await runCustomAmountProductSteps(page, payload, logger, ctx.screenshot);

      if (!customResult.ok) {
        return {
          outcome: 'failed',
          errorCode: customResult.errorCode,
          message: customResult.message,
          deliveredPayload: {
            checkpoint: customResult.errorCode === 'margin_insufficient'
              || customResult.errorCode.startsWith('payload_missing')
              || customResult.errorCode.startsWith('supplier_total')
              ? 'price_check'
              : 'product',
            url: page.url(),
            product_api: productResult.productApi,
            product_url: productResult.productUrl,
            ...(customResult.supplierTotal !== undefined
              ? {
                supplier_total: customResult.supplierTotal,
                supplier_total_raw: customResult.supplierTotalRaw ?? null,
                custom_quantity: customResult.customQuantity ?? null,
              }
              : {}),
          },
        };
      }

      customQuantity = customResult.quantity;
      lineTotal = customResult.lineTotal;
      supplierTotal = customResult.supplierTotal;
      playerId = customResult.playerId;
    }

    return submitWasimPurchase(page, payload, logger, ctx.screenshot, {
      productApi: productResult.productApi,
      productUrl: productResult.productUrl,
      playerId,
      unitPrice,
      lineTotal,
      customQuantity,
      supplierTotal,
      productAmountMode: payload.product_amount_mode ?? 'fixed',
    });
  },
};
