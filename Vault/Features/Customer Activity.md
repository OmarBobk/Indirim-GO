---
status: shipped
created: 2026-07-28
feature: customer-activity
closed: 2026-07-29
---

# Customer Activity

Unified customer **activity feed** replacing the standalone notifications page. See [[İndirimGo Index]].

## Goal

Customers see one **Activity** page: timeline of orders, wallet, topups, refunds, and action-required items, with filters and deep links. Home keeps wallet chrome in the mobile top bar; Needs attention is **not** on Home (deferred).

## Constraints

- Read-model: `GetCustomerActivity` + DTOs + presenters — no fat Livewire
- Notifications = unread/delivery truth; domain records = ops/financial truth; Activity = projection
- Canonical `/activity`; `/notifications` compatibility alias
- Flux FREE; storefront shell conventions
- i18n EN/AR

## Non-goals

- Admin activity log redesign
- Activity projection table / M6 preferences / Flutter API

## Acceptance criteria

- [x] Activity page + action-required readers
- [x] Realtime invalidation (M5.4)
- [x] Performance hardening (M5.4.1)
- [ ] Home Operational island (rolled back — wallet chrome restored on mobile top bar; placeholder Operational zone)
- [x] M5 closure (separate commits + docs)

## Open / deferred

- Home Needs attention island → deferred (rolled back from Home; Action Required stays on `/activity`)
- Home CTA mark twin read → M6
- Bell full read-model → defer (latest-five OK)
- Queued broadcasts / cursor pagination → before Flutter scale

## Shipped

- **M5.4** `fbcf087` — realtime sync
- **M5.4.1** `f6874f4` — query budgets, WebsiteSetting memo, bell lazy, fulfillments indexes
- **M5.5** `b8b481b` — Home Needs attention island (**rolled back** 2026-07-29: removed from Home; restored mobile wallet chrome)
- **Docs** — `SYSTEM_CONTEXT_CORE_v1.md` §11

## Gotchas

- Banner / mark-all always in DOM (`x-show`)
- Mobile top bar: wallet chrome + realtime bell badge
- Home Operational placeholder remains hidden (`customer-home-operational-placeholder`)
- Deploy: migrate indexes; Reverb origins; secure cookies; `npm run build`
- **M6.4:** rejected refund action-required destination prefers `WalletRefund` + `public_ref` → `/wallet/refunds/{WTX-*}` (falls back to order)

## Related

- [[Customer Financial Centre]] — M6 financial truth stays out of Activity; M6.4 refund detail destinations
- [[Wallet & Ledger]]
- [[Refunds & Settlements]]
- [[Orders & Checkout]]
