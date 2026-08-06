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

## C1.2 (shipped)

- Wasim UI adapter `wasim-ui-v1` + deterministic detection; unknown/ambiguous quarantine before submit
- Purchase/orders page contracts; typed UI failure codes
- Non-mutating HMAC probe `POST /v1/suppliers/wasim/probe` + Laravel `RunWasimHealthProbe` + cache snapshot
- Dashboard session/driver cards from probe; admin on-demand + scheduled probe

## C1.3 (shipped — code)

- Durable `automation_supplier_circuits` — Wasim `purchase` / `reconcile` / `price_scan` independent
- States: `enabled` | `paused_auto` | `paused_manual` | `probe_required`
- Laravel `AutomationCircuitPolicy` is enforceable truth; worker hints diagnostic only
- Dispatch gating; queued stay queued; no refund/cancel on open
- Dashboard circuit cards + Waiting for automation recovery + pause/resume

## C1.4 (acceptance — NOT CLOSED 2026-08-06)

- Automated gates green (Laravel + worker selfchecks); runbook: `Docs/AUTOMATION_OPERATIONS_RUNBOOK.md`
- **Blocked:** dirty `local/track-c1` worktree (C1.2/C1.3 uncommitted), local env, worker unreachable, probe product empty, no live probe/purchase
- Verdict **C — Not closed** until live gates pass (or Branch B safe pause with evidence)

## Key files

- `app/Actions/Fulfillments/*` (progress, ops dashboard, probe, circuit Observe/Pause/Resume)
- `app/Support/Automation/*` (`WasimHealthProbeStore`, `AutomationCircuitPolicy`, `AutomationCircuitGate`)
- `app/Models/AutomationSupplierCircuit.php`
- `app/Services/FulfillmentAutomationService.php`
- `Docs/AUTOMATION_OPERATIONS_RUNBOOK.md`
- `automation-worker/` (`src/progress/*`, `src/drivers/wasim/ui/*`)

## Track C status

- [[C1 — Automation Reliability and Supplier UI Resilience]] — code through C1.3; **C1.4 not closed**
- [[Future Roadmap - Automation and Growth]] — C1 → C2 → C3 → C4

## Related

- [[Orders & Checkout]]
- [[Refunds & Settlements]]
- [[Future Roadmap - Automation and Growth]]
