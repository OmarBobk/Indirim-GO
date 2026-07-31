---
date: 2026-07-29
status: accepted
---

# ADR — Mobile M2.1 Catalog API Contract

## Context

Mobile Commerce Shell needs a stable `/api/v1` catalog contract before Flutter M2.2. Web storefront already owns home shelves, package search, category browsing, and `CustomerPriceService` pricing.

## Decision

- Expose three read endpoints: `/catalog/home`, `/packages`, `/packages/{id}`.
- Reuse `GetCustomerHome` ranking/status rules for frequently ordered; featured = admin `order` then `name` among packages that have ≥1 active product (limit applied after that filter).
- Route packages by numeric id; return slug as informational only.
- Exact `category_id` filter; no recursive descendants.
- Search `q` is literal substring (`LIKE` with `ESCAPE '!'`); `%` `_` `\` are not wildcards.
- Money envelope: USD `amount` string + server `display` via `FrontendMoney`.
- Custom products expose schema-safe metadata + `minimum_price` only when `CustomAmountValidator` accepts `custom_amount_min`; invalid configs excluded from `from_price`.
- Active packages with zero active products are omitted from list/home and return `package_not_found` on detail.
- Package `from_price` uses purchasable totals (fixed qty 1 / valid custom min), including nonlinear rule brackets.
- `meta.prices_visible` on every catalog response; null money when hidden.
- Safe absolute image URLs or null after bounded URL decode/normalize; never SVG placeholders.
- Named `mobile-catalog` rate limiter; private/no-store responses; no shared priced cache.
- Catalog pricing warms global + per-user rules on a request-local `PriceCalculator` and memoizes loyalty on a request-local `CustomerPriceService` (not the container singleton).

## Alternatives considered

- Dedicated product endpoint — deferred; products are package-scoped.
- Cursor pagination — deferred; offset matches current catalog size.
- Client currency conversion — rejected; Laravel owns display conversion.
- Returning SVG placeholder URLs — rejected for Flutter raster loading.

## Consequences

- Flutter may display server prices but must not calculate authoritative totals.
- Query amplification from per-product pricing is bounded by rule/loyalty warm-up + product memo + Pest budgets (8×1 and 8×5).
- Catalog content remains untranslated until DB i18n exists.
- Telescope may still record priced API bodies when enabled (deferred ops hardening).
- Flutter M2.2 shipped against this contract on mobile `main`
  (`c2119116239a720638c16a0b113be34f36698a78`); local M2.3 accepted by Omar.
- Mobile M2 Commerce Shell is closed; purchasing is a later architecture candidate.

## Related

- [[Mobile M2.0 — Commerce Shell Architecture]]
- [[Mobile M2.1 — Laravel Catalog API]]
- [[Mobile M2.2 — Flutter Commerce Shell]]
- [[Mobile M2.3 — Local Commerce Integration]]
