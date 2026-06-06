import express from 'express';
import { verifyLaravelRequest } from './auth/verifyLaravel.js';
import { clearSessionState } from './browser/sessionStore.js';
import { WORKER_BUILD } from './build.js';
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
  res.json({
    status: 'ok',
    build: WORKER_BUILD,
    wasim_submit_purchase: true,
    wasim_reconcile: true,
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

const server = app.listen(port, () => {
  console.log(JSON.stringify({ event: 'worker_started', port, build: WORKER_BUILD }));
});

process.on('SIGTERM', async () => {
  server.close();
  await shutdownBrowserPool();
  process.exit(0);
});
