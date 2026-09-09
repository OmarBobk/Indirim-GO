---
status: shipped
created: 2026-08-01
updated: 2026-09-09
owner: Omar
type: feature
milestone: C1.4B
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

## Architecture status (2026-09-09)

**C1.0–C1.3 code** on `local/track-c1` (HEAD `0cd86fc` + uncommitted C1.2.1/C1.4A/C1.4B).  
**C1.4A controlled purchase PASSED.**  
**C1.4B: READY FOR PRODUCTION RELEASE** (deploy itself not authorized here).

### C1.4B final closure (2026-09-09)

- Refund tx `217` approved via normal `ApproveRefundRequest` (admin id 1): posted once, amount `2.66`, wallet 6 balance `2991.44` matches posted ledger, idempotency `refund:fulfillment:169`; order `ORD-2026-000121` → `refunded`; fulfillment `169` remains `failed`
- Reverb isolation: `FulfillmentListBroadcaster` + hardened `AdminOpsBroadcaster` + notification try/catch on Fail/Complete; `AutomationBroadcastIsolationTest` 8 green
- Restored admin clawback routes accidentally dropped by wallet commit `0cd86fc` (sidebar was 500ing `/admin/automation`)
- Privacy: player + balance chrome mask; worker build `2026-09-09-c1.4b-artifact-privacy`; historical controlled PNGs deleted (decision A); `private-evidence/` gitignored
- Prune dry-run OK (30-day); MySQL concurrency harness 6/6 on `karman_store_concurrency`
- Reverb process up; `AutomationRunChanged` broadcast OK; `private-admin.automation` auth HTTP 200
- Residual: end-of-session Wasim probes `unreachable` (worker `/health` OK); logged-in Echo UI eyeball still needs Omar session; production deploy not done

### C1.4A controlled acceptance (2026-09-08)

- Order `ORD-2026-000121` / fulfillment `169` / purchase run `adeb7830-…` / supplier order `36397`
- Submit-once; reconcile New→Cancelled; one refund path; circuits stayed enabled

### C1.2.1 shipped (2026-09-07)

- Tab-aware orders tables; prior build `2026-09-07-c1.2.1-orders-compat`

## Acceptance criteria

- [x] C1.0–C1.3 implementation (code)
- [x] Live controlled purchase + reconcile + refund posted
- [x] Reverb outage cannot break authoritative automation
- [x] Artifact privacy for future captures + historical decision A
- [x] MySQL concurrency harness + focused regression
- [ ] Production/staging deploy + commit packaging of dirty C1.2.1/C1.4 tree
- [x] C1 closed as **READY FOR PRODUCTION RELEASE**

## Gotchas

- Only `wasim-ui-v1` proven — never invent v2 to pass acceptance
- Optional realtime must use afterCommit + try/catch broadcasters — never throw from StartFulfillment
- Wallet commit `0cd86fc` removed clawback admin routes while leaving sidebar links — restore before admin UI acceptance
- Do not merge Track B again during C1; clawback behavior already ancestral on this branch
- Worker restart required to pick up privacy build on `/health`

## Next milestone (after C1 production release)

Production release ops only — do not start C2 unless Omar authorizes.
