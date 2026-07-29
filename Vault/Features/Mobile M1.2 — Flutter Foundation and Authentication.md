---
status: verified
created: 2026-07-29
feature: mobile-m1-2-flutter-auth
mobile_repository: OmarBobk/indirimGo-mobile
mobile_main_commit: 1b264786623f96936cfbf79470945f3c8a5d39d1
pull_request: https://github.com/OmarBobk/indirimGo-mobile/pull/1
---

# Mobile M1.2 — Flutter Foundation and Authentication

Customer-only Flutter foundation and Laravel-backed authentication in `OmarBobk/indirimGo-mobile`. Merged to mobile `main` via PR #1. Laravel OpenAPI on `origin/staging` remains authoritative. This note records verified facts only; full mobile architecture lives in the mobile repo.

## Verified facts

- Mobile repository: `OmarBobk/indirimGo-mobile`
- Merged `main` commit: `1b264786623f96936cfbf79470945f3c8a5d39d1`
- Flutter `3.44.8` / Dart `3.12.2` (pinned; CI enforces both)
- Android application ID / namespace: `tr.indirimgo.app`
- Riverpod: authentication and locale state without generators
- go_router: auth-aware redirects and route protection
- Dio: single configured JSON client; no secret-bearing logging
- flutter_secure_storage: atomic Sanctum session persistence (token + expiry under one key)
- Sanctum bearer PAT with server-controlled 30-day expiry; no refresh-token flow
- 2FA challenge tokens/codes remain memory-only; never persisted or logged
- Token-scoped 401 clearing (only the matching request session)
- Arabic/English ARB localization; Arabic fallback; `Accept-Language` sent
- Debug-local HTTP restricted to `10.0.2.2` / `127.0.0.1` / `localhost`; release/profile require HTTPS
- Android backup disabled while tokens live in secure storage
- Pinned Flutter CI workflow (not floating `stable`)
- CI evidence: formatting, analysis, **72** automated tests, debug APK build, Android manifest/security verification — all green on the merged PR run
- Manual evidence accepted for M1.2 closeout: normal login; process/session restoration without login flash; offline restoration and retry
- Omar accepted remaining manual-test risk for M1.2 closeout (2FA UI, logout, reinstall, TalkBack, large-text not claimed as passed here)

## Non-claims

Do **not** treat the following as verified passed for M1.2 closeout:

- Manual 2FA completion
- Manual logout
- Reinstall persistence
- TalkBack
- Large-text accessibility pass beyond what automated/widget coverage already proves

## Local integration context

- Emulator base URL pattern: `http://10.0.2.2:8000/api/v1`
- No staging/production API URL exists in either repository at M1.2 merge time

## Related

- [[Mobile M1.1 — Laravel API Foundation and Authentication]]
- [[Mobile M1.1 Authentication Architecture]]
- [[Mobile M1.2 Flutter Authentication Architecture]]
- [[Mobile M1.3 — Local Integration and Closeout]]
