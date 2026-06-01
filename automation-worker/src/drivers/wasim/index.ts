import type { RunDriver } from '../types.js';
import { openWasimProductPage } from './login.js';

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

    logger.log('checkpoint', 'Wasim product page loaded; order flow not implemented yet');

    return {
      outcome: 'needs_review',
      errorCode: 'flow_incomplete',
      message: 'Opened Wasim product page. Next step: place order.',
      deliveredPayload: {
        checkpoint: 'product',
        url: productResult.url,
        product_api: productResult.productApi,
        product_url: productResult.productUrl,
      },
    };
  },
};
