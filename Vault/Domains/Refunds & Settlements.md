# Refunds & Settlements

Customer refund requests and salesperson commission payouts / earnings.

## Invariants

- **No `RefundRequest` model** — refund workflow = `WalletTransaction` (`type=refund`, status pending|posted|rejected)
- Request = `RefundOrderItem` creates pending credit TX (amount via `LedgerMoney`); assigns immutable `public_ref` (`WTX-*`) at request time
- Approval: `ApproveRefundRequest` → `WalletLedger` promote pending; idempotent keys `refund:*`; approval+posting are atomic → customer “Refunded to wallet”
- Reject: `RejectRefundRequest` → `rejected` (no money moved); customer may re-request if fulfillment still Failed
- Dismiss: `DismissPendingRefundForFulfillment` / `DismissStaleRefundRequest` → `rejected` + `meta.dismiss_reason` (Closed to customer; no self-dismiss)
- Commission payout: `CreatePayoutBatch` → `WalletLedger` credit; key `commission_credit:{commission_id}`
- On refund post: pending commissions for fulfillment → `failed`; **credited commissions are NOT reversed** (explicit Phase-1 debt — no clawback without approved financial policy)
- Customer may request refund or retry (limited) on failed fulfillments
- Approve/reject/request/dismiss emit Activity + Financial broadcasters after commit (M6.4)
- M6.7 signalling: refund approve emits one owner event containing `TransactionPosted` + `RefundStateChanged`; pending→failed and credited commissions use `CommissionStateChanged`; payout-request create/process uses `PayoutRequestStateChanged`
- Payout batches deduplicate salesperson IDs and emit one `TransactionPosted` + `CommissionStateChanged` event per affected salesperson, not per commission row

## Commission / earnings (canonical — M6.6)

Do **not** create a second commission feature note; keep earnings contracts here + [[Customer Financial Centre]].

### Truth

- Commission table = earnings workflow
- Posted `commission_credit` WalletTransaction = money entered wallet
- PayoutRequest = signal only (`pending`|`processed`)
- Request floor `$10` exclusive (`RequestSalespersonPayout::MIN_ELIGIBLE_EXCLUSIVE`) ≠ admin batch min (`WebsiteSetting::getCommissionPayoutMinAmount()`, default `$200`)

### Refund × commission policy table (current code)

| Case | Current behaviour | Severity | M6.6 UI | Future policy |
|---|---|---|---|---|
| A. Refund before credit | Pending → `failed` | Expected | Show failed | Keep |
| B. Refund while pending | Same as A on approve | Expected | Show failed | Keep |
| C. Late refund after credit | Credited **not** reversed; customer refund still posts | **Phase-1 debt** | Show credited + order context; no false “final forever”; no clawback | Needs approved clawback / adjustment policy |
| D. Partial refund | Pending commissions for refunded fulfillment fail; other lines may remain | Medium | Per-commission status | Clarify multi-line rules |
| E. Multi-fulfillment one fails | Per-fulfillment commission rows | Medium | Per row | Keep |
| F. Already in PayoutBatch | Credited path; no reverse | High if late refund | Credited + WTX link | Clawback policy |

Never deduct customer refunds by salesperson commission.

### Salesperson earnings surface

- `/wallet/earnings` — financial commission clarity
- `/salesperson-dashboard` — business KPIs (still hosts payout request CTA)
- Admin: `/admin/commissions`

## Customer workspace (M6.4)

- Routes: `wallet.refunds.index` `/wallet/refunds`, `wallet.refunds.show` `/wallet/refunds/{WTX-*}`
- Read models: `GetCustomerRefunds`, `GetCustomerRefundDetail` → `CustomerRefundPresenter` → passive `x-wallet.refund*`
- Status map: pending→Under review; posted→Refunded; rejected+Failed FF→Needs action; rejected otherwise→Closed
- Recovery CTA: Review on order (existing re-request); no generic in-workspace retry invent
- Ledger link: same `WTX-*` via transactions search / M6.5 detail
- Ordering: `COALESCE(posted_at, created_at) DESC, id DESC` (no `updated_at` on wallet_transactions; rejected_at lives in meta for timeline only)

## Key files

- `app/Actions/Refunds/ApproveRefundRequest.php`, `RejectRefundRequest.php`, `GetCustomerRefunds.php`, `GetCustomerRefundDetail.php`
- `app/Actions/Orders/RefundOrderItem.php`
- `app/Support/Refunds/CustomerRefundClassifier.php`, `CustomerRefundPresenter.php`
- `app/Actions/Commissions/CreatePayoutBatch.php`, `RequestSalespersonPayout.php`
- `app/Support/Commissions/SalespersonCommissionEligibility.php`
- `app/Actions/Earnings/GetSalespersonEarnings.php`, `app/Support/SalespersonEarningsPresenter.php`
- Admin: `/refunds`, `/admin/commissions`

## Features

- [[Customer Financial Centre]] — M6.4 refunds + **M6.6 earnings** shipped
- [[Customer Activity]] — rejected refund → refund detail destination when `public_ref` present

## Related

- [[Fulfillments & Automation]]
- [[Wallet & Ledger]]
