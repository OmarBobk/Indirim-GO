---
date: 2026-07-29
status: accepted
---

# ADR — Mobile M1.1 Authentication Architecture

## Context

The customer Flutter client needs a stateless Laravel authentication boundary without replacing Fortify web authentication or coupling to shipped Customer Activity behavior. The API must preserve account-status rules, existing two-factor enrollment, localization, and financial-data boundaries.

## Decision

- Use Laravel Sanctum personal access tokens for native mobile authentication.
- Require a real Sanctum bearer PAT on protected mobile routes; reject web-session `TransientToken` authentication without altering the browser session.
- Permit only users holding the `customer` role.
- Give mobile tokens only the `mobile:access` ability.
- Set `expires_at` explicitly to 30 days on each mobile token.
- Do not implement refresh tokens; authenticate again after expiry.
- Keep Fortify as the 2FA authority.
- After valid username/password credentials, issue a five-minute opaque challenge when confirmed 2FA is enabled.
- Store challenge state under a SHA-256 identifier, enforce a shared five-attempt account budget across newly issued challenges, and serialize completion with challenge- and user-scoped cache locks.
- Lock the user row when validating/consuming recovery codes.
- Publish and review the API contract at `docs/api/v1/openapi.yaml`; implementation and tests must follow it.
- Use local Laravel until a staging URL exists. Android emulators reach the host through `10.0.2.2`.
- Keep API base URLs environment-configured rather than hardcoded.
- Exclude mobile login and 2FA request entries from Telescope and configure defense-in-depth redaction for authentication request fields, headers, returned PATs, and challenge tokens.

## Alternatives considered

- **Fortify web sessions in Flutter:** rejected because the native client requires a bearer-token lifecycle and must not depend on browser session state.
- **Allow Sanctum web-session fallback on `/api/v1`:** rejected because transient tokens satisfy every ability and would break PAT expiry/logout semantics.
- **OAuth/Passport:** rejected because first-party customer PATs do not need OAuth client and grant complexity.
- **JWT with refresh tokens:** rejected because it adds signing, rotation, and revocation behavior not required for M1.
- **Unsigned user ID as 2FA challenge:** rejected because it is client-controlled, enumerable, and replayable.
- **Client-managed 2FA state:** rejected because Laravel must remain authoritative.
- **Global Sanctum expiration:** rejected because it would alter unrelated current/future tokens.

## Consequences

- Positive:
  - Native bearer authentication with server-side revocation and per-token abilities
  - Existing Fortify secrets and recovery behavior remain authoritative
  - No staff role/permission or financial-data exposure
  - Clear OpenAPI source of truth for Flutter integration
- Negative / tradeoffs:
  - Requires the `personal_access_tokens` migration
  - Users must log in again after 30 days
  - Challenge locking requires a cache shared by all application instances
  - Local integration requires per-environment base URL configuration

## Code touchpoints

- `routes/api.php`
- `config/mobile_api.php`
- `config/sanctum.php`
- `app/Actions/MobileAuth/*`
- `app/Http/Middleware/EnsureMobileAccountCanAuthenticate.php`
- `app/Providers/TelescopeServiceProvider.php`
- `app/Http/Resources/Api/V1/*`
- `docs/api/v1/openapi.yaml`

## Related features

- [[Mobile M1.1 — Laravel API Foundation and Authentication]]
- [[Customer Activity]] (protected, unchanged)
- [[Wallet & Ledger]] (financial boundary, unchanged)
