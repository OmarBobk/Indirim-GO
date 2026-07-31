# System Events Map

## Invariant: Ledger + Event Mirror Consistency

**For every wallet balance mutation there is exactly one financial system_event** (one POSTED `wallet_transactions` row, one wallet balance change, one `system_events` row with `is_financial = true` and matching `event_type`). No balance change without event.

**Exception:** `wallet.credit_facility.updated` is recorded with `is_financial = true` for audit/timeline but does **not** change balance (facility config only).

**Source of truth:** `wallet_transactions` and `wallets.balance`. `system_events` is observability only. No logic must derive balance or financial state from `system_events`. Customer `balance` may be negative under an Active credit facility.

---

## Financial Events (is_financial = true)

**Recorded inside the same DB transaction as the related write. Balance-changing events also update `wallets.balance`. Broadcast via `DB::afterCommit()`.**

| event_type                  | Entity       | Actor    | Balance change                    | Hook |
|----------------------------|-------------|----------|-----------------------------------|------|
| `wallet.purchase.debited`  | Order       | User     | Wallet decrement (customer; may go negative under Active credit facility) | PayOrderWithWallet |
| `wallet.refund.credited`   | WalletTransaction | Admin | Wallet increment (customer)       | ApproveRefundRequest |
| `wallet.topup.posted`      | TopupRequest| Admin    | Wallet increment (customer; also reduces outstanding debt by arithmetic) | ApproveTopupRequest |
| `wallet.adjustment.posted` | WalletTransaction | Admin | Wallet increment/decrement (customer; AdjustWallet via WalletLedger) | AdjustWallet |
| `wallet.commission.credited` | Commission / WalletTransaction | Admin | Salesperson wallet credit (`commission_credit`) | CreatePayoutBatch |
| `platform.profit.recorded` | Settlement  | null     | Platform wallet increment         | ProfitSettleCommand |
| `wallet.credit_facility.updated` | Wallet | Admin | **No balance change** — facility grant/limit/terms/status audit (`previous_*` / `new_*`) | UpdateCreditFacility |

**Note on `wallet.credit_facility.updated`:** recorded with `is_financial = true` for audit streaming / customer wallet timeline, but it does **not** mutate `wallets.balance`. It is facility config only.

**Not financial (workflow only):** `profit.settlement.executed` → `is_financial = false` (recorded async after commit). Settlement execution is one balance change; only `platform.profit.recorded` mirrors it.

---

## Informational Events (is_financial = false)

**Dispatched via `PersistSystemEventJob` after commit. Idempotency: structured key `async:{event_type}:{entity_type}:{entity_id}[:suffix]` (no hash of meta).**

| event_type             | Entity           | Actor | Idempotency suffix | Hook |
|------------------------|------------------|-------|--------------------|------|
| `order.created`         | Order            | User  | —                  | CreateOrderFromCartPayload (after commit) |
| `refund.requested`     | WalletTransaction| User  | —                  | RefundOrderItem (after commit) |
| `refund.approved`      | WalletTransaction| Admin | —                  | ApproveRefundRequest (after commit) |
| `fulfillment.created`  | Fulfillment      | User  | —                  | CreateFulfillmentsForOrder (after commit) |
| `admin.rejected.refund`| WalletTransaction| Admin | —                  | RejectRefundRequest (after commit) |
| `admin.rejected.topup` | TopupRequest     | Admin | —                  | RejectTopupRequest (after commit) |
| `tier.upgraded`        | User             | null  | new_tier           | EvaluateLoyaltyForUserAction (after commit; activity event `loyalty.tier_changed`) |
| `profit.settlement.executed` | Settlement | null | —                  | ProfitSettleCommand (after commit) |

**Also logged via Spatie activity (not always mirrored as system_events):** fulfillment automation lifecycle (`fulfillment.automation.*`), `payout_request.created` / `payout_request.processed`, `refund.dismissed`, `payment.failed`. See `logging_map.md`.

---

## Anomaly Events (operational intelligence, is_financial = false)

**Recorded by OperationalIntelligenceService inside `DB::afterCommit()` at invocation points. Idempotency: time bucket or date suffix so the same condition does not flood events.**

| event_type                           | Entity           | Actor | Idempotency suffix              | Severity  | Hook |
|--------------------------------------|------------------|-------|---------------------------------|-----------|------|
| `wallet.anomaly.velocity_detected`   | Wallet           | null  | time bucket (window_seconds)    | warning   | PayOrderWithWallet, ApproveTopupRequest (after commit) |
| `refund.anomaly.pattern_detected`    | User             | null  | time bucket (window_minutes)    | warning   | ApproveRefundRequest (after commit) |
| `fulfillment.anomaly.failure_spike`  | Fulfillment      | null  | provider/product + bucket      | warning   | FailFulfillment (after commit) |
| `wallet.anomaly.drift_detected`      | Wallet           | null  | date (Y-m-d)                    | critical  | WalletReconcile (after drift fix) |

---

## Broadcast

- **Financial:** Insert in transaction → `DB::afterCommit(() => event(new SystemEventCreated($event->id)))`.
- **Async:** Job inserts → `event(new SystemEventCreated($event->id))` (no transaction in worker).

Admin UI: on `system-event-created`, **prepend** new event to list and trim to 50 (no full list refresh).

---

## Severity

- Default: `info`.
- Financial events: `info`.
- Anomaly (velocity, refund abuse, fulfillment failure): `warning`.
- Reconciliation drift: `critical` (if recorded).
- System failure: `critical`.
