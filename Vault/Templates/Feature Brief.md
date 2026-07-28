---
status: draft
created: {{date}}
feature: 
---

# {{title}}

> Duplicate this note into `Features/` before starting. Link from [[Karman Index]].

## Goal

One sentence: what the user/admin should be able to do when this ships.

## Constraints

- Stack: Laravel 12, Livewire 4, Tailwind 4, Flux **FREE** only
- Financial writes: transactional, idempotent, `lockForUpdate`, `DB::afterCommit` for side effects
- Backend denial: 404 by design (not 403)
- Performance: avoid `wire:model.live` on high-frequency inputs; thin Livewire, logic in Actions
- Do not add packages without approval

## Non-goals

What we are **not** doing in this slice (prevents scope creep).

- 

## Affected areas

Files or domains to inspect first (check `SYSTEM_CONTEXT_CORE_v1.md` §16):

- Routes:
- Actions:
- Livewire / views:
- Tests:

## Acceptance criteria

- [ ] 
- [ ] 
- [ ] Tests: `php artisan test --compact tests/Feature/...`

## Open questions

- 

## Ask mode findings

Paste Cursor Ask results here (or link [[Templates/Session Handoff]]).

## Plan summary

Bullets only — full plan lives in ChatGPT thread or Agent prompt.

- 

## Shipped

<!-- Fill after merge / deploy -->

- **Date:**
- **Key files:**
- **Tests:**

## Gotchas

<!-- What the next AI session must know -->

- 

## Related

- Domain notes: 
- Decisions: 
