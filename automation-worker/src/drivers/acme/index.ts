import type { RunDriver } from '../types.js';

/**
 * Placeholder supplier driver for MVP wiring.
 * Replace DOM steps with real supplier portal automation.
 */
export const acmeDriver: RunDriver = {
  supplierKey: 'acme',

  async execute(ctx) {
    const { page, payload, logger } = ctx;

    logger.log('navigate', 'Opening supplier portal placeholder');

    await page.goto('about:blank');
    await ctx.screenshot('start');

    const externalOrderId = `ACME-${payload.fulfillment_id}-${Date.now()}`;

    logger.log('place_order', 'Simulated order placement', 'info');

    await ctx.screenshot('confirmation');

    return {
      outcome: 'success',
      externalOrderId,
      deliveredPayload: {
        code: externalOrderId,
        idempotency_reference: payload.idempotency_reference,
        requirements: payload.requirements,
        simulated: true,
      },
    };
  },
};
