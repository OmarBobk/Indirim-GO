import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import express from 'express';
import { verifyLaravelRequest } from './auth/verifyLaravel.js';
import { clearSessionState } from './browser/sessionStore.js';
import { WORKER_BUILD } from './build.js';
import { listSupportedDrivers } from './drivers/index.js';
import { DRIVER_VERSIONS } from './progress/steps.js';
import { executeRun } from './runs/executeRun.js';
import { executePriceScan } from './runs/executePriceScan.js';
import { shutdownBrowserPool } from './browser/pool.js';
import { workerStoragePath } from './storage/workerPaths.js';
import {
  WORKER_INSTANCE_ID,
  getActiveCount,
  getLastSuccessfulTaskAt,
  getUptimeSeconds,
} from './workerIdentity.js';
import type { PriceScanPayload, RunPayload } from './types.js';

const require = createRequire(import.meta.url);

const app = express();
const port = Number(process.env.PORT ?? 3100);
const secret = process.env.FULFILLMENT_AUTOMATION_CALLBACK_SECRET ?? '';
const maxConcurrency = Number(process.env.FULFILLMENT_AUTOMATION_MAX_CONCURRENCY ?? 1);

let isReady = true;

function getPlaywrightVersion(): string {
  try {
    const packageJson = require('playwright/package.json') as { version: string };

    return packageJson.version;
  } catch {
    return 'unknown';
  }
}

function isSessionStoreAvailable(): boolean {
  try {
    const keepFilePath = workerStoragePath('sessions', '.keep');
    fs.accessSync(path.dirname(keepFilePath), fs.constants.R_OK | fs.constants.W_OK);

    return true;
  } catch {
    return false;
  }
}

app.use(
  express.json({
    verify: (req, _res, buf) => {
      (req as express.Request & { rawBody?: string }).rawBody = buf.toString('utf8');
    },
  }),
);

function verifySignedRequest(req: express.Request, res: express.Response): string | null {
  const rawBody = (req as express.Request & { rawBody?: string }).rawBody ?? JSON.stringify(req.body);
  const signature = String(req.header('X-Automation-Signature') ?? '');
  const timestamp = String(req.header('X-Automation-Timestamp') ?? '');

  if (!verifyLaravelRequest(rawBody, signature, timestamp, secret)) {
    res.status(401).json({ message: 'Invalid signature' });

    return null;
  }

  return rawBody;
}

app.get('/health', (_req, res) => {
  const lastSuccessfulTaskAt = getLastSuccessfulTaskAt();

  res.json({
    status: 'ok',
    ready: isReady,
    build: WORKER_BUILD,
    instance_id: WORKER_INSTANCE_ID,
    uptime_seconds: getUptimeSeconds(),
    active_count: getActiveCount(),
    configured_max_concurrency: maxConcurrency,
    browser_available: true,
    playwright_version: getPlaywrightVersion(),
    session_store_available: isSessionStoreAvailable(),
    supported_drivers: listSupportedDrivers(),
    driver_versions: DRIVER_VERSIONS,
    last_successful_task_at: lastSuccessfulTaskAt !== null ? lastSuccessfulTaskAt.toISOString() : null,
    server_time: new Date().toISOString(),
    wasim_submit_purchase: true,
    wasim_reconcile: true,
    wasim_price_scan: true,
    session_clear: true,
  });
});

app.post('/v1/sessions/clear', (req, res) => {
  if (verifySignedRequest(req, res) === null) {
    return;
  }

  const sessionKey = String((req.body as { session_key?: string }).session_key ?? '').trim();

  if (sessionKey === '') {
    res.status(422).json({ message: 'session_key is required' });

    return;
  }

  const cleared = clearSessionState(sessionKey);

  res.json({ cleared, session_key: sessionKey });
});

app.post('/v1/runs', (req, res) => {
  if (verifySignedRequest(req, res) === null) {
    return;
  }

  const payload = req.body as RunPayload;

  res.status(202).json({ accepted: true, run_uuid: payload.run_uuid });

  void executeRun(payload);
});

app.post('/v1/price-scans', (req, res) => {
  if (verifySignedRequest(req, res) === null) {
    return;
  }

  const payload = req.body as PriceScanPayload;

  if (!payload.scan_uuid || !Array.isArray(payload.items)) {
    res.status(422).json({ message: 'scan_uuid and items are required' });

    return;
  }

  res.status(202).json({ accepted: true, scan_uuid: payload.scan_uuid });

  void executePriceScan(payload);
});

const server = app.listen(port, () => {
  console.log(JSON.stringify({ event: 'worker_started', port, build: WORKER_BUILD, instance_id: WORKER_INSTANCE_ID }));
});

process.on('SIGTERM', async () => {
  isReady = false;
  server.close();
  await shutdownBrowserPool();
  process.exit(0);
});
