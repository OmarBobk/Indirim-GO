# Refunds & Settlements

Customer refund requests and salesperson commission payouts.

## Invariants

- Refund request = pending `WalletTransaction` (`type=refund`) via `RefundOrderItem` (amount via `LedgerMoney`)
- Refund approval: `ApproveRefundRequest` → `WalletLedger` promote pending; idempotent keys `refund:*`
- Commission payout: `CreatePayoutBatch` → `WalletLedger` credit; key `commission_credit:{commission_id}`
- Customer may request refund or retry (limited) on failed fulfillments
- Approve/reject emit `CustomerActivityBroadcaster` + `CustomerFinancialBroadcaster` after commit

## Key files

- `app/Actions/Refunds/ApproveRefundRequest.php`, `RejectRefundRequest.php`
- `app/Actions/Orders/RefundOrderItem.php`
- `app/Actions/Commissions/CreatePayoutBatch.php`
- Admin: `/refunds`, `/admin/commissions`

## Features

- [[Customer Financial Centre]] — M6 refund tracking IA
- [[Customer Activity]] — refund action-required items (projection)

## Related

- [[Fulfillments & Automation]]
- [[Wallet & Ledger]]
