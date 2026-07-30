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

- [[Customer Financial Centre]] — M6 Wallet → Financial Control Centre (architecture M6.0)
- [[Customer Activity]] — M5 Activity feed
- [[Customer Activity]] — unified activity feed (shipped; Home Needs attention deferred)

## Mobile

- [[Mobile M1.1 — Laravel API Foundation and Authentication]] — Laravel `staging` customer auth foundation
- [[Mobile M1.1 Authentication Architecture]] — accepted auth and API-contract decisions
- [[Mobile M1.2 — Flutter Foundation and Authentication]] — mobile `main` Flutter auth foundation (`1b26478…`, PR #1 merged)
- [[Mobile M1.2 Flutter Authentication Architecture]] — Riverpod/go_router/Dio/secure-storage decisions
- [[Mobile M1.3 — Local Integration and Closeout]] — local emulator integration + auth/Reverb isolation (`99b6427`, PR #39 accepted)
- [[Mobile M2.0 — Commerce Shell Architecture]] — accepted M2 scope and exclusions
- [[Mobile M2.1 Catalog API Contract]] — accepted catalog/pricing/OpenAPI decisions
- [[Mobile M2.1 — Laravel Catalog API]] — catalog read API implementation (in review)
- After M2.1 acceptance: Flutter M2.2 Commerce Shell (do not start before merged OpenAPI on `staging`)

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
