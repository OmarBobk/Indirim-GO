---
status: accepted
created: 2026-07-29
feature: mobile-m1-3-local-integration-closeout
pull_request: https://github.com/OmarBobk/Indirim-GO/pull/39
---

# Mobile M1.3 — Local Integration and Closeout

Close out local Laravel ↔ Flutter authentication integration and isolate optional Reverb failures from mobile login success.

## Position

- Laravel M1.1 auth foundation + M1.3 Reverb isolation are on `staging` (`99b6427`, PR #39 merged).
- Flutter M1.2 is on mobile `main` (`1b264786623f96936cfbf79470945f3c8a5d39d1`, PR #1 merged).
- Local emulator integration used: `http://10.0.2.2:8000/api/v1`
- Laravel, Reverb, and the Android emulator were run locally during verification.
- No staging/production API URL exists in repository evidence.
- Production promotion is deferred.
- Laravel `staging` must **not** be merged to `main` merely to close M1.3.
- Commerce Shell successors shipped and closed:
  - [[Mobile M2.1 — Laravel Catalog API]] (`485be1befcf99f9d4a337745ec0b4390529c79e1`, PR #40)
  - [[Mobile M2.2 — Flutter Commerce Shell]] (`c2119116239a720638c16a0b113be34f36698a78`, PR #4)
  - [[Mobile M2.3 — Local Commerce Integration]] (Omar-accepted local Android verification)
- Local emulator API remains: `http://10.0.2.2:8000/api/v1`

## Incident (local Android verification)

1. Laravel served on port `8000`.
2. Reverb was not running on `localhost:8080`.
3. Valid customer submitted `POST /api/v1/auth/login`.
4. Login recorded durable Spatie activity (`user.login`) via `RecordSuccessfulLogin`.
5. `AppServiceProvider::registerActivityBroadcasting` published `ActivityLogChanged` (`ShouldBroadcastNow`) through Pusher/Reverb.
6. Broadcaster connection failure escaped the request → API 500 → Flutter temporary-service-unavailable UI.
7. Starting Reverb allowed the same customer to sign in.

Customer Activity (`CustomerActivityBroadcaster` / `CustomerActivityInvalidated`) was **not** on this login path.

## Scoped resolution

- Application-owned realtime boundary: `App\Support\ActivityLogBroadcaster`
- Durable activity rows remain authoritative and are preserved.
- Optional realtime publication failures are caught at that boundary only.
- Safe warning log uses stable `error_id=activity_log_broadcast_failed` plus `activity_id` and `exception_class`.
- Raw broadcast exception messages are not logged (may contain signed query parameters).
- OpenAPI contract unchanged.
- Authentication response fields/statuses unchanged.
- Broadcast driver defaults and `.env` not used to hide the defect.

## Manual / integration evidence recorded

- Successful manual normal login (with Reverb available after incident diagnosis)
- Successful process/session restoration without login flash (M1.2)
- Successful offline restoration and retry (M1.2)
- Omar accepted remaining M1.2 manual-test risk (2FA UI, logout, reinstall, TalkBack, large-text not claimed here)

## Acceptance criteria

- [x] Diagnose exact event/listener/broadcaster escape path from repository evidence
- [x] Isolate optional activity-log realtime failures from auth success
- [x] Regression tests for login-with-failing-broadcaster, durable activity, safe logging, denials, 2FA challenge, authoritative failures
- [x] M1.2 vault sync from verified mobile `main`
- [x] M1.3 feature note + index/context updates
- [x] Omar review / merge (PR #39 on `staging`)

## Gotchas

- Login side effect path is **admin ActivityLogChanged**, not Customer Activity invalidation.
- `CustomerActivityBroadcaster` already isolated its own broadcast failures; that path is unrelated to this incident.
- Do not disable broadcasting globally or force `null`/`log` drivers to “fix” auth.
- Do not merge Laravel `staging` → `main` as an M1.3 closeout step.

## Related

- [[Mobile M1.1 — Laravel API Foundation and Authentication]]
- [[Mobile M1.2 — Flutter Foundation and Authentication]]
- [[Mobile M1.2 Flutter Authentication Architecture]]
- [[Mobile M2.0 — Commerce Shell Architecture]]
- [[Mobile M2.2 — Flutter Commerce Shell]]
- [[Customer Activity]] (not the failing login path; unchanged beyond shared broadcast hygiene patterns)
