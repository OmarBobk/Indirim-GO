# Refunds & Settlements

Customer refund requests and salesperson commission payouts.

## Invariants

- Refund approval: transactional wallet credit; idempotent keys `refund:*`
- Commission payout: `commission_credit:{commission_id}` idempotency
- Customer may request refund or retry (limited retries) on failed fulfillments

## Key files

- `app/Actions/Refunds/ApproveRefundRequest.php`
- `app/Actions/Commissions/CreatePayoutBatch.php`
- Admin: `/refunds`, `/admin/commissions`

## Features

- [[Customer Activity]] — refund action-required items

## Related

- [[Fulfillments & Automation]]
- [[Wallet & Ledger]]
