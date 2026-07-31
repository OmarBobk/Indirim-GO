# karman.store — Application schema (verified)

> Concise reference for **application** tables used by this Laravel app.  
> Shared MySQL may contain leftover vendor/legacy tables — **ignore those**; do not invent columns.  
> Source of truth for money: `wallets.balance` + posted `wallet_transactions`.  
> Last refreshed: 2026-07-30 from live schema + models/enums.

---

## Financial core

### `wallets`

| Column | Notes |
|--------|--------|
| `user_id` | Unique; one customer wallet per user |
| `type` | `customer` \| `platform` (`WalletType`) |
| `balance` | `decimal`; **may be negative** for customer wallets with Active credit facility |
| `currency` | Ledger currency (USD) |
| `credit_enabled` | bool — facility granted |
| `credit_limit` | max overdraft ceiling |
| `payment_terms_days` | nullable Net N |
| `credit_status` | nullable `active` \| `suspended` (`CreditFacilityStatus`); **null when not granted** |

Helpers on `Wallet`: `effectiveCreditLimit()`, `minimumAllowedBalance()`, `availableToSpend()`, `availableCredit()`, `outstandingDebt()`, `isOverdrawn()`.

### `wallet_transactions`

| Column | Notes |
|--------|--------|
| `wallet_id` | FK |
| `type` | `topup`, `purchase`, `refund`, `adjustment`, `settlement`, `commission_credit` |
| `direction` | `credit` \| `debit` (not “cash”) |
| `amount` | decimal |
| `status` | `pending` \| `posted` \| `rejected` |
| `reference_type` / `reference_id` | polymorphic |
| `idempotency_key` | unique |
| `public_ref` | unique human ref (`WTX-*`, etc.) |
| `meta` | JSON (incl. receipt snapshots) |
| `posted_at` | set when posted; no `updated_at` on model |

Posted rows are immutable. All product money mutations go through `WalletLedger`.

---

## Orders & catalog

### `orders`

`user_id`, unique `order_number`, `currency`, `subtotal`, `fee`, `total`, `status` (`pending_payment` \| `paid` \| `processing` \| `fulfilled` \| `failed` \| `refunded` \| `cancelled`), `paid_at`, `meta` (incl. `cart_hash` for checkout idempotency).

### `order_items`

Snapshot line: `product_id`, `package_id`, `name`, `unit_price`, `unit_cost`, `quantity`, `amount_mode`, `requested_amount`, `amount_unit_label`, `pricing_meta`, `line_total`, `entry_price`, `requirements_payload`, `status`.

Custom-amount lines are quantity-1 with `requested_amount`. Server pricing is authoritative.

### Catalog

- `categories` — tree via `parent_id`, slug, active, order, image/icon
- `packages` — belongs to category; `fulfillment_provider` (`null` = manual, `browser:{supplier}` = automated)
- `products` — belongs to package; prices + amount mode fields (see migrations/models)
- `package_requirements` — keyed fields for checkout payload validation
- `pricing_rules`, `user_pricing_rules` — global vs per-user pricing
- `payment_methods` — admin-managed top-up methods (`name`, `image`, `account_text`, `is_active`, `sort_order`)

---

## Topups

### `topup_requests`

`public_ref` (`TUP-*`), `user_id`, `wallet_id`, `payment_method_id`, `amount`, `currency`, `status`, `note`, `approved_by`, `approved_at`.

### `topup_proofs`

Private file metadata for a request (`file_path`, mime, size). Access gated by ownership/admin.

---

## Fulfillments & automation

### `fulfillments`

`order_id`, `order_item_id`, `claimed_by`, `claimed_at`, `provider`, `status` (`queued` \| `processing` \| `completed` \| `failed` \| `cancelled`), `attempts`, `meta` (refund workflow keys, delivered payload, automation flags).

### `fulfillment_logs`

Append-only debug/audit lines per fulfillment.

### `fulfillment_automation_runs`

`uuid`, `fulfillment_id`, `supplier_key`, `status` (`FulfillmentAutomationRunStatus`), `idempotency_key`, `external_order_id`, result/log JSON, timestamps, `meta` (incl. `automation_phase` purchase/reconcile).

### Supplier price scans

`supplier_price_scans`, `supplier_price_scan_items` — Wasim catalog drift runs + per-product results.

---

## Commissions & payouts

### `commissions`

Per fulfillment/order referral commission: `salesperson_id`, `customer_id`, amounts + rate snapshot, `status` (`pending` \| `credited` \| `failed`), optional `payout_batch_id`, unique `wallet_transaction_id` when credited.

### `payout_batches`

Admin batch that posts `commission_credit` wallet txs via `CreatePayoutBatch`.

### `payout_requests`

Salesperson workflow signal only (`pending` \| `processed`) — **does not** post wallet money.

---

## Settlements

`settlements` + `settlement_fulfillments` — platform profit settle (`profit:settle`) credits platform wallet idempotently.

---

## Observability & ops

| Table | Role |
|-------|------|
| `system_events` | Audit/timeline mirror (`event_type`, `is_financial`, entity morph, meta). Never derive balances from this. |
| `activity_log` | Spatie activity log |
| `notifications` | Customer/staff notification delivery + unread truth |
| `push_logs` | FCM telemetry |
| `bugs`, `bug_attachments`, `bug_links`, `bug_steps` | Bug ops |
| `website_settings` | Singleton site config (automation kill switch, Wasim credentials, commission floors, etc.) |
| `loyalty_tiers` / related loyalty config | Tier thresholds |
| `agent_conversations`, `agent_conversation_messages` | Ops Assistant persistence |

---

## Auth / ACL

`users` (+ referral fields, locale, loyalty, block flags), Spatie `roles` / `permissions` / pivots, `personal_access_tokens` (Sanctum mobile PATs), `password_reset_tokens`, `sessions`.

---

## Invariants (do not break)

1. Every balance mutation ⇒ one posted wallet TX + matching financial `system_events` row (facility update is financial audit without balance change; reconcile `--repair` is the documented snapshot exception).
2. Spend gates use `WalletSpendPolicy` / `availableToSpend()`; debit floor uses `minimumAllowedBalance()` under lock.
3. Cart/checkout prices are recalculated server-side; client totals are untrusted.
4. Platform wallets never overdraft / never get a credit facility.
