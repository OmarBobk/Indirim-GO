import fs from 'node:fs';
import path from 'node:path';
import { withBrowserContext } from '../browser/pool.js';
import { postResult } from '../callbacks/postResult.js';
import { uploadArtifact } from '../callbacks/uploadArtifact.js';
import { resolveDriver } from '../drivers/index.js';
import { RunLogger } from '../logging/runLogger.js';
import { workerScreenshotsDir } from '../storage/workerPaths.js';
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

  const screenshotsDir = workerScreenshotsDir(payload.run_uuid);
  const capturedScreenshots: Array<{ label: string; path: string }> = [];

  try {
    const result = await withBrowserContext(payload.session_key, async (context) => {
      const page = await context.newPage();

      const screenshot = async (label: string): Promise<void> => {
        const filePath = path.join(screenshotsDir, `${label}.png`);
        await page.screenshot({ path: filePath, fullPage: true });
        capturedScreenshots.push({ label, path: filePath });
        logger.log('screenshot', `Saved ${filePath}`);
      };

      return driver.execute({
        page,
        payload,
        logger,
        screenshot,
      });
    });

    await uploadCapturedScreenshots(payload, capturedScreenshots, logger);

    const deliveredPayload = {
      ...(result.deliveredPayload ?? {}),
      screenshots: capturedScreenshots.map((shot) => ({
        label: shot.label,
        path: shot.path,
      })),
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

    await uploadCapturedScreenshots(payload, capturedScreenshots, logger).catch(() => {
      // Best-effort artifact upload on failure.
    });

    await postResult(
      payload.callback_urls.result,
      callbackSecret,
      {
        outcome: 'failed',
        errorCode: 'browser_crash',
        message,
        deliveredPayload: {
          screenshots: capturedScreenshots.map((shot) => ({
            label: shot.label,
            path: shot.path,
          })),
        },
      },
      logger.excerpt(),
    );
  }
}

async function uploadCapturedScreenshots(
  payload: RunPayload,
  screenshots: Array<{ label: string; path: string }>,
  logger: RunLogger,
): Promise<void> {
  if (screenshots.length === 0 || callbackSecret === '') {
    return;
  }

  const artifactsUrl = payload.callback_urls.artifacts;

  for (const shot of screenshots) {
    if (!fs.existsSync(shot.path)) {
      logger.log('screenshot', `Skipping missing file ${shot.path}`, 'warn');

      continue;
    }

    try {
      await uploadArtifact(artifactsUrl, callbackSecret, shot.path, shot.label);
      logger.log('screenshot', `Uploaded ${shot.label}.png to Laravel`);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Artifact upload failed';
      logger.log('screenshot', message, 'warn');
    }
  }
}
