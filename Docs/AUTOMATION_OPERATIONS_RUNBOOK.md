# Automation Operations Runbook (Track C1)

Practical operator guide for Wasim browser automation after C1.1–C1.3.

**Scope:** Laravel owns workflow/circuits; Playwright worker executes browsers only.  
**Do not** put secrets, storageState, or customer identifiers in tickets or chat pastes.

Last acceptance review: **2026-08-06 (local)** — see Vault C1 note for live vs automated status.

---

## Quick reference

| Item | Value |
|---|---|
| Admin UI | `/admin/automation` |
| Worker health | `GET {WORKER_URL}/health` (default local `http://127.0.0.1:3100/health`) |
| Expected worker build | `2026-08-05-c1.2-ui-adapters` |
| Supported UI adapter | `wasim-ui-v1` only |
| Probe command | `php artisan fulfillment:probe-wasim-health` (or admin **Run health probe**) |
| Dispatch | `php artisan fulfillment:dispatch-automation` (scheduled every minute when enabled) |
| Stale sweep | `php artisan fulfillment:sweep-stale-automation-runs` |
| Artifact prune | `php artisan fulfillment:prune-automation-artifacts` (daily 04:00) |
| Price scan stale | `php artisan wasim:sweep-stale-price-scans` |
| Global kill switch | `WebsiteSetting::automation_enabled` (admin toggle on automation page) |
| Circuits | `automation_supplier_circuits` — capabilities `purchase`, `reconcile`, `price_scan` |
| Artifact storage | `storage/app/private/fulfillment-automation/{run_uuid}/` (admin-only route) |
| Retention | `config('fulfillment_automation.artifacts.retention_days')` default **30** |
| Events/run limit | `config('fulfillment_automation.progress.events_per_run_limit')` default **100** |

---

## A. Normal daily checks

1. Open `/admin/automation`.
2. Confirm health cards:
   - Global automation enabled (unless intentionally off)
   - Worker **ready**, browser available, session store available
   - Build = `2026-08-05-c1.2-ui-adapters` (or documented successor)
   - Session / driver cards from latest Wasim probe
   - Purchase / reconcile / price_scan circuits **enabled** (or intentionally paused)
3. Scan boards: Working now, Waiting for supplier, Scheduled reconciliation, Waiting for automation recovery, Needs attention.
4. Clear Needs attention items (needs_review, stale, reconcile exhausted) using existing retry/cancel/manual paths — never invent a second supplier purchase.
5. Optional: run **Run health probe** if session/UI age is stale (> probe freshness, default 30 minutes for resume).
6. Confirm scheduler is running (`schedule:work` / cron) and queue worker listens to `fulfillment-automation` (or configured queue).

---

## B. Unsupported / ambiguous UI

**Symptoms:** probe or run reports `unsupported_ui` / `ambiguous_ui`; purchase circuit `paused_auto`.

**Do:**

1. Do **not** Resume purchase.
2. Confirm no supplier order was created for the blocked run.
3. Open private artifacts (admin only) — screenshot + safe diagnostics.
4. Confirm queued fulfillments remain **Queued** (not Failed, no refund).
5. Capture safe evidence for a **narrow C1.2.x** adapter patch (fixtures only; never invent `wasim-ui-v2` without live DOM proof).
6. After adapter patch: fixture tests → deploy worker → probe must show `wasim-ui-v1` (or newly proven ID) → controlled order → then Resume.

**Do not:** disable detection, loosen submit selectors, force adapter ID, or resume on a failing/stale probe.

---

## C. Session expired / authentication required

1. Verify credentials in admin Wasim credentials form (or env) — never paste passwords into chat.
2. **Clear browser session** (admin action → worker `POST /v1/sessions/clear`).
3. Run health probe.
4. If probe `healthy` + authenticated: resume circuits only if state is `probe_required` / policy requires it.
5. Queued fulfillments must remain queued during this process.

---

## D. Worker down

1. Confirm `GET /health` fails or `ready=false`.
2. New purchase/reconcile dispatch stays gated by health — fulfillments stay queued.
3. Supplier UI circuits should **not** open merely because the worker is down.
4. Restart worker process (pm2/systemd/manual). Example:

```bash
cd automation-worker
npm ci
npm run build   # expect: Build OK: 2026-08-05-c1.2-ui-adapters
# restart process (pm2 restart <name> | systemctl restart …)
curl -s http://127.0.0.1:3100/health
```

5. Confirm new `instance_id`, build string, `browser_available`, `session_store_available`.
6. Dispatch should auto-resume when ready — no manual UI-safety resume required for pure worker outage.
7. Check stale runs / Needs attention after prolonged outage.

---

## E. Submitted order stuck (awaiting reconcile)

