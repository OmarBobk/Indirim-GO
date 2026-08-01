- users
  - name
  - email
  - email_verified_at
  - password
  - username
  - is_active
  - blocked_at
  - last_login_at
  - timezone
  - meta
  - profile_photo
  - phone
  - country_code
- categories (subscriptions, games)
  - id
  - parent_id (nullable)
  - name
  - slug
  - order
  - icon
  - is_active
  - image
- packages (Pubg, tiktok)
  - id
  - category_id
  - name
  - slug
  - description
  - is_active
  - order
  - icon
- products
  - id
  - package_id
  - name
  - retail_price
  - wholesale_price
  - is_active
  - order
- package_requirements
  - id
  - package_id
  - key (id, username, phone)
  - label ("ID")
  - type (enum: string, number, select)
  - is_required
  - validation_rules (string nullable) (Laravel-style: required|numeric)
  - order

Create The Wallets Model and wallet transactions model with these data:
- wallets:
  - user_id
  - balance (default 0; **may be negative** for customer wallets with an Active credit facility)
  - type (customer / platform)
  - credit_enabled, credit_limit, payment_terms_days, credit_status (nullable Active/Suspended when granted)
  - timestamps
- wallet_transactions
  - wallet_id
  - type enum: purchase, topup, refund, adjustment.
  - direction enum: cash, debit
  - amount
  - status enum: pending, approved, rejected
  - reference_type nullable (polymorphic string: Order, TopupRequest, etc.)
  - reference_id nullable (bigint)
  - meta json nullable (notes, admin_id, etc.)

Products belongs to package so create the related methods in the model

act like a senior designer and design the Products manager page to manage the products using Laravel, Livewire, alpinjs, Tailwind and flux best practices.
the style and colors should match and follow the design pattern and the general colors that has been used before and don't forget to use the same colors
pay attention for the ui / ux princeples and high performance and quality the page speed should be the light speed


the placeholder of the order field should be the higher and the smallest product order that is exist in db


You are a senior Laravel 12 backend engineer with strong database design experience.

Goal (Step 4):
Implement orders + order_items schema and the backend “checkout snapshot” flow that converts the Alpine/localStorage cart payload into a persistent Order.

Context:
- Cart is handled on the frontend with Alpine.js (localStorage). We will NOT add carts tables.
- At checkout, the server must create a permanent Order + OrderItems snapshot from the cart payload.
- Wallet system exists (wallets + wallet_transactions) and top-ups exist (topup_requests/proofs).

Scope:
✅ Implement ONLY:
1) orders table
2) order_items table
3) Eloquent models + relationships
4) A minimal service/action: CreateOrderFromCartPayload (no payment yet)
5) A minimal service/action: PayOrderWithWallet (wallet debit + mark order paid)
   ❌ Do NOT implement fulfillments, supplier APIs, UI, admin panels.

A) orders table
Fields:
- id
- user_id (FK)
- order_number (string unique; human-friendly e.g. "ORD-2026-000042")
- currency (string, default 'USD')
- subtotal (decimal 12,2)
- fee (decimal 12,2 default 0)   // e.g. your $5 margin/fee
- total (decimal 12,2)           // subtotal + fee
- status (enum/string: pending_payment, paid, processing, fulfilled, failed, refunded, cancelled)
- paid_at (nullable timestamp)
  - meta (nullable json)           // client hints: ip, user_agent, etc.
- created_at, updated_at

Indexes:
- user_id, status, created_at
- unique(order_number)

B) order_items table
Purpose: immutable snapshot of what the user bought.
Fields:
- id
- order_id (FK)
- product_id (FK -> products.id) nullable if needed
- package_id (FK -> packages.id) nullable if needed
- name (string)                 // snapshot (don’t rely on products table later)
- unit_price (decimal 12,2)
- quantity (unsigned int)
- line_total (decimal 12,2)
- requirements_payload (json nullable)  // e.g. {"id":"...", "region":"..."}
- status (enum/string: pending, processing, fulfilled, failed)
- created_at, updated_at

Indexes:
- order_id
- product_id, package_id
- status

C) Input contract: cart payload format
Assume the frontend sends an array similar to:
[
{
"product_id": 40,
"package_id": 12,              // optional
"quantity": 2,
"unit_price": "100.00",        // or price field name; normalize
"name": "Google Play 100$",
"requirements": { "account_id": "12345" } // optional
}
]
If current frontend payload differs, detect it by searching existing Livewire/Controllers and adapt accordingly.

SECURITY / PRICING (MANDATORY):
- The cart payload coming from Alpine/localStorage is UNTRUSTED.
- DO NOT trust unit_price, line_total, subtotal, total from the client.
- The payload may include only identifiers + quantity + requirements.

