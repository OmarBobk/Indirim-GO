# Fulfillment automation worker

Playwright execution runtime for İndirimGo browser fulfillments. Laravel owns all business state; this service only runs browsers and reports results.

## Environment

- `PORT` (default `3100`)
- `FULFILLMENT_AUTOMATION_CALLBACK_SECRET` (must match Laravel)
- `PLAYWRIGHT_HEADLESS` (`true` default)
- `FULFILLMENT_AUTOMATION_PROGRESS_HEARTBEAT_SECONDS` (default `15`) — heartbeat interval for the progress beacon
- `FULFILLMENT_AUTOMATION_MAX_CONCURRENCY` (default `1`) — reported on `/health` for operator dashboards; not enforced by the worker itself

## Endpoints

### `GET /health`

Returns worker identity, capacity, and driver support info, e.g.:

```json
{
  "status": "ok",
  "ready": true,
  "build": "2026-08-01-c1.1-progress",
  "instance_id": "…",
  "uptime_seconds": 42,
  "active_count": 0,
  "configured_max_concurrency": 1,
  "browser_available": true,
  "playwright_version": "1.52.0",
  "session_store_available": true,
  "supported_drivers": ["wasim", "acme"],
  "driver_versions": { "wasim": "wasim-1.0.0", "acme": "acme-1.0.0" },
  "last_successful_task_at": null,
  "server_time": "2026-08-01T00:00:00.000Z",
  "wasim_submit_purchase": true,
  "wasim_reconcile": true,
  "wasim_price_scan": true,
  "session_clear": true
}
```

### `POST /v1/runs`

Laravel dispatches a run with HMAC headers:

- `X-Automation-Timestamp`
- `X-Automation-Signature` (`sha256=` + HMAC of `{timestamp}.{rawBody}`)

Request body:

```json
{
  "run_uuid": "uuid",
  "fulfillment_id": 123,
  "supplier_key": "acme",
  "driver": "acme",
  "session_key": "acme-main",
  "idempotency_reference": "fulfillment:123",
  "requirements": {},
  "custom_amount": null,
  "callback_urls": {
    "result": "https://app/internal/automation/runs/{uuid}/result",
    "artifacts": "https://app/internal/automation/runs/{uuid}/artifacts",
    "progress": "https://app/internal/automation/runs/{uuid}/progress"
  }
}
```

`callback_urls.progress` is optional. When present, the worker posts a signed
progress beacon (current step + a ~15s heartbeat) to that URL as the run
advances through `ALLOWED_STEPS` (see `src/progress/steps.ts`, which mirrors
`App\Enums\FulfillmentAutomationProgressStep`). Progress delivery is
best-effort: failures are logged and counted but never fail the run itself.
When absent, no progress requests are made.

Response: `202 Accepted` immediately; execution continues async.

### Laravel callback `POST /internal/automation/runs/{uuid}/result`

Worker sends:

```json
{
  "outcome": "success",
  "external_order_id": "ACME-123-...",
  "delivered_payload": {},
  "log_excerpt": []
}
```

`outcome` may be `success`, `failed`, or `needs_review`.

## Development

```bash
cd automation-worker
npm install
npm run build
npm start
```

## Production deploy (required for driver changes)

Laravel deploy alone is not enough. After pulling code:

```bash
cd automation-worker
npm ci
npm run build
# restart the process (pm2/systemd) — NOT only Laravel queue
npm start
```

Verify the **compiled files** (build can succeed while an old Node process keeps running):

```bash
grep wasim_submit_purchase dist/server.js   # must print a match
npm run build                                # prints "Build OK: 2026-06-04-wasim-submit"
```

Restart the worker, then verify the **live process**:

```bash
curl -s http://127.0.0.1:3100/health
# must include: "build":"2026-06-04-wasim-submit","wasim_submit_purchase":true
```

If `dist/server.js` has `wasim_submit_purchase` but `curl` still returns only `{"status":"ok"}`, the old process was **not restarted** (pm2/systemd/manual `node` still running).

```bash
# example with pm2
pm2 list
pm2 restart <automation-worker-app-name>
```

If `grep wasim_submit_purchase dist/server.js` finds nothing, run `git pull` in the repo root first — the server checkout is behind.

If runs still show `flow_incomplete`, you are viewing an old run — **retry** the fulfillment after redeploy.

Screenshots are captured in memory, uploaded to Laravel immediately (`storage/app/private/fulfillment-automation/{run_uuid}/`), and are not kept on the worker disk. Legacy folders under `automation-worker/storage/screenshots/` are removed when a run starts. Prune old Laravel copies with `php artisan fulfillment:prune-automation-artifacts`.

## Drivers

| Driver | Supplier | Status |
|--------|----------|--------|
| `wasim` | Wasim Store | Product page → form fill → margin check → purchase → parse Swal result |
| `acme` | Test placeholder | Simulated success for automated tests |

Add `src/drivers/{name}/index.ts` and register in `src/drivers/index.ts`.
Register the supplier in Laravel `config/fulfillment_automation.php` and set the package to `browser:{supplier_key}`.