1. Preserve `supplier_order_id` / external id in fulfillment meta — **never place a second purchase**.
2. Confirm reconcile circuit is enabled.
3. Inspect reconcile history on `/admin/automation` and run detail.
4. If reconcile UI is broken → circuit may pause; orders stay processing/awaiting — no auto-refund from pause alone.
5. After recovery: probe → resume reconcile if needed → normal schedule (`ScheduleWasimOrderReconcile` path).
6. If exhausted / needs_review: manual review using existing admin tools.

---

## F. Circuit paused

| State | Meaning | Next step |
|---|---|---|
| `paused_auto` | Typed safety / threshold | Fix cause → healthy probe → becomes `probe_required` → Resume |
| `paused_manual` | Admin pause | Manual Resume (probe if reason requires) |
| `probe_required` | Technical recovery proven | Admin Resume after confirming UI/contract versions |

Always check: reason code, opened source, last probe state, supported UI (`wasim-ui-v1`), purchase/reconcile independence.

Pause stops **new dispatch only**. It does not cancel submitted supplier orders or refund customers.

---

## G. Rollback

### Prefer (safer)

1. Pause **purchase** circuit (and global kill switch if needed).
2. Keep **reconcile** enabled if the running worker still understands orders contract.
3. Deploy previous known-good Laravel release **and/or** previous known-good worker build.
4. Restart queues / Reverb / worker.
5. Clear session if credential/session format incompatible.
6. Run probe; controlled resume.

### Worker rollback

- Never roll back to a worker that can bypass current UI contracts while purchase is enabled.
- Pause purchase first → deploy old worker → restart → verify `/health` build → probe → resume only if contracts still pass.

### Migrations

- C1 circuit/progress migrations are additive. Prefer app rollback **without** destructive `migrate:rollback` unless ops policy requires it and a backup exists.

---

## H. Artifact / privacy incident

1. Restrict access (admin role only; private disk — not public URL).
2. Preserve copies for investigation if needed.
3. Delete exposed artifact files + clear paths from run meta via prune/manual ops.
4. Rotate Wasim credentials if screenshots/logs may have leaked them; clear session.
5. Audit who accessed `/admin/automation` / artifact routes.
6. If pre-submit screenshots show player IDs, ship the narrowest crop/mask fix before re-enabling broad purchase.

---

## Pre-purchase acceptance gate (mandatory)

Do **not** run a controlled live purchase unless all are true:

- [ ] Worker ready; build matches expected
- [ ] Wasim session authenticated (probe)
- [ ] Detected UI = supported adapter (`wasim-ui-v1`)
- [ ] Purchase contract healthy
- [ ] Reconcile contract healthy (or explicit approved limitation)
- [ ] Purchase circuit `enabled`
- [ ] Probe recent (within freshness window)
- [ ] Probe product configured (`FULFILLMENT_AUTOMATION_WASIM_PROBE_PRODUCT_API`)
- [ ] Test order explicitly prepared (low-cost, known product, controlled user)

If any fail: stop. Collect safe diagnostics. Do not force resume or broaden selectors.

---

## Deploy checklist (high level)

1. Clean worktree on intended branch (do not deploy dirty C1.2/C1.3 trees).
2. Backup DB per ops policy.
3. Laravel: dependencies → migrate → `config:cache` / `route:cache` / `view:cache` as used in prod → `npm run build` → restart queue + Reverb + scheduler.
4. Worker: `npm ci` → `npm run build` → restart process → verify `/health`.
5. Smoke: app loads, `/admin/automation` loads, circuits exist, no unexpected auto-pause, probe.
6. Only then: controlled purchase path.

---

## Useful Artisan commands

```bash
php artisan fulfillment:dispatch-automation
php artisan fulfillment:sweep-stale-automation-runs
php artisan fulfillment:probe-wasim-health
php artisan fulfillment:prune-automation-artifacts
php artisan wasim:scan-prices
php artisan wasim:sweep-stale-price-scans
```

Worker tests (pre-deploy):

```bash
cd automation-worker
npm run build
npm run test:ui-adapter
npm run test:progress
npm run test:parse-swal
npm run test:orders-date
npm run test:parse-money
```

---

## Config keys (no secrets)

- `FULFILLMENT_AUTOMATION_ENABLED`
- `FULFILLMENT_AUTOMATION_WORKER_URL`
- `FULFILLMENT_AUTOMATION_CALLBACK_SECRET` (must match worker)
- `FULFILLMENT_AUTOMATION_WASIM_PROBE_PRODUCT_API`
- `FULFILLMENT_AUTOMATION_WASIM_PROBE_EXPECTED_PRODUCT_ID`
- `FULFILLMENT_AUTOMATION_WASIM_PROBE_EXPECTED_CURRENCY`
- `FULFILLMENT_AUTOMATION_CIRCUIT_*` thresholds / probe freshness
- `FULFILLMENT_AUTOMATION_ARTIFACT_RETENTION_DAYS`

Wasim username/password: prefer admin encrypted settings over env when possible.
