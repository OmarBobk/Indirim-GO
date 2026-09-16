# Mobile M5 — Wallet and manual top-ups

Additive customer Mobile API **1.5.0** for wallet history and manual top-ups.

Authoritative contract: [`openapi.yaml`](openapi.yaml).

## Endpoints

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/wallet/summary` | `available_to_spend` + `pending_topup_public_ref` |
| GET | `/wallet/transactions` | Posted ledger only, page/per_page |
| GET | `/wallet/payment-methods` | Active methods, plain instructions |
| GET | `/wallet/topups` | Owned history |
| POST | `/wallet/topups` | Multipart + `Idempotency-Key` |
| GET | `/wallet/topups/status` | Lost-response recovery |
| GET | `/wallet/topups/{public_ref}` | Owned detail |
| GET | `/wallet/topups/{public_ref}/proof` | Private stream |

POST never credits the wallet. Existing admin approval posts the ledger.
`currency` is required. TRY amounts convert server-side; clients must not convert.
