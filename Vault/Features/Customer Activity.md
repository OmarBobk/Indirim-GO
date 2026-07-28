---
status: in-progress
created: 2026-07-28
feature: customer-activity
---

# Customer Activity

Unified customer **activity feed** replacing the standalone notifications page. See [[Karman Index]].

## Goal

Customers see one **Activity** page: timeline of orders, wallet, topups, refunds, and action-required items, with filters and deep links to the right storefront/backend destinations.

## Constraints

- Read-model pattern: `GetCustomerActivity` + DTOs + presenters — no fat Livewire
- Merge notification-derived items with domain readers (orders, topups, refunds)
- Flux FREE components; match existing storefront shell
- Route replaces `/notifications` → activity route (verify `routes/web.php`)
- i18n: `lang/en/messages.php`, `lang/ar/messages.php`
- Tests required: `CustomerActivityPageTest`, `CustomerActivityReadModelTest`, etc.

## Non-goals

- Admin activity log redesign
- Real-time push changes (reuse existing notification bell if separate)
- New database tables for activity (derive from existing sources)

## Affected areas

- `app/Actions/Activity/GetCustomerActivity.php`
- `app/DTOs/CustomerActivity*.php`
- `app/Support/Activity/*` (merger, mappers, readers)
- `app/Support/CustomerActivityPresenter.php`
- `resources/views/pages/frontend/⚡activity.blade.php`
- `resources/views/components/activity/*`
- `routes/web.php`
- `tests/Feature/CustomerActivity*.php`

## Acceptance criteria

- [ ] Authenticated customer can open activity page
- [ ] Action-required section surfaces pending topup/refund/order items
- [ ] Filters work without excessive Livewire chatter
- [ ] Old notifications route redirects or is removed cleanly
- [ ] `php artisan test --compact --filter=CustomerActivity` passes

## Open questions

- Should notification bell dropdown share the same read-model?

## Ask mode findings

<!-- Paste Cursor Ask output here -->

## Plan summary

<!-- Fill after ChatGPT Plan phase -->

## Shipped

<!-- Fill after done -->

## Gotchas

<!-- Fill after implementation -->

## Related

- [[Wallet & Ledger]]
- [[Refunds & Settlements]]
- [[Orders & Checkout]]
