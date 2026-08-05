# Fulfillments & Automation

Order line fulfillment: admin manual flow + Node/Playwright automation worker.

## Invariants

- Laravel owns business/workflow state; worker executes browser only
- Worker callbacks: HMAC-signed, CSRF exempt under `internal/automation/*` (result, artifacts, **progress**)
- Delivered payload display: `CustomerDeliveredPayload` filters automation internals
- Progress/heartbeat is operational display state only — **must not** mutate fulfillment status or money; final result callback remains outcome authority
- Failure/timeout paths **do** call `FailFulfillment` (ingest `failed`/`needs_review`, heartbeat-aware stale sweep)

## C1.1 (shipped)

- Structured progress snapshot + bounded `fulfillment_automation_run_events`
- `/admin/automation` operations board (working now / waiting supplier / scheduled / needs attention)
- Worker progress reporter + richer `/health` + `pre_submit` artifact
- Config: `fulfillment_automation.progress` + `liveness`

## Key files

- `app/Actions/Fulfillments/*` (incl. `IngestFulfillmentAutomationProgress`, `GetAutomationOperationsDashboard`)
- `app/Support/Automation/*`
- `app/Services/FulfillmentAutomationService.php`
- `app/Livewire/Admin/AutomationMonitor.php`
- `automation-worker/` (`src/progress/*`)
- `routes/automation.php`

## Track C status

- [[C1 — Automation Reliability and Supplier UI Resilience]] — **C1.1 shipped**; next C1.2 adapters/contracts
- [[Future Roadmap - Automation and Growth]] — C1 → C2 → C3 → C4

## Related

- [[Orders & Checkout]]
- [[Refunds & Settlements]]
- [[Future Roadmap - Automation and Growth]]
