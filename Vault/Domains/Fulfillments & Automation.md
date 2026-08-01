# Fulfillments & Automation

Order line fulfillment: admin manual flow + Node/Playwright automation worker.

## Invariants

- Laravel owns business/workflow state; worker executes browser only
- Worker callbacks: HMAC-signed, CSRF exempt under `internal/automation/*`
- Delivered payload display: `CustomerDeliveredPayload` filters automation internals
- Progress/heartbeat (planned C1.1) must **not** mutate fulfillment status; final result callback remains authoritative for outcomes
- **Note:** failure/timeout paths **do** call `FailFulfillment` today (ingest `failed`/`needs_review`, stale sweep). Older “never fail via automation” wording is outdated.

## Key files

- `app/Actions/Fulfillments/*`
- `app/Services/FulfillmentAutomationService.php`
- `app/Livewire/Admin/AutomationMonitor.php`
- `automation-worker/`
- `routes/automation.php`

## Track C status

- [[C1 — Automation Reliability and Supplier UI Resilience]] — C1.0 architecture done; C1.1 dashboard/progress next
- [[Future Roadmap - Automation and Growth]] — C1 → C2 → C3 → C4

## Related

- [[Orders & Checkout]]
- [[Refunds & Settlements]]
- [[Future Roadmap - Automation and Growth]]
