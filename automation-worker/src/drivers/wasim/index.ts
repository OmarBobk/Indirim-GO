import type { RunDriver } from '../types.js';
import { runCustomAmountProductSteps } from './customAmountForm.js';
import { openWasimProductPage } from './login.js';
import { assertUnitPriceCoversSupplierTotal } from './priceCheck.js';
import { fillPlayerId, isFixedQuantityMode } from './productForm.js';

export const wasimDriver: RunDriver = {
  supplierKey: 'wasim',

  async execute(ctx) {
    const { page, payload, logger } = ctx;

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
      logger.log('checkpoint', 'Player id filled on Wasim fixed product form; order submit not implemented yet');
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
          },
        };
      }

      customQuantity = customResult.quantity;
      lineTotal = customResult.lineTotal;
      supplierTotal = customResult.supplierTotal;
      playerId = customResult.playerId;
      logger.log('checkpoint', 'Custom amount, margin check, and player id filled; order submit not implemented yet');
    }

    return {
      outcome: 'needs_review',
      errorCode: 'flow_incomplete',
      message: playerId !== null
        ? 'Wasim product form filled (quantity/price/player). Next step: submit order.'
        : 'Opened Wasim product page. Next step: complete form and submit order.',
      deliveredPayload: {
        checkpoint: playerId !== null ? 'player_id_filled' : 'product',
        url: page.url(),
        product_api: productResult.productApi,
        product_url: productResult.productUrl,
        player_id: playerId,
        unit_price: unitPrice,
        line_total: lineTotal,
        custom_quantity: customQuantity,
        supplier_total: supplierTotal,
        product_amount_mode: payload.product_amount_mode ?? 'fixed',
      },
    };
  },
};
