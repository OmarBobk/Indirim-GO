# Fulfillments & Automation

Order line fulfillment: admin manual flow + Node/Playwright automation worker.

## Invariants

- Automation **does not** mark fulfillments failed — admin decides
- Worker callbacks: HMAC-signed, CSRF exempt under `internal/automation/*`
- Delivered payload display: `CustomerDeliveredPayload` filters automation internals

## Key files

- `app/Actions/Fulfillments/*`
- `app/Services/FulfillmentAutomationService.php`
- `automation-worker/`
- `routes/automation.php`

## Related

- [[Orders & Checkout]]
- [[Refunds & Settlements]]
- [[Future Roadmap - Automation and Growth]] — Track C (Wasim harden → 2nd driver → routing → price updates)
