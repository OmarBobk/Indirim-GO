# Refunds & Settlements

Customer refund requests and salesperson commission payouts.

## Invariants

- **No `RefundRequest` model** — refund workflow = `WalletTransaction` (`type=refund`, status pending|posted|rejected)
- Request = `RefundOrderItem` creates pending credit TX (amount via `LedgerMoney`); assigns immutable `public_ref` (`WTX-*`) at request time
- Approval: `ApproveRefundRequest` → `WalletLedger` promote pending; idempotent keys `refund:*`; approval+posting are atomic → customer “Refunded to wallet”
- Reject: `RejectRefundRequest` → `rejected` (no money moved); customer may re-request if fulfillment still Failed
- Dismiss: `DismissPendingRefundForFulfillment` / `DismissStaleRefundRequest` → `rejected` + `meta.dismiss_reason` (Closed to customer; no self-dismiss)
- Commission payout: `CreatePayoutBatch` → `WalletLedger` credit; key `commission_credit:{commission_id}`
- On refund post: pending commissions for fulfillment → `failed`; **credited commissions are NOT reversed** (explicit Phase-1 debt)
- Customer may request refund or retry (limited) on failed fulfillments
- Approve/reject/request/dismiss emit Activity + Financial broadcasters after commit (M6.4)

## Customer workspace (M6.4)

- Routes: `wallet.refunds.index` `/wallet/refunds`, `wallet.refunds.show` `/wallet/refunds/{WTX-*}`
- Read models: `GetCustomerRefunds`, `GetCustomerRefundDetail` → `CustomerRefundPresenter` → passive `x-wallet.refund*`
- Status map: pending→Under review; posted→Refunded; rejected+Failed FF→Needs action; rejected otherwise→Closed
- Recovery CTA: Review on order (existing re-request); no generic in-workspace retry invent
- Ledger link: same `WTX-*` via transactions search (no TX detail until M6.5)
- Ordering: `COALESCE(posted_at, created_at) DESC, id DESC` (no `updated_at` on wallet_transactions; rejected_at lives in meta for timeline only)

## Key files

- `app/Actions/Refunds/ApproveRefundRequest.php`, `RejectRefundRequest.php`, `GetCustomerRefunds.php`, `GetCustomerRefundDetail.php`
- `app/Actions/Orders/RefundOrderItem.php`
- `app/Support/Refunds/CustomerRefundClassifier.php`, `CustomerRefundPresenter.php`
- `app/Actions/Commissions/CreatePayoutBatch.php`
- Admin: `/refunds`, `/admin/commissions`

## Features

- [[Customer Financial Centre]] — M6.4 refunds workspace shipped
- [[Customer Activity]] — rejected refund → refund detail destination when `public_ref` present

## Related

- [[Fulfillments & Automation]]
- [[Wallet & Ledger]]
