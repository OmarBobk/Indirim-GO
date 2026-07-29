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