Server must:
1) For each item:
    - Validate quantity is an integer >= 1.
    - Resolve the “buyable” record from DB:
        - If package_id exists, load Package (and its related Product if needed).
        - Else load Product by product_id.
    - Determine the authoritative unit_price from the database (based on current pricing rules).
    - Compute line_total = unit_price * quantity (server-side).
    - Snapshot name from DB (product/package name) into order_items.name.
2) Compute subtotal = sum(line_total).
3) Compute fee server-side:
    - Use a config value (e.g., config('billing.checkout_fee_fixed') or percentage)
    - Document the rule in a comment.
4) total = subtotal + fee.

Validation rules:
- Reject any item where referenced product/package does not exist.
- Reject quantities that are 0 or negative.
- If requirements_payload is present, validate required keys based on packages_requirements (if implemented). If not implemented yet, store payload but keep TODO note.

Result:
- Create Order + OrderItems using ONLY server-calculated prices/totals.
- Return Order id + order_number + totals.


D) CreateOrderFromCartPayload action (server-side)
- Validate payload strictly (quantity >0, prices >=0, ids exist).
- Compute subtotal = sum(line_total).
- Apply fee logic (for now: configurable fixed fee or percentage; keep it simple and documented).
- Create Order with status = pending_payment.
- Create OrderItems with snapshot fields (name, unit_price, quantity, line_total, requirements_payload).
- Return created Order (id + order_number + totals).

E) PayOrderWithWallet action (atomic + safe)
- DB transaction
- lock wallet row FOR UPDATE
- ensure order.status is pending_payment
- **Current (2026-07):** gate spend with `WalletSpendPolicy` / `availableToSpend()` (customer credit facility may allow balance to go negative). Do **not** use raw `balance >= order.total` as the sole check.
- create wallet_transaction:
    - type='purchase'
    - direction='debit'
    - amount=order.total
    - status='posted'
    - reference_type='Order'
    - reference_id=order.id
    - meta includes order_number
- decrement wallet.balance
- set order.status='paid', paid_at=now()

Idempotency rules:
- Paying twice must not double-debit.
- Enforce by checking order.status or existing wallet_transaction reference.

Models:
- Order belongsTo User, hasMany OrderItem
- OrderItem belongsTo Order
- Add casts for json fields.

Quality requirements:
- Laravel 12 conventions
- strict types
- no debug logs
- clean, production-ready code
- minimal but correct indexes/constraints
- Use enums if the repo already uses them; otherwise constants.

Deliverables:
1) migrations for orders + order_items
2) models Order + OrderItem with relations/casts
3) service/action classes: CreateOrderFromCartPayload, PayOrderWithWallet
4) brief comments explaining why snapshot fields exist (name/unit_price stored on items)

Before coding:
- Scan existing schema: products, packages, packages_requirements.
- Decide whether order_item should reference product_id, package_id, or both based on current DB.
- Follow the project naming/style patterns.

If anything critical is ambiguous, ask ONE question before coding.








## Commission clawback tables (Track B / M7)

**Branch:** `local/commission-policy` (may be absent on staging until merge).

**Policy:** Clawbacks are **prospective only** (`billing.commission_clawback.effective_at`). Historical exposure is report/review only — **never** posts wallet debits or creates obligations.

### `commission_clawbacks`

| Concept | Notes |
|---------|-------|
| `public_ref` | **`CLB-*`** |
| `status` | `pending` \| `processing` \| `posted` \| `needs_review` \| `failed` \| `waived` |
| `commission_id` | FK → `commissions` |
| `refund_wallet_transaction_id` | FK → customer refund WTX |
| `amount` | Obligation amount |
| Unique | `(commission_id, refund_wallet_transaction_id)` |

### `commission_clawback_decisions`

| Concept | Notes |
|---------|-------|
| `public_ref` | **`CLD-*`** |
| `type` | `waiver` \| `correction` \| `dispute_opened` \| `dispute_resolved` |
| Links | Optional related wallet TX for posted waiver/correction |

### `historical_commission_exposure_reviews`

| Concept | Notes |
|---------|-------|
| Pair | Unique `(commission_id, refund_wallet_transaction_id)` |
| `outcome` | `platform_absorbed` \| `not_actionable` \| `insufficient_data` \| `duplicate_or_invalid` \| `deferred_review` |
| Money | **None** — markers only |

### Related wallet TX types (salesperson)

| Type | Direction | Purpose |
|------|-----------|---------|
| `commission_reversal` | Debit | Automatic clawback after refund |
| `commission_clawback_waiver` | Credit | Forgiveness |
| `commission_reversal_correction` | Credit | Fix erroneous reversal |

Original reversal is immutable; cumulative waiver + correction ≤ posted reversal.


