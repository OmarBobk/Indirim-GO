---
status: accepted
created: 2026-07-30
updated: 2026-07-31
feature: mobile-m2-2-flutter-commerce-shell
pull_request: https://github.com/OmarBobk/indirimGo-mobile/pull/4
---

# Mobile M2.2 — Flutter Commerce Shell

Authenticated Flutter catalog discovery for customers. Consumes the Laravel M2.1
OpenAPI contract only. No purchasing.

## Position

- **Accepted / merged** on mobile `main` as squash commit
  `c2119116239a720638c16a0b113be34f36698a78` (PR #4, merged 2026-07-30).
- Squash includes the authoritative session-rejection correction (pre-merge tip
  `2373fd5b059d34b9af9d319726c4d5577d88587d`; CI `verify` success on that HEAD,
  GitHub Actions run `30566851816`).
- Contract pairing: Laravel M2.1 OpenAPI merge
  `485be1befcf99f9d4a337745ec0b4390529c79e1` on Laravel `staging` (PR #40).
- OpenAPI authority: `docs/api/v1/openapi.yaml` (version **1.1.0**). Do not
  duplicate schemas here.

## Goal

Customers browse home shelves, search/filter packages, and inspect package
product options and server prices inside the authenticated Android app.

## Flutter routes

| Route | Screen |
| --- | --- |
| `/app` | Catalog Home |
| `/app/packages` | Package list / search (`category_id`, `q`) |
| `/app/packages/:id` | Package detail |
| `/app/account` | Account + logout |

## Endpoints consumed

- `GET /api/v1/catalog/home`
- `GET /api/v1/packages`
- `GET /api/v1/packages/{id}`

Plus existing auth foundation (`/me`, login, 2FA, logout).

## Scope included

- Home: frequently ordered, featured packages, category chips, browse-all
- Browse/search with trim, debounce, 1-character suppression, OpenAPI `q` max 100
- Category filter by numeric id; chip shows server category name when known
- Detail: fixed/custom product options; custom amount is informational only
- Server `display.formatted` prices; `meta.prices_visible` / price unavailable
- Arabic/English localization; RTL/LTR; price text forced LTR; RTL chevron
- Accessibility keys/semantics (TalkBack-oriented structure)
- Authoritative session rejection: HTTP **401** and allowlisted **403** codes
  (`account_inactive`, `account_blocked`, `customer_role_required`,
  `missing_mobile_ability`, `unauthenticated`) via
  `AuthController.applyAuthoritativeRejection` + token-scoped `clearIfCurrent`
- Stale A→B rejection isolation; catalog clear on logout/session end
- Local API configuration: `http://10.0.2.2:8000/api/v1` (emulator)

## Explicit exclusions

Cart, buy now, checkout, wallet, orders, fulfillments, refunds, notifications,
Customer Activity UI, custom-amount input/quotation, client price math, deep
links / live route-query sync (deferred), production deploy, Laravel
`staging`→`main`.

## Verification recorded

- Format / analyze / `flutter test` / debug APK build on the correction pass
- GitHub CI `verify` success for pre-merge HEAD `2373fd5`
- Omar merged PR #4 to mobile `main`

## Successors

- [[Mobile M2.3 — Local Commerce Integration]] (Omar-accepted local Android run)
- Purchasing / cart milestone: architecture/discovery candidate only after M2
  closeout documentation is accepted (not started in this note)

## Related

- [[Mobile M2.0 — Commerce Shell Architecture]]
- [[Mobile M2.1 — Laravel Catalog API]]
- [[Mobile M2.1 Catalog API Contract]]
- [[Mobile M1.3 — Local Integration and Closeout]]
