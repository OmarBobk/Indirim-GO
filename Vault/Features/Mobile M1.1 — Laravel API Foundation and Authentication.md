---
status: verified
created: 2026-07-29
feature: mobile-m1-1-api-auth
pull_request: https://github.com/OmarBobk/Indirim-GO/pull/38
---

# Mobile M1.1 — Laravel API Foundation and Authentication

Customer-only Laravel API foundation for the future İndirimGo Flutter application. This is a backend prerequisite for the older M1 Shopping milestone in `Docs/doc.md`; it does not replace that milestone.

## Goal

Flutter can authenticate a customer, complete existing Fortify 2FA, read a safe profile, and revoke its current access token through a stable `/api/v1` contract.

## Approved decisions

- Customer-only access; no staff roles or permission dump
- `username` remains the login identifier and follows Fortify lowercase normalization
- Laravel Sanctum personal access tokens with only `mobile:access`
- Protected mobile routes require a real bearer PAT; web-session authentication is rejected without ending the web session
- Explicit 30-day expiry per mobile token; no global token expiry change
- No refresh-token flow
- Full Fortify authenticator-code and recovery-code support
- API prefix `/api/v1`
- Authoritative contract: `docs/api/v1/openapi.yaml`
- API messages support only `ar` and `en` through `Accept-Language`
- No new email-verification login gate
- Local Laravel integration until a staging URL exists
- Android application ID context: `tr.indirimgo.app` (no mobile-repository changes in M1.1)
- Laravel remains authoritative for auth, authorization, validation, and domain behavior

See [[Mobile M1.1 Authentication Architecture]].

## Scope and implementation evidence

- `routes/api.php` — login, 2FA challenge, logout, and `/me`
- `app/Actions/Auth/AuthenticateUserCredentials.php` — shared Fortify/mobile credential and account-status checks
- `app/Actions/Auth/RecordSuccessfulLogin.php` — shared `last_login_at` and existing activity-audit convention
- `app/Actions/MobileAuth/*` — one-time challenge, locked completion, recovery-code consumption, PAT issue
- `app/Http/Middleware/SetApiLocale.php` — stateless `Accept-Language`
- `app/Http/Middleware/EnsureMobileAccountCanAuthenticate.php` — token-aware `canLogin()` and customer-role recheck
- `app/Http/Resources/Api/V1/*` — safe authentication and customer profile envelopes
- `app/Providers/TelescopeServiceProvider.php` — excludes sensitive login/2FA request entries and redacts auth request/response data
- `config/mobile_api.php` — ability, token lifetime, challenge lifetime/attempt/lock limits
- `database/migrations/2026_07_29_024057_create_personal_access_tokens_table.php`
- `docs/api/v1/openapi.yaml`

## Security behavior

- Password, TOTP, recovery code, challenge token, and plain Sanctum token are never logged.
- A password-successful 2FA login returns HTTP 202 without a Sanctum token.
- Challenge material is 256-bit random, returned once, and addressed in cache only by SHA-256.
- Challenge state contains only user ID, validated device name, attempts, and expiry.
- Challenge lifetime is five minutes with a shared five-attempt account budget across newly issued challenges and a dedicated route/IP limiter.
- Challenge completion uses challenge- and user-scoped cache locks; recovery-code consumption also locks the user row.
- The challenge is deleted before token issue after successful verification, preventing replay.
- Account status and customer role are rechecked before token issue and on protected requests.
- Invalid protected accounts lose only their current mobile token; web sessions are unaffected.
- Logout revokes only the current token.
- Telescope does not record mobile login/2FA request entries; request fields, auth headers, returned PATs, and challenge values are also configured for redaction.

## Local integration

- Start Laravel locally; no staging API URL exists yet.
- Host/base path: `http://localhost:8000/api/v1`
- Android emulator equivalent: `http://10.0.2.2:8000/api/v1`
- Configure the client base URL per environment. Do not hardcode a complete API URL in Laravel or Flutter source.

## Acceptance criteria

