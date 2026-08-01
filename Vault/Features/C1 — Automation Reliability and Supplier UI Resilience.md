---
status: architecture
created: 2026-08-01
owner: Omar
type: feature
milestone: C1.0
---

# C1 — Automation Reliability and Supplier UI Resilience

> Architecture-only (C1.0). Not shipped. Do not treat as implementation.

Related: [[Fulfillments & Automation]], [[Future Roadmap - Automation and Growth]], [[İndirimGo Index]]

## Goal

Give admins a live Automation Operations Dashboard, structured run progress/heartbeats, and a Wasim driver architecture that survives supplier UI changes via versioned adapters, page contracts, unknown-UI quarantine, and circuit breakers — without moving business truth into the worker.

## Constraints

- Stack: Laravel 12, Livewire 4, Tailwind 4, Flux **FREE** only
- Laravel owns business/workflow state; worker executes browser only
- HMAC callbacks; no worker trust for financial/fulfillment finality beyond validated result contracts
- Backend denial: 404 by design; prefer permission-based access over role shortcuts where project is moving that way
- No AI-generated click paths in production
- Do not add packages without approval

## Non-goals (C1.0)

- No production code, migrations, worker/selector changes
- No dashboard UI build, adapters, circuit breakers, or C1.1 start
- No Git / deploy / server work

## Architecture status (2026-08-01)

**C1.0 architecture accepted for planning.** Implementation split: **C1.1 → C1.4**. Active milestone after Omar decisions: **C1.1**.

### Verdict (C1.0)

Existing purchase/reconcile orchestration is solid enough to extend. Observability and UI resilience are the gaps: no mid-run progress/heartbeat, weak health signals, monolithic Wasim selectors, no UI-version detection/contracts/circuit breakers. Dashboard should **upgrade** `/admin/automation`, not replace it.

### Architecture scores (C1.0)

| Dimension | Score | Note |
|---|---|---|
| Architecture | **7.5 / 10** | Clear Laravel/worker split; missing progress + adapter layers |
| Operational safety | **5.5 / 10** | Admin inbox exists; cannot answer “what step now?” |
| Financial safety | **8 / 10** | HMAC + idempotent ingest; cancel/refund paths exist; stale may fail too aggressively |
| UI resilience | **3 / 10** | Selectors inline; Arabic/Swal/DataTables coupled; no adapter/contract |

**C1.1 readiness:** Ready after Omar decisions in §Must decide before C1.1. Do not start adapters/circuits in C1.1.

---

## Current system snapshot (audit)

### Ownership

- Laravel: eligibility, reserve/dispatch/ingest, fulfillments, refunds, admin UI, schedules
- Worker (`automation-worker/`): Playwright, Wasim purchase/reconcile/price-scan, session storageState, artifact upload
- Kill switches: env `FULFILLMENT_AUTOMATION_ENABLED`, `WebsiteSetting::automation_enabled`, package `fulfillment_provider`

### Purchase lifecycle (authoritative)

1. Eligibility → `FulfillmentAutomationService::isEligible`
2. Reserve → `ReserveFulfillmentAutomationRun` (`reserved`, idempotency `automation:fulfillment:{id}:attempt:{n}`)
3. Start fulfillment → `StartFulfillment` (`queued` → `processing`)
4. Dispatch → `DispatchFulfillmentAutomationRun` → worker `POST /v1/runs` (run jumps to **`running`**; `dispatched` enum rarely persisted)
5. Worker: session → product → price check → fill → submit Swal → callback
6. Ingest `submitted` → run **`succeeded`**, fulfillment stays **`processing`**, meta `awaiting_wasim_reconcile` + `supplier_order_id`
7. `ScheduleWasimOrderReconcile` → delayed `DispatchWasimReconcileJob`

### Reconcile lifecycle

1. `isEligibleForReconcile` (processing + awaiting + supplier_order_id)
2. `ReserveFulfillmentAutomationReconcileRun` (idempotency `…:reconcile:{n}`)
3. Dispatch same worker path with `automation_phase=reconcile`
4. Outcomes: `success` → CompleteFulfillment; `failed` (e.g. cancelled + refund path); `pending_reconcile` → run succeeded + reschedule; exhaust → `requires_review` meta **without** NeedsReview run / FailFulfillment

### Representation gotcha

`submitted` and `pending_reconcile` are **callback outcomes**, not run statuses. Waiting work lives on **fulfillment meta**, while the purchase/reconcile **run row is terminal Succeeded**. Working-now board must query meta + scheduled jobs, not only `scopeActive`.

