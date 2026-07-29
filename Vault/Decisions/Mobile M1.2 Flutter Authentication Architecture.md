---
date: 2026-07-29
status: accepted
---

# ADR — Mobile M1.2 Flutter Authentication Architecture

## Context

The customer Android client needed a small, testable authentication foundation against Laravel M1.1 `/api/v1` without inventing trusted domain behavior or financial mutation on the device.

## Decision

- Keep a feature-first Flutter structure (`lib/core` + `lib/features/auth` + minimal shell).
- Use Riverpod (no generators), go_router, Dio, and flutter_secure_storage.
- Persist only one atomic secure session value (Sanctum token + expiry).
- Keep username/password, authenticator codes, recovery codes, and challenge tokens out of persistence and logs.
- Treat Laravel as authoritative for credentials, account status, customer role, 2FA, token expiry, validation, and every domain/financial decision.
- Clear session only for authoritative 401 (and matching `/me`/logout 403) scoped to the request session that observed rejection.
- Preserve connectivity/timeout/5xx failures for retry without destroying the token.
- Restrict cleartext HTTP to debug-local hosts; require HTTPS for profile/release.
- Disable Android backup while tokens live in app-private secure storage.
- Pin Flutter/Dart toolchain in CI; keep tests independent of a live Laravel server.

## Alternatives considered

- **Code-generated clean architecture layers:** rejected for M1.2 scope and maintenance cost.
- **Client-owned 2FA expiry/attempt clocks:** rejected; Laravel owns challenge validity.
- **Refresh tokens:** rejected; matches M1.1 Sanctum PAT decision.
- **Floating Flutter stable in CI:** rejected; pin `3.44.8` / Dart `3.12.2`.

## Consequences

- Positive: deterministic auth restoration, secure token handling, OpenAPI-aligned client, CI-gated foundation
- Tradeoffs: remaining manual UX/accessibility checks deferred with Omar’s accepted risk for M1.2 closeout

## Evidence

- Mobile `main` commit `1b264786623f96936cfbf79470945f3c8a5d39d1`
- Merged PR: https://github.com/OmarBobk/indirimGo-mobile/pull/1
- CI: 72 tests + debug/analysis + debug APK + manifest/security verification

## Related features

- [[Mobile M1.2 — Flutter Foundation and Authentication]]
- [[Mobile M1.1 Authentication Architecture]]
- [[Mobile M1.3 — Local Integration and Closeout]]