- [x] `POST /api/v1/auth/login`
- [x] `POST /api/v1/auth/two-factor-challenge`
- [x] `POST /api/v1/auth/logout`
- [x] `GET /api/v1/me`
- [x] Customer-only Sanctum PAT with explicit 30-day expiry
- [x] Fortify authenticator and recovery-code flows
- [x] One-time, hashed, short-lived, attempt-limited, lock-protected challenge
- [x] Token-aware account status and mobile ability checks
- [x] Safe `MobileUserResource`, including display-only phone and no financial fields
- [x] Arabic and English API messages
- [x] Authoritative OpenAPI contract
- [x] Existing web auth focused tests pass
- [x] Existing web login, 2FA, recovery-code, remember-me, audit, logout, redirect, session-status, and rate-limit behavior passes focused regression tests
- [x] M5 protected files remain unchanged; its flaky Activity assertion fails identically on the base commit
- [ ] Repository-wide suite fully green; all nine final feature failures are already failing identically on the base commit

## Setup and verification commands

```text
php artisan install:api --no-interaction --without-migration-prompt
php artisan migrate
php artisan route:list --path=api/v1 -v
php artisan config:show mobile_api
php artisan config:show sanctum
php artisan test --compact tests/Feature/Api/MobileAuthenticationTest.php tests/Feature/Api/MobileOpenApiContractTest.php
php artisan test --compact tests/Feature/Auth/WebAuthenticationRegressionTest.php
php artisan test --compact tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/TwoFactorChallengeTest.php tests/Feature/Auth/LoginLocaleSyncTest.php tests/Feature/BlockedUserSessionTest.php
php artisan test --compact tests/Feature/AuthActivityLogTest.php --filter="user login and logout"
php artisan test --compact tests/Unit/CustomerActivityPresenterTest.php tests/Unit/CustomerActivityDTOTest.php tests/Feature/CustomerActivityReadModelTest.php tests/Feature/CustomerActivityRealtimeTest.php tests/Feature/CustomerActivityPageRealtimeTest.php tests/Feature/CustomerActivityActionRequiredTest.php tests/Feature/CustomerActivityPerformanceTest.php tests/Feature/CustomerActivityPageTest.php
php artisan test --compact
vendor/bin/pint --dirty
composer test:lint
```

## Verification result

- Toolchain: PHP 8.4.23, Composer 2.7.1, Node 22.14.0, npm 10.9.7
- Mobile API/OpenAPI targeted tests: pass, 355 assertions
- Dedicated web-auth regression tests: pass, 60 assertions
- Combined final auth verification: pass, 415 assertions
- Pint: pass
- Repository has no configured static-analysis command beyond Pint
- Final feature full suite: 9 failures, 3,499 assertions
- Exact `origin/staging` (`b833e89`) full suite: 10 failures, 3,073 assertions
- Every final feature failure has the same failure message and source line on `origin/staging`; the base has one additional random package-order collision.
- The earlier reported feature run had 10 failures because random unique-order and locale/activity flakes vary between runs. The controlled final comparison supersedes that count.
- Targeted M5 rerun: one `CustomerActivityPerformanceTest` assertion failure at line 173; the exact failure is present on the base commit and no M5 file changed.
- PHP 8.4 emits dependency deprecation notices during tests; these are not M1.1 failures

## Pull request

- PR: https://github.com/OmarBobk/Indirim-GO/pull/38
- Base: `staging`
- Branch: `cursor/mobile-api-v1-auth-5bcf` (Cursor Cloud branch policy)

## Successors

- [[Mobile M1.2 — Flutter Foundation and Authentication]] — Flutter client foundation merged to mobile `main`
- [[Mobile M1.2 Flutter Authentication Architecture]]
- [[Mobile M1.3 — Local Integration and Closeout]] — local emulator integration closeout + auth/Reverb failure isolation

## Gotchas

- `config('sanctum.expiration')` intentionally remains `null`; only mobile PATs receive an explicit `expires_at`.
- Native Flutter uses bearer PATs, not Sanctum SPA cookie authentication.
- Sanctum web-session `TransientToken` is intentionally rejected on protected mobile routes.
- Distinct inactive, blocked, non-customer, and 2FA-required responses occur only after a correct password, as required by the approved login contract; wrong passwords remain uniform.
- Production cache must be shared by all Laravel instances so challenge locks and one-time state remain authoritative.
- `APP_URL` must be correct for absolute profile-photo URLs.
- Run the Sanctum migration before serving mobile requests.
- Existing npm audit reports vulnerabilities in the current frontend lockfile; M1.1 did not change frontend dependencies.
- Customer Activity, notification unread state, realtime behavior, and mobile repository files are outside this milestone.
- M1.3 discovered that successful mobile login can create a durable `user.login` activity row that then publishes optional `ActivityLogChanged` realtime; Reverb downtime must not fail authentication (see M1.3).
