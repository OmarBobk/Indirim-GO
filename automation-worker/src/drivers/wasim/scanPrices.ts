import type { Page } from 'playwright';
import type { PriceScanItemResult, PriceScanPayload } from '../../types.js';
import { RunLogger } from '../../logging/runLogger.js';
import type { ScanLogger } from '../../logging/scanLogger.js';
import { openWasimProductPage } from './login.js';
import { scanWasimProductPrice } from './scanProductPrice.js';

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

export async function scanWasimProductPrices(
  page: Page,
  payload: PriceScanPayload,
  logger: ScanLogger,
): Promise<PriceScanItemResult[]> {
  const results: PriceScanItemResult[] = [];
  const delayMs = Math.max(0, payload.delay_ms_between_products ?? 0);

  for (let index = 0; index < payload.items.length; index += 1) {
    const item = payload.items[index];

    if (index > 0 && delayMs > 0) {
      await sleep(delayMs);
    }

    logger.log('price_scan_item', `Scanning product ${item.product_id} (${item.product_api})`);

    const pageLogger = new RunLogger(payload.scan_uuid, 0);

    const minimalPayload = {
      product_api: item.product_api,
      credentials: payload.credentials,
      requirements: {},
      custom_amount: null,
    } as Parameters<typeof openWasimProductPage>[1];

    const productResult = await openWasimProductPage(
      page,
      minimalPayload,
      pageLogger,
      async () => undefined,
    );

    if (!productResult.ok) {
      results.push({
        product_id: item.product_id,
        ok: false,
        error_code: productResult.errorCode,
        message: productResult.message,
      });
      logger.log('price_scan_item', `Product ${item.product_id} failed: ${productResult.message}`, 'warn');

      continue;
    }

    const priceResult = await scanWasimProductPrice(
      page,
      item,
      payload.custom_reference_quantity,
    );

    if (!priceResult.ok) {
      results.push({
        product_id: item.product_id,
        ok: false,
        error_code: priceResult.errorCode,
        message: priceResult.message,
      });
      logger.log('price_scan_item', `Product ${item.product_id} price read failed: ${priceResult.message}`, 'warn');

      continue;
    }

    results.push({
      product_id: item.product_id,
      ok: true,
      scanned_price: priceResult.scannedPrice,
      displayed_raw: priceResult.displayedRaw,
    });
    logger.log(
      'price_scan_item',
      `Product ${item.product_id} scanned at ${priceResult.scannedPrice} (raw: ${priceResult.displayedRaw})`,
    );
  }

  return results;
}
