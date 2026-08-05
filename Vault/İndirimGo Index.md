# İndirimGo Index

Central map for indirimGo knowledge. Use this vault with [[Ask → Plan → Agent Pipeline]] and the repo context files.

## Context files (paste or @ in Cursor / upload to ChatGPT)

| File | Purpose |
|------|---------|
| `SYSTEM_CONTEXT_CORE_v1.md` | Invariants, stack, routes, primary source files |
| `Docs/PROJECT_STRUCTURE.md` | Full project map |
| `.cursor/rules/laravel-boost.mdc` | Stack versions + conventions (auto-loaded in Cursor) |
| `Docs/CHATGPT_PROJECT_PROMPT.md` | ChatGPT Project instructions (copy-paste) |
| `.cursor/rules/050-vault-sync.mdc` | Cursor agents: sync work to vault after each task |

## Domains

- [[Wallet & Ledger]] — balance truth, topups, credit facility, spend policy
- [[Orders & Checkout]] — cart, buy-now, pay with wallet
- [[Fulfillments & Automation]] — supplier worker, retries, delivered payload
- [[Refunds & Settlements]] — refund requests, commission payouts
- [[Customer Activity]] — unified activity feed (shipped M5)

## Features

- [[Customer Financial Centre]] — M6 closed (M6.8); Financial Control Centre shipped
- [[Customer Activity]] — M5 Activity feed (shipped; Home Needs attention deferred)
- [[M7 — Financial Risk and Admin Operations]] — Track B closed (M7.0–M7.2.4)
- [[C1 — Automation Reliability and Supplier UI Resilience]] — Track C; **C1.1 shipped** (2026-08-05); C1.2 next

## Roadmap

- [[Future Roadmap - Automation and Growth]] — Track C **active** (C1.1 shipped → C1.2); Track D backlog; Track B closed
- Track B complete through **M7.2.4 Historical Commission Exposure**

## Mobile

- [[Mobile M1.1 — Laravel API Foundation and Authentication]] — Laravel `staging` customer auth foundation
- [[Mobile M1.1 Authentication Architecture]] — accepted auth and API-contract decisions
- [[Mobile M1.2 — Flutter Foundation and Authentication]] — mobile `main` Flutter auth foundation (`1b26478…`, PR #1 merged)
- [[Mobile M1.2 Flutter Authentication Architecture]] — Riverpod/go_router/Dio/secure-storage decisions
- [[Mobile M1.3 — Local Integration and Closeout]] — local emulator integration + auth/Reverb isolation (`99b6427`, PR #39 accepted)
- [[Mobile M2.0 — Commerce Shell Architecture]] — accepted M2 scope and exclusions (**M2 closed** after M2.3)
- [[Mobile M2.1 Catalog API Contract]] — accepted catalog/pricing/OpenAPI decisions
- [[Mobile M2.1 — Laravel Catalog API]] — catalog read API on `staging` (`485be1befcf99f9d4a337745ec0b4390529c79e1`, PR #40)
- [[Mobile M2.2 — Flutter Commerce Shell]] — Flutter discovery UI on mobile `main` (`c2119116239a720638c16a0b113be34f36698a78`, PR #4)
- [[Mobile M2.3 — Local Commerce Integration]] — Omar-accepted local Android verification (`http://10.0.2.2:8000/api/v1`)
- [[Mobile M3.0 — Purchasing Architecture]] — accepted M3 scope (buy-now / single-line)
- [[Mobile M3.1 Purchase API Contract]] — proposed OpenAPI 1.2.0 purchase contract
- [[Mobile M3.1 — Laravel Purchase API]] — in review on `feature/mobile-m3.1-purchase-api`
- Next after Omar accepts M3.1: M3.1R independent review, then Flutter M3.2 (not started)

## Workflow

1. Create a note from [[Templates/Feature Brief]] (or duplicate an existing feature note).
2. Upload context + feature note to ChatGPT → get Ask prompt, then Plan prompt.
3. Run in Cursor with `@SYSTEM_CONTEXT_CORE_v1.md` + feature note + plan.
4. After shipping, fill **Shipped** + **Gotchas** on the feature note.
5. Global rules discovered? Update `SYSTEM_CONTEXT_CORE_v1.md`.

## Templates

- [[Templates/Feature Brief]]
- [[Templates/Session Handoff]]
- [[Templates/Decision Record]]

## Inbox

Scratch captures to process weekly. Delete or promote to a feature / decision note.

- 
