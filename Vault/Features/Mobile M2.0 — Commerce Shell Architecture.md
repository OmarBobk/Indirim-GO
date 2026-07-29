---
status: accepted
created: 2026-07-29
feature: mobile-m2-0-commerce-shell-architecture
---

# Mobile M2.0 — Commerce Shell Architecture

Architecture and contract decisions for the authenticated Arabic-first Mobile Commerce Shell. Implementation starts with [[Mobile M2.1 — Laravel Catalog API]].

## Goal

Customers discover available packages and understand product options/prices inside a package. Laravel remains authoritative for visibility, pricing, localization messages, and media URLs.

## Accepted M2.1 surface

- `GET /api/v1/catalog/home`
- `GET /api/v1/packages`
- `GET /api/v1/packages/{package}`

## Scope included

- Authenticated home shelves (frequently ordered, featured admin-order packages, category chips)
- Package list with exact `category_id`, optional search, offset pagination
- Package detail with nested active products
- Server-calculated final prices (`Money` + `MoneyDisplay`)
- `meta.prices_visible`

## Explicit exclusions

Cart, buy now, checkout, wallet, orders, fulfillments, refunds, notifications, Customer Activity UI, realtime catalog, custom quote API, package requirements, Flutter implementation (M2.2), production deploy, `staging`→`main`.

## Contract authority

- OpenAPI: `docs/api/v1/openapi.yaml`
- Decision record: [[Mobile M2.1 Catalog API Contract]]

## Successors

- [[Mobile M2.1 — Laravel Catalog API]]
- Flutter M2.2 consumes the merged OpenAPI only after M2.1 acceptance