### Existing admin surface

`/admin/automation` (`AutomationMonitor`) — admin role: KPIs, status inbox + Reverb `admin.automation`, needs-review, worker build probe, Wasim credentials, clear session, flow guide, retry/cancel/review, artifacts. Exception badge: `automation_needs_review` only.

### Observability gaps

- No `current_step` / heartbeat / progress callback
- Worker `/health` = build + hardcoded capability booleans
- Session health not probed
- No UI version / page contract / circuit state
- Stale sweep treats long `Running` as timeout → FailFulfillment (cannot distinguish supplier wait vs worker lost — purchase wait is short; reconcile wait is **between** runs)
- Reconcile exhaustion under-visible in needs-review badge
- `max_concurrent_runs` / `dispatch.max_attempts` configured but unused in PHP
- Progress only in final `log_excerpt` (free-text steps)

### Worker / Wasim fragility

- Workflow + selectors mixed (`submitPurchase.ts`, `reconcileOrder.ts`, `ordersPageHelpers.ts`, login duplicated)
- Fragility: Swal2 classes, Arabic copy, DataTables `#responsiveDataTable2` + responsive expand + 1920×1080 viewport, placeholder text
- No UI detector, page contracts, adapters, heartbeat
- Artifacts: full-page PNG, no redaction; retention config 30 days
- Build: `WORKER_BUILD = '2026-05-29-session-credentials'`

---

## Target architecture (approved direction)

### Progress protocol (C1.1)

- Combined HMAC **progress+heartbeat** callback (smallest reliable protocol); final result callback stays separate
- Monotonic `progress_sequence`; safe message codes; no secrets/DOM/credentials; **no fulfillment mutation** from progress
- Storage: **mutable snapshot on run** + **bounded append-only events** for meaningful step transitions (not every heartbeat)
- SystemEvent = mirror only

### Recommended snapshot fields (prove need before migrating)

On `fulfillment_automation_runs` (or equivalent progress JSON column): `current_step`, `current_step_started_at`, `last_heartbeat_at`, `progress_sequence`, `safe_progress_message_code`, `worker_instance_id`, `worker_build`, `driver_name`, `driver_version`, `detected_ui_version`, `page_contract_version`, optional `session_state` / health snapshot refs.

Prefer one JSON progress snapshot + bounded `fulfillment_automation_run_events` over dozens of loose columns.

### Working-now inclusion

Active run statuses (`reserved`/`dispatched`/`running`) **plus** fulfillments with `awaiting_wasim_reconcile` (waiting supplier / scheduled reconcile) even when latest run is `succeeded`.

### Health models

| Layer | States (v1) |
|---|---|
| Worker | reachable / ready / degraded / unavailable |
| Session | unknown / authenticated / authentication_required / expired / invalid / clearing / unavailable |
| Supplier purchase vs reconcile | healthy / degraded / authentication_required / unsupported_ui / maintenance / unreachable / contract_failed / circuit_open — **independent** |
| Circuit (C1.3) | enabled / paused_auto / paused_manual / probe_required |

Purchase circuit open → stop new purchase dispatch; keep reconcile if healthy; do not cancel accepted/submitted; do not auto-fail queued.

### Supplier-driver split (C1.2)

- Stable Wasim business workflow (ensureAuthenticated, openProduct, readPrice, …)
- Versioned UI adapters (`WasimUiV1`, `WasimUiV2`, …) owning selectors
- UI detector (multi-signature) → recognized / ambiguous / unknown / login / maintenance
- Page-contract validators pre-submit / pre-reconcile act
- Unknown/ambiguous → stop before purchase; artifacts; quarantine; consider circuit

### Selector priority

data attributes → form names → labels → a11y roles → stable URLs → semantic relationships → allowlisted text → CSS last resort → no nth-child as primary.

### AI policy

Allowed offline: sanitized artifact analysis, selector suggestions, fixture generation. **Forbidden runtime:** LLM choosing clicks/selectors, autonomous unknown-UI retry, sending secrets to LLMs.

### Permissions

Prefer small dedicated set if leaving admin-role gate: `view_automation_operations`, `manage_automation_operations` (session + circuit under manage). Avoid explosion. Current admin-only is acceptable interim if Omar keeps it.

---

## Milestone split

### C1.1 — Live Automation Operations Dashboard

