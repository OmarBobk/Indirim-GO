import { WORKER_BUILD } from '../build.js';
import { withBrowserContext } from '../browser/pool.js';
import { uploadArtifactBytes } from '../callbacks/uploadArtifact.js';
import { postResult } from '../callbacks/postResult.js';
import { resolveDriver } from '../drivers/index.js';
import { RunLogger } from '../logging/runLogger.js';
import { removeLegacyWorkerScreenshotsDir } from '../storage/workerPaths.js';
import type { RunPayload } from '../types.js';

const callbackSecret = process.env.FULFILLMENT_AUTOMATION_CALLBACK_SECRET ?? '';

export async function executeRun(payload: RunPayload): Promise<void> {
  const logger = new RunLogger(payload.run_uuid, payload.fulfillment_id);
  const driver = resolveDriver(payload.driver);

  removeLegacyWorkerScreenshotsDir(payload.run_uuid);

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

  logger.log('worker', `build=${WORKER_BUILD} driver=${payload.driver}`);

  const capturedScreenshotLabels: string[] = [];
  const artifactsUrl = payload.callback_urls.artifacts;
  const canUploadArtifacts = callbackSecret !== '' && artifactsUrl !== '';

  try {
    const result = await withBrowserContext(payload.session_key, async (context) => {
      const page = await context.newPage();

      const screenshot = async (label: string): Promise<void> => {
        const fileData = await page.screenshot({ type: 'png', fullPage: true });
        capturedScreenshotLabels.push(label);
        logger.log('screenshot', `Captured ${label}.png (${fileData.byteLength} bytes)`);

        if (!canUploadArtifacts) {
          return;
        }

        try {
          await uploadArtifactBytes(artifactsUrl, callbackSecret, fileData, label);
          logger.log('screenshot', `Uploaded ${label}.png to Laravel`);
        } catch (error) {
          const message = error instanceof Error ? error.message : 'Artifact upload failed';
          logger.log('screenshot', message, 'warn');
        }
      };

      return driver.execute({
        page,
        payload,
        logger,
        screenshot,
      });
    });

    const deliveredPayload = {
      ...(result.deliveredPayload ?? {}),
      screenshots: capturedScreenshotLabels.map((label) => ({ label })),
    };

    await postResult(
      payload.callback_urls.result,
      callbackSecret,
      { ...result, deliveredPayload },
      logger.excerpt(),
    );
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
        deliveredPayload: {
          screenshots: capturedScreenshotLabels.map((label) => ({ label })),
        },
      },
      logger.excerpt(),
    );
  }
}
