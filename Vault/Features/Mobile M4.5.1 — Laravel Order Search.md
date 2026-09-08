---
status: draft
created: 2026-09-08
feature: mobile-m4.5.1-laravel-order-search
---

# Mobile M4.5.1 — Laravel Order Search

Authenticated mobile customers can search and filter their complete order history on `GET /api/v1/orders`.

## Goal

Extend the owned-orders list with optional `q` and `customer_state` so Flutter can search all history by order number and item snapshot title, and filter by customer-facing state, without changing privacy, pagination, or Money.

## Constraints

- Stack: Laravel 12 API on `staging`; OpenAPI is authoritative
- Ownership: `user_id` scope first; missing/cross-customer detail stays 404
- Search: literal LIKE (`ESCAPE '!'`), snapshot `order_items.name` only — never live package names or requirement values
- Filter: reuse `CustomerOrderFulfillmentClassifier::applyFilter`; do not expose `other`
- Do not add packages, migrations, or indexes

## Non-goals

- Flutter UI (M4.5.2+)
- Order detail / checkout / recovery changes
- Web `/orders` Livewire search
- `needs_attention` sort priority
- Image/CDN work

## Affected areas

- Routes: `GET /api/v1/orders` (`OrderIndexController`)
- Actions: `ListMobileOrders`
- Requests: `ListOrdersRequest`
- Contract: `docs/api/v1/openapi.yaml` 1.4.0
- Tests: `tests/Feature/Api/MobileOrdersApiTest.php`, `MobileOpenApiContractTest.php`

## Acceptance criteria

- [x] Optional `q` (trim, min 2, max 100; empty omitted) and `customer_state` (`needs_attention|in_progress|delivered|refunded`)
- [x] Literal search of `orders.order_number` and historical `order_items.name` via `whereExists` (no duplicate rows)
- [x] Search and filter compose; default sort remains `created_at DESC, id DESC`
- [x] Response fields, Money envelope, pagination, Cache-Control, throttle, and privacy allowlist unchanged
- [x] Tests: `php artisan test --compact tests/Feature/Api/MobileOrdersApiTest.php` and `tests/Feature/Api`

## Open questions

- None for this slice. Production image/CDN work is out of scope.

## Plan summary

- Validate `q` / `customer_state` on `ListOrdersRequest` (same 422 shape as catalog)
- Apply escaped LIKE + classifier filter in `ListMobileOrders`
- Bump OpenAPI to 1.4.0; keep schemas/examples otherwise intact

## Shipped

- **Date:** 2026-09-08
- **Summary:** Optional `q` and `customer_state` on `GET /api/v1/orders`. Search is literal substring on order number + snapshot item title. Filter reuses `CustomerOrderFulfillmentClassifier::applyFilter`. OpenAPI 1.4.0.
- **Key files:**
  - `app/Http/Requests/Api/V1/ListOrdersRequest.php`
  - `app/Actions/MobileOrders/ListMobileOrders.php`
  - `app/Http/Controllers/Api/V1/Orders/OrderIndexController.php`
  - `docs/api/v1/openapi.yaml`
  - `tests/Feature/Api/MobileOrdersApiTest.php`
  - `tests/Feature/Api/MobileOpenApiContractTest.php`
- **Tests:** `php artisan test --compact tests/Feature/Api/MobileOrdersApiTest.php` and `tests/Feature/Api` (fill results after run)

## Gotchas

- Classifier `normalizeFilter` maps unknown values to `all`; HTTP validation must reject `other` / `all` before the action runs.
- Catalog already uses `ESCAPE '!'` in `ListMobilePackages`; orders copy that convention so SQLite tests and MySQL stay aligned.
- Do not `orWhereHas` without EXISTS — multiple matching items must not duplicate orders.

## Related

- Domain notes: [[Orders & Checkout]]
- Audit: Mobile M4.5.0
