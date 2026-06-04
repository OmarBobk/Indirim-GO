import express from 'express';
import { verifyLaravelRequest } from './auth/verifyLaravel.js';
import { executeRun } from './runs/executeRun.js';
import { shutdownBrowserPool } from './browser/pool.js';
import type { RunPayload } from './types.js';

const app = express();
const port = Number(process.env.PORT ?? 3100);
const secret = process.env.FULFILLMENT_AUTOMATION_CALLBACK_SECRET ?? '';

app.use(
  express.json({
    verify: (req, _res, buf) => {
      (req as express.Request & { rawBody?: string }).rawBody = buf.toString('utf8');
    },
  }),
);

app.get('/health', (_req, res) => {
  res.json({ status: 'ok' } );
});

app.post('/v1/runs', (req, res) => {
  const rawBody = (req as express.Request & { rawBody?: string }).rawBody ?? JSON.stringify(req.body);
  const signature = String(req.header('X-Automation-Signature') ?? '');
  const timestamp = String(req.header('X-Automation-Timestamp') ?? '');

  if (!verifyLaravelRequest(rawBody, signature, timestamp, secret)) {
    res.status(401).json({ message: 'Invalid signature' });

    return;
  }

  const payload = req.body as RunPayload;

  res.status(202).json({ accepted: true, run_uuid: payload.run_uuid });

  void executeRun(payload);
});

const server = app.listen(port, () => {
  console.log(JSON.stringify({ event: 'worker_started', port }));
});

process.on('SIGTERM', async () => {
  server.close();
  await shutdownBrowserPool();
  process.exit(0);
});
