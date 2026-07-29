---
date: 2026-07-29
status: accepted
---

# ADR — Mobile M2.1 Catalog API Contract

## Context

Mobile Commerce Shell needs a stable `/api/v1` catalog contract before Flutter M2.2. Web storefront already owns home shelves, package search, category browsing, and `CustomerPriceService` pricing.

## Decision

- Expose three read endpoints: `/catalog/home`, `/packages`, `/packages/{id}`.
- Reuse `GetCustomerHome` ranking/status rules for frequently ordered; featured = admin `order` then `name` (not sales-ranked).
- Route packages by numeric id; return slug as informational only.
- Exact `category_id` filter; no recursive descendants.
- Money envelope: USD `amount` string + server `display` via `FrontendMoney`.
- Custom products expose metadata + `minimum_price` at `custom_amount_min`; no quote endpoint.
- Package `from_price` uses purchasable totals (fixed qty 1 / custom min), including nonlinear rule brackets.
- `meta.prices_visible` on every catalog response; null money when hidden.
- Safe absolute image URLs or null; never SVG placeholders.
- Named `mobile-catalog` rate limiter; private/no-store responses; no shared priced cache.

## Alternatives considered

- Dedicated product endpoint — deferred; products are package-scoped.
- Cursor pagination — deferred; offset matches current catalog size.
- Client currency conversion — rejected; Laravel owns display conversion.
- Returning SVG placeholder URLs — rejected for Flutter raster loading.

## Consequences

- Flutter may display server prices but must not calculate authoritative totals.
- Query amplification from per-product pricing is bounded by eager loads + memoization + Pest budgets.
- Catalog content remains untranslated until DB i18n exists.

## Related

- [[Mobile M2.0 — Commerce Shell Architecture]]
- [[Mobile M2.1 — Laravel Catalog API]]
