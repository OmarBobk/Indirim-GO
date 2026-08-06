---
status: shipped-partial
created: 2026-08-01
updated: 2026-08-06
owner: Omar
type: feature
milestone: C1.4
---

# C1 — Automation Reliability and Supplier UI Resilience

Related: [[Fulfillments & Automation]], [[Future Roadmap - Automation and Growth]], [[İndirimGo Index]], `Docs/AUTOMATION_OPERATIONS_RUNBOOK.md`

## Goal

Give admins a live Automation Operations Dashboard, structured run progress/heartbeats, Wasim UI adapters/contracts/health probes, and supplier-capability circuit breakers — without moving business truth into the worker — then **accept live** in C1.4.

## Constraints

- Stack: Laravel 12, Livewire 4, Tailwind 4, Flux **FREE** only
- Laravel owns business/workflow state; worker executes browser only
- HMAC callbacks; progress never mutates fulfillment/financial finality
- Admin-only under existing admin gate
- No AI-generated click paths / runtime LLM selector repair

## Architecture status (2026-08-06)

**C1.0–C1.3 code shipped** (C1.1 committed; C1.2/C1.3 present on `local/track-c1` worktree — **must be committed before any deploy**).  
**C1.4 acceptance: NOT CLOSED** (2026-08-06 local review).

### C1.4 blockers (honest)

- Worktree dirty: C1.2 + C1.3 uncommitted → do not deploy
- Environment: `APP_ENV=local` — not production
- Worker `http://127.0.0.1:3100` **unreachable** during review
- Probe product `FULFILLMENT_AUTOMATION_WASIM_PROBE_PRODUCT_API` **empty**
- No production deploy / restart performed
- No live Wasim probe / controlled purchase performed

### C1.4 verified (automated / local)

- Laravel suites: circuits, probe, ops dashboard, progress, fulfillment automation, price-scan, prune/stale filters — green
- Worker: `tsc` build `2026-08-05-c1.2-ui-adapters`; ui-adapter / progress / swal / orders / parse-money selfchecks — green
- Pint + `npm run build` (Laravel frontend) — green
- Migrations present locally: progress fields + `automation_supplier_circuits` (ran on local DB)
- Runbook written: `Docs/AUTOMATION_OPERATIONS_RUNBOOK.md`

### Closure verdict

**C — Not closed.** Safety controls exist in code/tests; live production acceptance criteria not met.

### Next to close C1

1. Commit C1.2+C1.3 on clean branch (no unrelated merges)
2. Configure probe product API
3. Deploy Laravel + restart worker; verify `/health` build
4. Live probe → UI classification
5. Controlled purchase + reconcile only if gates pass
6. Failure sims on safe environment; privacy/pruning sign-off
7. Re-run this checklist → verdict A or B

---

## C1.3 shipped (code)

See prior section: three Wasim circuits, Laravel policy, dispatch gating, probe-gated resume, dashboard controls.

## C1.2 shipped (code)

`wasim-ui-v1` only; HMAC probe; fail-closed unknown UI.

## C1.1 shipped (committed `2529ef9`)

Ops dashboard + structured progress/heartbeat.

## Acceptance criteria

- [x] C1.0–C1.3 implementation (code)
- [x] Pre-deploy automated gates (local 2026-08-06)
- [x] Operator runbook + rollback written
- [ ] Clean deployable commit of C1.2+C1.3
- [ ] Production/staging deploy + worker restart
- [ ] Live probe with configured product
- [ ] Live UI = `wasim-ui-v1` (or safe Branch B pause)
- [ ] Controlled purchase + reconcile
- [ ] Live failure simulations as feasible
- [ ] Artifact privacy sign-off on live screenshots
- [ ] C1 closed (A or B)

## Gotchas

- Only `wasim-ui-v1` proven — never invent v2 to pass acceptance
- Do not deploy dirty worktree
- Empty probe product → product slice `not_configured`; do not treat as full health for purchase gate
- Opening a circuit ≠ fail/refund/cancel submitted orders
- Track B (`origin/local/commission-policy`) is separate — do not merge solely for C1.4

## Recommended next (after C1 close)

- If Branch B (unsupported UI): narrow **C1.2.x** compatibility patch only
- Else: ops soak, then choose C2 / Track D / mobile by business pressure — **not** auto-start C2
