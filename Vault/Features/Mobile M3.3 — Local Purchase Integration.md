---
title: Mobile M3.3 — Local Purchase Integration
type: feature
status: shipped
owner: Omar
project: İndirimGo
tags: [mobile, flutter, purchase, integration, m3]
created: 2026-08-05
updated: 2026-08-05
---

# Mobile M3.3 — Local Purchase Integration

## Summary

Omar-accepted local Android purchase walkthrough pairing **local Laravel staging** with **mobile `main`** (post–M3.2). Validates end-to-end buy-now against the M3.1 API on the emulator — not production.

## Status

**Accepted.** Omar accepted the local M3.3 Android purchase walkthrough. Production was **not** tested or deployed under this milestone. Laravel staging → main promotion remains outside M3.

## Pairing

| Side | Ref |
|------|-----|
| Laravel | Local checkout of accepted M3.1 on `staging` (merge `d23f961b1261a01f1adbd5eccfaae454ccfb8045` and later staging tip) |
| Mobile | `origin/main` tip including M3.2 PR #5: `9e056f1d5d795c8ad9d9c4061a0fddac831bae6f` |
| Emulator API base | `http://10.0.2.2:8000/api/v1` (debug cleartext only) |

## Scope of acceptance

- Local Android debug build against the emulator API URL above
- Purchase walkthrough accepted by Omar (package → buy → quote → confirm → receipt path)
- No customer information, order numbers, requirement values, Idempotency-Keys, tokens, or wallet balances recorded in this note

## Explicit non-claims

- Production deployment or production testing — **not** part of M3.3
- Laravel staging → main promotion — **outside** this milestone
- No invented DB counts or unrecorded manual-test matrices

## Related

- [[Mobile M3.2 — Flutter Buy-Now Purchasing Flow]]
- [[Mobile M3.1 — Laravel Purchase API]]
- [[Mobile M2.3 — Local Commerce Integration]] — prior local commerce pairing pattern

## Acceptance

- [x] Local Laravel staging + mobile main pairing
- [x] Emulator API `http://10.0.2.2:8000/api/v1`
- [x] Omar accepted local Android purchase walkthrough
- [x] No production deploy claim
