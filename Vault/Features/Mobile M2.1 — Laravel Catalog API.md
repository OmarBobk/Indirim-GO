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
| GET | `/api/v1/catalog/home` | Frequently ordered ≤8, featured ≤8 (sellable only before limit), categories ≤12 |
| GET | `/api/v1/packages` | `category_id?`, `q?` (2–100, literal LIKE), `page`, `per_page` (default 24, max 50) |
| GET | `/api/v1/packages/{package}` | Active package with ≥1 active product; else 404 `package_not_found` |

All require Sanctum PAT + `mobile:access` + `mobile.account` + `SetApiLocale` + `throttle:mobile-catalog` (60/min user, 120/min IP).

## Pricing semantics

- Ledger `amount` is USD decimal string (2 dp, bankers rounding via existing services).
- `display.{currency,formatted}` from `FrontendMoney::displayForUsdAmount`.
- Fixed: `unit_price` for qty 1; `custom_amount`/`minimum_price` null.
- Custom: `unit_price` null; schema-safe `custom_amount` metadata (bounds &lt; 1 → null); `minimum_price` only when `CustomAmountValidator` accepts the configured min.
- Invalid custom configs never contribute to package `from_price` and never 500.
- Package `from_price` = min purchasable total across active products (fixed qty 1 or valid custom min).
- When `prices_visible=false`, money fields are null; keys remain.

## Category / search

- Exact active `category_id` only (no descendant inclusion).
- Inactive/missing category → 422 validation.
- Search uses parameterized `LIKE … ESCAPE '!'`; `%`, `_`, and `\` are literal.
- Packages must have ≥1 active product (list/home/detail).

## Images

- Absolute http(s) URL or null after bounded decode/normalize.
- Reject traversal (literal/encoded/double-encoded), null bytes, schemes, scheme-relative, malformed `%`, SVG.

## Security

Allowlisted resources only. Never expose entry/supplier/API/fulfillment/rule internals, roles, wallet fields.

## Performance

- Eager-load active products/categories.
- Per catalog request: fresh `PriceCalculator::warmForUser()` + `CustomerPriceService` (tier memo on that instance only); product-total memo on `MobileCatalogPricer`.
- No shared/final-price cache; Octane-safe (no static warmed state).
- Measured budgets (SQLite test env after warm-up): **8×1** home≈14 / list≈8 / detail≈7; **8×5** home≈10–14 / list≈8 / detail≈7. Pest enforces home≤40/45, list≤50/55, detail≤25 with 8×5 growth bounded vs 8×1 (not an unbounded raise).
- `Cache-Control: private, no-store`.

## Deferred (not M2.1)

Telescope may store customer-specific catalog response bodies when enabled — pre-existing ops hardening, not a merge blocker for this PR.

## Key files

- `routes/api.php`
- `app/Actions/MobileCatalog/*`
- `app/Support/Api/V1/*`
- `app/Services/PriceCalculator.php` (`warmForUser`)
- `app/Http/Controllers/Api/V1/Catalog/*`
- `docs/api/v1/openapi.yaml`
- `tests/Feature/Api/MobileCatalogApiTest.php`
- `tests/Unit/SafePublicAssetUrlTest.php`

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
