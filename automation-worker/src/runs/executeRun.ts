import { WORKER_BUILD } from '../build.js';
import { withBrowserContext } from '../browser/pool.js';
import { uploadArtifactBytes } from '../callbacks/uploadArtifact.js';
import { postResult } from '../callbacks/postResult.js';
import { resolveDriver } from '../drivers/index.js';
import { RunLogger } from '../logging/runLogger.js';
import { ProgressReporter } from '../progress/ProgressReporter.js';
import { DRIVER_VERSIONS } from '../progress/steps.js';
import { removeLegacyWorkerScreenshotsDir } from '../storage/workerPaths.js';
import {
  WORKER_INSTANCE_ID,
  decrementActiveCount,
  incrementActiveCount,
  markSuccessfulTask,
} from '../workerIdentity.js';
import type { DriverOutcome, RunPayload } from '../types.js';

const callbackSecret = process.env.FULFILLMENT_AUTOMATION_CALLBACK_SECRET ?? '';

const OUTCOMES_COUNTED_AS_SUCCESSFUL_TASK: DriverOutcome[] = ['success', 'submitted', 'pending_reconcile'];

export async function executeRun(payload: RunPayload): Promise<void> {
  const logger = new RunLogger(payload.run_uuid, payload.fulfillment_id);
  const driver = resolveDriver(payload.driver);
  const phase = payload.automation_phase ?? 'purchase';

  const progress = new ProgressReporter({
    progressUrl: payload.callback_urls.progress,
    secret: callbackSecret,
    phase,
    workerInstanceId: WORKER_INSTANCE_ID,
    workerBuild: WORKER_BUILD,
    driverName: payload.driver,
    driverVersion: DRIVER_VERSIONS[payload.driver] ?? 'unknown',
    sessionAlias: payload.session_key,
  });

  progress.step('worker_received');
  progress.startHeartbeat();

  removeLegacyWorkerScreenshotsDir(payload.run_uuid);

  if (driver === null) {
    progress.stop();

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

  incrementActiveCount();

  try {
    progress.step('browser_starting');

    const result = await withBrowserContext(
      payload.session_key,
      payload.credentials,
      async (context) => {
        const page = await context.newPage();

        progress.step('browser_ready');

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
          progress,
        });
      },
    );

    progress.step('finalizing_result');

    const deliveredPayload = {
      ...(result.deliveredPayload ?? {}),
      screenshots: capturedScreenshotLabels.map((label) => ({ label })),
      ...(progress.getDiagnostics().progress_failures > 0 ? { progress_observability_degraded: true } : {}),
    };

    progress.step('callback_sending');

    await postResult(
      payload.callback_urls.result,
      callbackSecret,
      { ...result, deliveredPayload },
      logger.excerpt(),
    );

    if (OUTCOMES_COUNTED_AS_SUCCESSFUL_TASK.includes(result.outcome)) {
      markSuccessfulTask();
    }
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
          ...(progress.getDiagnostics().progress_failures > 0 ? { progress_observability_degraded: true } : {}),
        },
      },
      logger.excerpt(),
    );
  } finally {
    progress.stop();
    decrementActiveCount();
  }
}
