---
status: in-review
created: 2026-07-29
feature: mobile-m2-1-catalog-api
---

# Mobile M2.1 — Laravel Catalog API

Customer-only read catalog API and authoritative OpenAPI updates for the Mobile Commerce Shell.

## Goal

Flutter M2.2 can load authenticated home shelves, browse/search packages, and inspect package product options with server-authored final prices.

## Endpoints

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/catalog/home` | Frequently ordered ≤8, featured ≤8, categories ≤12 |
| GET | `/api/v1/packages` | `category_id?`, `q?` (2–100), `page`, `per_page` (default 24, max 50) |
| GET | `/api/v1/packages/{package}` | Active package + active products; 404 `package_not_found` |

All require Sanctum PAT + `mobile:access` + `mobile.account` + `SetApiLocale` + `throttle:mobile-catalog` (60/min user, 120/min IP).

## Pricing semantics

- Ledger `amount` is USD decimal string (2 dp, bankers rounding via existing services).
- `display.{currency,formatted}` from `FrontendMoney::displayForUsdAmount`.
- Fixed: `unit_price` for qty 1; `custom_amount`/`minimum_price` null.
- Custom: `unit_price` null; `custom_amount` metadata; `minimum_price` = total at `custom_amount_min` (never amount 1 unless min is 1).
- Package `from_price` = min purchasable total across active products (fixed qty 1 or custom min).
- When `prices_visible=false`, money fields are null; keys remain.

## Category / search

- Exact active `category_id` only (no descendant inclusion).
- Inactive/missing category → 422 validation.
- Search mirrors storefront name/description LIKE; packages must have ≥1 active product.

## Images

- Absolute http(s) URL or null.
- Reject traversal, schemes, null bytes, `.svg` (no SVG placeholder URLs).

## Security

Allowlisted resources only. Never expose entry/supplier/API/fulfillment/rule internals, roles, wallet fields.

## Performance

Eager-load active products/categories; request-memoize identical price calls. Query-budget tests (8 packages × 1 product fixture): home ≤40, list ≤50, detail ≤25. No shared public cache (`Cache-Control: private, no-store`).

## Key files

- `routes/api.php`
- `app/Actions/MobileCatalog/*`
- `app/Support/Api/V1/*`
- `app/Http/Controllers/Api/V1/Catalog/*`
- `docs/api/v1/openapi.yaml`
- `tests/Feature/Api/MobileCatalogApiTest.php`

## Exclusions

Flutter, cart/checkout/wallet/orders, custom quote API, package requirements, production deploy, `staging`→`main`.

## M2.2 prerequisites

- Merged OpenAPI on Laravel `staging`
- Emulator base URL still local (`10.0.2.2`)
- Mobile docs correction: stale M1.2 note remains in read-only mobile repo until M2.2

## Related

- [[Mobile M2.0 — Commerce Shell Architecture]]
- [[Mobile M2.1 Catalog API Contract]]
- [[Mobile M1.3 — Local Integration and Closeout]]
