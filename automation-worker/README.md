# Fulfillment automation worker

Playwright execution runtime for İndirimGo browser fulfillments. Laravel owns all business state; this service only runs browsers and reports results.

## Environment

- `PORT` (default `3100`)
- `FULFILLMENT_AUTOMATION_CALLBACK_SECRET` (must match Laravel)
- `PLAYWRIGHT_HEADLESS` (`true` default)

## Endpoints

### `GET /health`

Returns `{ "status": "ok" }`.

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
    "artifacts": "https://app/internal/automation/runs/{uuid}/artifacts"
  }
}
```

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

Screenshots are saved under `storage/screenshots/{run_uuid}/` on the worker host. Laravel artifact upload from the worker is not wired yet; use local files for debugging.

## Drivers

Add `src/drivers/{name}/index.ts` and register in `src/drivers/index.ts`.
