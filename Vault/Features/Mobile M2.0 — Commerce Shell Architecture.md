---
status: accepted
created: 2026-07-29
updated: 2026-07-31
feature: mobile-m2-0-commerce-shell-architecture
---

# Mobile M2.0 — Commerce Shell Architecture

Architecture and contract decisions for the authenticated Arabic-first Mobile
Commerce Shell. Implementation delivered as [[Mobile M2.1 — Laravel Catalog API]]
+ [[Mobile M2.2 — Flutter Commerce Shell]] + Omar-accepted
[[Mobile M2.3 — Local Commerce Integration]].

## Goal

Customers discover available packages and understand product options/prices
inside a package. Laravel remains authoritative for visibility, pricing,
localization messages, and media URLs.

## Milestone status (closed)

| Slice | Status | Evidence |
| --- | --- | --- |
| M2.1 Laravel Catalog API | Accepted on `staging` | `485be1befcf99f9d4a337745ec0b4390529c79e1` (PR #40) |
| M2.2 Flutter Commerce Shell | Accepted on mobile `main` | `c2119116239a720638c16a0b113be34f36698a78` (PR #4) |
| M2.3 Local Android integration | Accepted by Omar | Local API `http://10.0.2.2:8000/api/v1` |

**Mobile M2 Commerce Shell is closed** after Omar’s M2.3 acceptance. No
production deployment and no Laravel `staging`→`main` merge are part of M2
closeout.

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
- Flutter discovery UI (Home, browse/search/filter, detail, Account)
- Arabic/English, RTL/LTR, accessibility-oriented structure
- Authoritative session rejection + session isolation on the Flutter client

## Explicit exclusions

Cart, buy now, checkout, wallet, orders, fulfillments, refunds, notifications,
Customer Activity UI, realtime catalog, custom quote API, package requirements,
client price calculations, production deploy, `staging`→`main`.

## Contract authority

- OpenAPI: `docs/api/v1/openapi.yaml`
- Decision record: [[Mobile M2.1 Catalog API Contract]]

## Next (not M2)

Purchasing / cart / checkout architecture discovery is a **separate** candidate
after this documentation closeout is accepted. Do not treat it as an open M2
task.

## Related

- [[Mobile M2.1 — Laravel Catalog API]]
- [[Mobile M2.1 Catalog API Contract]]
- [[Mobile M2.2 — Flutter Commerce Shell]]
- [[Mobile M2.3 — Local Commerce Integration]]
