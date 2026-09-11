---
status: shipped
created: 2026-09-11
feature: Buy Now package overlay remount race
---

# Buy Now Package Overlay Remount

## Goal

Logged-in users who open a package then hit Buy now should land on the checkout form on the first click — not bounce back to the package product list.

## Constraints

- Stack: Laravel 12, Livewire 4, Tailwind 4, Flux **FREE** only
- No new packages
- Minimal UI change: structural Alpine/Livewire host split only

## Non-goals

- Redesigning package overlay / buy-now UX
- Order details summary RTL layout (separate bug)

## Affected areas

- Livewire / views: `resources/views/components/main/⚡buy-now-modal.blade.php`
- Tests: `tests/Feature/BuyNowModalTest.php`

## Acceptance criteria

- [x] First Buy now click from package overlay stays on buy-now form
- [x] Package deep-link `?package=` still opens overlay once on page load
- [x] Opening package via click sets `__karmanPackageDeepLinkDone` so remounts cannot re-dispatch
- [x] Tests: `php artisan test --compact tests/Feature/BuyNowModalTest.php`

## Open questions

- None

## Shipped

- **2026-09-11** — Moved deep-link `x-init` + window listeners to stable `data-test="buy-now-overlay-host"` parent; kept `wire:key` remount only on inner buy-now shell. Regression source-order test added.

### Key files

- `resources/views/components/main/⚡buy-now-modal.blade.php`
- `tests/Feature/BuyNowModalTest.php`

### Gotchas

- Do **not** put `__karmanPackageDeepLinkDone` / `open-package-overlay` listeners on the same node as `wire:key="buy-now-shell-…"`. Remounting that key re-runs Alpine `x-init` while `?package=` is still in the URL and re-opens the package list.
- Click-open path must set `__karmanPackageDeepLinkDone = true` when writing the URL param (defense in depth).
