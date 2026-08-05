---
status: shipped-partial
created: 2026-08-01
updated: 2026-08-05
owner: Omar
type: feature
milestone: C1.1
---

# C1 — Automation Reliability and Supplier UI Resilience

Related: [[Fulfillments & Automation]], [[Future Roadmap - Automation and Growth]], [[İndirimGo Index]]

## Goal

Give admins a live Automation Operations Dashboard, structured run progress/heartbeats, and (later) Wasim UI adapters/contracts/circuit breakers — without moving business truth into the worker.

## Constraints

- Stack: Laravel 12, Livewire 4, Tailwind 4, Flux **FREE** only
- Laravel owns business/workflow state; worker executes browser only
- HMAC callbacks; progress never mutates fulfillment/financial finality
- C1.1 admin-only under existing admin gate
- No AI-generated click paths in production

## Non-goals (still deferred)

- C1.2: UI adapters, UI-version detection, page contracts, session/supplier health probes
- C1.3: circuit breakers, auto purchase pause, resume policies
- Second supplier, selector redesign, Git/deploy in this milestone

## Architecture status (2026-08-05)

**C1.0** architecture done (2026-08-01).  
**C1.1 shipped** — Live Automation Operations Dashboard + structured progress/heartbeat.  
**Next:** C1.2 Wasim UI Adapter and Contract Health (not started).

---

## C1.1 shipped (2026-08-05)

### Progress protocol

- Route: `POST /internal/automation/runs/{uuid}/progress` (HMAC, same middleware as result)
- Payload: `progress_sequence`, `phase`, `step` (allowlisted enum), `emitted_at`, `heartbeat`, safe message/params, worker/driver metadata, nullable UI/contract versions, `session_alias`
- Monotonic sequence; duplicate/out-of-order ignored; terminal runs no-op
- Progress **cannot** change fulfillment status, schedule refunds, or act as price authority
- Final result callback remains outcome authority

### Snapshot + events

- Run columns: `progress_sequence`, `last_heartbeat_at`, `current_step_started_at`, `progress_snapshot` (JSON)
- Table `fulfillment_automation_run_events` — unique `(run_id, sequence)`; created only on **step change**
- Heartbeats update snapshot only (no event rows)
- Per-run event prune via `progress.events_per_run_limit` (default 100); artifact prune also deletes events

### Step registry

`FulfillmentAutomationProgressStep` (+ worker `ALLOWED_STEPS`): shared + purchase + reconcile steps (no click-level noise). EN/AR labels under `messages.automation_step_*`.

### Heartbeat / stale

- Worker heartbeat ~15s while owning a run (`progress.heartbeat_interval_seconds`)
- Config `liveness.*`: purchase/reconcile slow 180s, stale 480s; legacy fallback 30m
- Waiting supplier / scheduled reconcile are **not** worker-stale
- Sweeper uses heartbeat-first classification; skips Reserved; FailFulfillment only for stale Running/Dispatched

### Dashboard

- Upgraded `/admin/automation` (not replaced)
- `GetAutomationOperationsDashboard` → DTOs → presenter → board partial
- Health cards: global, worker, active ops, needs attention, session (unknown until C1.2), driver
- Sections: Working now, Waiting for supplier, Scheduled reconciliation, Needs attention, Recent outcomes + existing paginated inbox
- Honest presentation: succeeded purchase + awaiting reconcile → “Supplier accepted — awaiting reconciliation”
- Realtime: `run_progress_changed` refreshes ops board; does not reset history pagination; heartbeats not broadcast
- Client-side elapsed timers only (display); server owns stale classification

### Worker

- `ProgressReporter`, instance ID, richer `/health`, build `2026-08-01-c1.1-progress`
- Instrumentation around existing Wasim flow; `pre_submit` screenshot before buy click
- Progress failures non-blocking; may set `progress_observability_degraded` on final payload

### Exception counts

`automation_needs_review` badge = needs-review runs + stale active + reconcile exhausted (`AutomationActionRequiredQuery`)

### Tests

- `AutomationProgressCallbackTest`, `AutomationOperationsDashboardTest`
- Regression: `FulfillmentAutomationTest`, `AutomationAdminTest`, `PruneFulfillmentAutomationArtifactsTest`
- Worker: `npm run test:progress` + build verify

### Key files

- `app/Actions/Fulfillments/IngestFulfillmentAutomationProgress.php`
- `app/Actions/Fulfillments/GetAutomationOperationsDashboard.php`
- `app/Support/Automation/*`
- `app/Enums/FulfillmentAutomationProgressStep.php`
- `app/Livewire/Admin/AutomationMonitor.php` + `partials/automation-operations-board.blade.php`
- `automation-worker/src/progress/*`, `workerIdentity.ts`, Wasim progress hooks
- Migration `2026_08_01_204758_add_progress_fields_to_fulfillment_automation_runs_table.php`

## Acceptance criteria

- [x] C1.0 architecture
- [x] Progress registry + HMAC callback
- [x] Snapshot + bounded events
- [x] Worker progress/heartbeat + richer health
- [x] Heartbeat-aware stale + waiting/scheduled visibility
- [x] Dashboard health header + working-now board
- [x] Selective realtime
- [x] Pre-submit artifact
- [x] Tests + Pint + worker/frontend build
- [ ] C1.2 adapters/contracts (not started)
- [ ] C1.3 circuits (not started)

## Gotchas

- `submitted` / awaiting reconcile still terminalizes the **run** as Succeeded; board derives waiting/scheduled from fulfillment meta + `next_reconcile_at`
- Do not derive current step from `log_excerpt`
- Progress sequence starts at 1 (worker increments before send)
- Session health card intentionally unknown until C1.2
- Pre-submit screenshots may still show player ID on page — private admin artifact; no HTML capture in C1.1
- Restart automation-worker after deploy so `/health` build matches `2026-08-01-c1.1-progress`

## Open questions / C1.2 exclusions

- UI adapters, detector, page contracts, non-mutating supplier probes
- Circuit breakers / auto pause / probe-gated resume
- Dedicated automation permissions (still admin-only)
