# Ask → Plan → Agent Pipeline

How **ChatGPT**, **Obsidian**, and **Cursor** work together without losing context.

## Roles

| Tool | Role |
|------|------|
| **Obsidian (`Vault/`)** | Durable memory: feature briefs, decisions, gotchas |
| **ChatGPT Plus** | Prompt factory: Ask → Plan prompts (stateless — always attach files) |
| **Cursor** | Executor: reads repo + rules + your prompt |

## Per-feature loop

```text
Feature Brief (Obsidian)
    ↓
ChatGPT Project + SYSTEM_CONTEXT + feature note
    ↓ Ask prompt
Cursor Ask mode → paste findings back to ChatGPT
    ↓ Plan prompt
Refine plan in ChatGPT
    ↓ Agent prompt
Cursor Agent (@files + plan) → implement + test
    ↓
Update feature note: Shipped + Gotchas
```

## Step 1 — Feature Brief (Obsidian)

Duplicate [[Templates/Feature Brief]] into `Features/<Name>.md` before starting.

Minimum fields: **Goal**, **Constraints**, **Non-goals**, **Acceptance criteria**.

## Step 2 — Ask prompt (ChatGPT → Cursor Ask)

ChatGPT outputs a prompt that:

- Names files to read first (from `SYSTEM_CONTEXT_CORE_v1.md` §16)
- Asks what exists today vs what must be built
- Lists risks (financial, auth, N+1, over-engineering)
- Does **not** invent schema or routes

You run it in Cursor **Ask mode**, then paste the answer back to ChatGPT.

## Step 3 — Plan prompt (ChatGPT → Cursor Plan/Agent)

ChatGPT outputs:

- Ordered file list (minimal diff)
- Happy path + edge cases
- Test files to add/update
- Explicit **do not** list (no new packages, no wire:model.live, etc.)

Refine until the plan is small enough for one Agent session.

## Step 4 — Agent (Cursor)

Start every Agent prompt with:

```text
@SYSTEM_CONTEXT_CORE_v1.md
@Vault/Features/<Feature Name>.md
@<key files from plan>

Implement the plan below. Minimal diff. Match existing conventions.
Do not invent DB columns — inspect models/migrations first.
Run focused Pest tests when done.
```

## Step 5 — Close the loop

On the feature note, add:

- **Shipped** — date, key files, tests
- **Gotchas** — things the next session must know
- Links to related [[Templates/Decision Record]] if architecture changed

## Anti-patterns

- Long chat history as the only source of truth
- Starting Agent without `@` context files
- Skipping **Non-goals** (causes over-engineering)
- ChatGPT planning without re-uploading the feature note each thread

## ChatGPT setup

Copy instructions from `Docs/CHATGPT_PROJECT_PROMPT.md` into a ChatGPT **Project**. Pin or upload:

- `SYSTEM_CONTEXT_CORE_v1.md`
- `Docs/PROJECT_STRUCTURE.md`
- Current feature note from this vault
