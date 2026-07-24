import { WORKER_BUILD } from '../build.js';
import { withBrowserContext } from '../browser/pool.js';
import { postPriceScanResult } from '../callbacks/postPriceScanResult.js';
import { scanWasimProductPrices } from '../drivers/wasim/scanPrices.js';
import { ScanLogger } from '../logging/scanLogger.js';
import type { PriceScanPayload } from '../types.js';

const callbackSecret = process.env.FULFILLMENT_AUTOMATION_CALLBACK_SECRET ?? '';

export async function executePriceScan(payload: PriceScanPayload): Promise<void> {
  const logger = new ScanLogger(payload.scan_uuid);

  if (payload.driver !== 'wasim') {
    await postPriceScanResult(
      payload.callback_url,
      callbackSecret,
      {
        status: 'failed',
        items: [],
        error_code: 'unknown_driver',
        message: `Price scan driver ${payload.driver} is not supported.`,
      },
      logger.excerpt(),
    );

    return;
  }

  logger.log('price_scan', `build=${WORKER_BUILD} items=${payload.items.length}`);

  try {
    const items = await withBrowserContext(
      payload.session_key,
      payload.credentials,
      async (context) => {
        const page = await context.newPage();

        return scanWasimProductPrices(page, payload, logger);
      },
    );

    await postPriceScanResult(
      payload.callback_url,
      callbackSecret,
      {
        status: 'completed',
        items,
      },
      logger.excerpt(),
    );
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Price scan batch failed';

    logger.log('price_scan', message, 'error');

    await postPriceScanResult(
      payload.callback_url,
      callbackSecret,
      {
        status: 'failed',
        items: [],
        error_code: 'scan_batch_failed',
        message,
      },
      logger.excerpt(),
    );
  }
}
