import fs from 'node:fs';
import path from 'node:path';
import { withBrowserContext } from '../browser/pool.js';
import { postResult } from '../callbacks/postResult.js';
import { resolveDriver } from '../drivers/index.js';
import { RunLogger } from '../logging/runLogger.js';
import type { RunPayload } from '../types.js';

const callbackSecret = process.env.FULFILLMENT_AUTOMATION_CALLBACK_SECRET ?? '';

export async function executeRun(payload: RunPayload): Promise<void> {
  const logger = new RunLogger(payload.run_uuid, payload.fulfillment_id);
  const driver = resolveDriver(payload.driver);

  if (driver === null) {
    await postResult(
      payload.callback_urls.result,
      callbackSecret,
      {
        outcome: 'failed',
        errorCode: 'unknown_driver',
        message: `No driver registered for ${payload.driver}`,
      },
      logger.excerpt(),
    );

    return;
  }

  const screenshotsDir = path.resolve('storage/screenshots', payload.run_uuid);
  fs.mkdirSync(screenshotsDir, { recursive: true });

  try {
    const result = await withBrowserContext(payload.session_key, async (context) => {
      const page = await context.newPage();

      const screenshot = async (label: string): Promise<void> => {
        const filePath = path.join(screenshotsDir, `${label}.png`);
        await page.screenshot({ path: filePath, fullPage: true });

      };

      return driver.execute({
        page,
        payload,
        logger,
        screenshot,
      });
    });

    await postResult(payload.callback_urls.result, callbackSecret, result, logger.excerpt());
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown automation error';

    logger.log('fatal', message, 'error');

    await postResult(
      payload.callback_urls.result,
      callbackSecret,
      {
        outcome: 'failed',
        errorCode: 'browser_crash',
        message,
      },
      logger.excerpt(),
    );
  }
}