**In:** progress/heartbeat protocol + snapshot (+ optional bounded events), health header, working-now board, selective realtime on `admin.automation`, worker/session/build display, upgrade `/admin/automation`.

**Out:** adapters, UI detector, contracts, circuit breaker, selector rewrites, Wasim UI changes.

**Laravel:** callback ingest for progress, read models, Livewire board, config thresholds, possibly migration for snapshot/events.

**Worker:** emit progress/heartbeat only (minimal); no selector/adapter rewrite.

**Stop:** admin can see working-now step + elapsed + heartbeat without opening free-text logs; health header answers kill-switch/worker reachability/build.

### C1.2 — Wasim UI Adapter and Contract Health

**In:** workflow/adapter split, old+new adapters as needed, detector, purchase/reconcile contracts, non-mutating health probe, driver/UI/contract version metadata, fixture tests.

**Out:** circuit auto-pause (may report unsupported_ui), full dashboard redesign, second supplier.

### C1.3 — Circuit Breaker and Recovery

**In:** persisted supplier health, purchase/reconcile circuits, triggers, auto pause, admin resume + probe gate, alerts, runbook.

**Out:** new adapters, AI runtime.

### C1.4 — Production Acceptance and Hardening

**In:** E2E, UI-change simulation, outages, concurrency/idempotency, artifact retention, runbook, closure review.

**Out:** new product features.

---

## Must decide before C1.1

1. Upgrade `/admin/automation` vs replace — **rec: upgrade**
2. Working-now cards vs table vs hybrid — **rec: hybrid** (table + detail drawer)
3. Customer identifiers on active board — **rec: order number + package only; no player IDs**
4. Screenshot before final submit — **rec: yes, private, retention-bound**
5. Progress storage — **rec: snapshot + bounded events**
6. Heartbeat model — **rec: per-run heartbeat via progress callback; worker `/health` for process liveness**
7. Permissions — **rec: keep admin-only for C1.1; add dedicated perms in C1.3 if needed**
8. Stale purchase threshold — **rec: configurable; default slower than run_seconds but distinct from supplier-wait (reconcile)**

Full decision sheet lives in C1.0 Agent report (chat).

## Can defer past C1.1

- UI adapters / detector / contracts (C1.2)
- Circuit breaker / resume probe gate (C1.3)
- Price-scan circuit
- Sanitized HTML artifacts vs screenshot-only
- Simultaneous old+new UI support depth
- Half-open auto resume complexity
- Enforcing unused `max_concurrent_runs` (useful hardening; not dashboard-blocking)

## Acceptance criteria (architecture)

- [x] Current lifecycle + data model audited
- [x] Dashboard information + working-now contracts defined
- [x] Progress/heartbeat/storage/stale semantics designed
- [x] Worker/session/supplier health models designed
- [x] Adapter/detector/contract/circuit/AI/security/test/deploy strategy designed
- [x] C1.1–C1.4 split + Omar decision sheet
- [ ] Omar decisions recorded (open)
- [ ] C1.1 implementation (not started)

## Open questions

See Omar decision sheet (20 items) in C1.0 report.

## Shipped

Not shipped — architecture only (2026-08-01).

## Gotchas

- Do not derive “current step” from `log_excerpt` free text
- Do not treat `succeeded` run as “done” when `awaiting_wasim_reconcile`
- Do not mark scheduled reconcile wait as worker-stale
- Progress must not mutate fulfillment status
- Vault domain note previously said automation never fails fulfillments — **outdated** (ingest failure + stale sweep call `FailFulfillment`)
- `Dispatched` status mostly unused in persistence
- Worker capability flags on `/health` are hardcoded `true`

## Key files (audit)

- `config/fulfillment_automation.php`, `routes/automation.php`, `routes/channels.php`, `routes/console.php`
- `app/Services/FulfillmentAutomationService.php`
- `app/Actions/Fulfillments/{Reserve,Dispatch,Ingest,ScheduleWasim,Cancel,Retry}*`, `ReserveFulfillmentAutomationReconcileRun`
- `app/Jobs/DispatchFulfillmentAutomationJob.php`, `DispatchWasimReconcileJob`
- `app/Livewire/Admin/AutomationMonitor.php`
- `app/Models/FulfillmentAutomationRun.php`
- `automation-worker/src/server.ts`, `drivers/wasim/*`, `build.ts`
- Tests: `FulfillmentAutomationTest`, `AutomationAdminTest`
