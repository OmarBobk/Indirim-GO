# karman.store — AI Feature Delivery Context

Use this as the primary prompt context for AI tools that will plan or implement new features.

---

## 0. AI operator contract (read first)

- **Financial truth:** `wallet_transactions` + `wallets.balance` are authoritative. `system_events` is a mirror/timeline only.
- **Pricing truth:** server-side pricing is authoritative (`app/Domain/Pricing/*`, `CustomerPriceService`, `PriceCalculator`). Never trust client totals.
- **Cart model:** cart state is client-side (`localStorage` key `karman.cart.v1`), but checkout always revalidates and recalculates on server.
- **Access model:** backend routes are hidden by `backend` middleware and permission checks (404 on denial by design).
- **Mutation safety:** financial writes must stay transactional and idempotent (`lockForUpdate`, idempotency keys, `DB::afterCommit` side effects).

---

## 1. Project identity

- **Repo:** karman.store.
- **Owner:** Omar.
- **Frontend brand:** İndirimGo.
- **Product type:** Laravel commerce + wallet operations platform (catalog, wallet, orders, fulfillments, topups, refunds, settlements, loyalty, bug ops, referral commissions, commission wallet payouts).

---

## 2. Stack baseline

- **PHP/Laravel:** PHP 8.4.x, Laravel 12, Livewire 4.
- **Frontend:** Blade + Alpine + Tailwind v4 + Flux free components.
- **Auth/ACL:** Laravel Fortify + Spatie permissions/roles.
- **Realtime:** Reverb + Echo/Pusher protocol.
- **Testing/style:** Pest + PHPUnit, Pint.
- **PWA:** `erag/laravel-pwa` with permission-aware install button.

---

## 3. Architecture map (where to implement changes)

- **Routes:** `routes/web.php`, `routes/channels.php`.
- **Domain actions:** `app/Actions/*` (Orders, Fulfillments, Topups, Refunds, Pricing, Users, Commissions, etc).
- **Pricing domain:** `app/Domain/Pricing/PricingEngine.php`, `CustomAmountValidator.php`, `PriceQuoteDTO.php`.
- **Financial services:** `SystemEventService`, `OperationalIntelligenceService`.
- **UI/state boundary:** Livewire for server state; Alpine for UI/cart state. Dashboard UI uses Blade components under `resources/views/components/dashboard/*` plus service-built payloads.
- **Observability:** Spatie activity log + `system_events` + push logs.

---

## 4. Critical invariants (do not break)

1. Every wallet balance mutation corresponds to one posted wallet transaction and one financial system event.
2. Never derive balances from `system_events`.
3. Payment/refund/topup/settlement writes are done inside DB transactions with row locks.
4. Idempotency on money paths is mandatory (`purchase:order:{id}`, `refund:*`, `settlement:{id}`).
5. Notification/realtime emissions happen after commit (`DB::afterCommit`).
6. Custom-amount lines remain quantity-1 semantic lines with `requested_amount`.
7. Pricing-rule coverage must include computed custom-amount entry totals.
8. Backend visibility must remain permission-based (no role-only shortcuts).
9. Commission payouts are wallet credits (`commission_credit`) and must be idempotent by `commission_credit:{commission_id}`.

---

## 5. Authentication, permissions, and roles

- **Fortify config reality:** `username` auth key, `lowercase_usernames=true`, `home='/'`, registration currently enabled in features array.
- **Backend gate:** `EnsureBackendAccess` checks `config('permission.backend_permissions')` and returns 404 when blocked.
- **Backend permissions list:** `view_dashboard`, `manage_users`, `manage_sections`, `manage_products`, `manage_topups`, `view_referrals`, `create_orders`, `edit_orders`, `delete_orders`, `view_orders`, `view_fulfillments`, `manage_fulfillments`, `view_refunds`, `process_refunds`, `view_activities`, `manage_settlements`, `manage_bugs`, `update_product_prices`.
- **Important nuance:** `manage_user_prices` exists for per-user price overrides but is not itself a backend-entry permission.
- **Roles:** admin, supervisor, salesperson, customer.

---

## 6. Role-based feature surface

- **Customer:** browse catalog, cart, buy-now/custom amount, wallet + topups, orders/details, loyalty, referral link page when allowed by `view_referrals`, notifications, locale switch.
- **Supervisor/operations:** fulfillment queues and claim workflow, refunds, topups, customer funds, settlements, bugs inbox.
- **Salesperson:** `view_referrals` dashboard, referral link, referral-driven order/commission analytics, eligible payout visibility.
- **Admin:** all ops pages + system events + user management + commissions management + website settings.

---

## 7. Pricing and checkout flow

- **Buy-now quote API is active (not a stub):** `POST /api/pricing/buy-now-custom-amount-quote` via `BuyNowCustomAmountQuoteController`.
- **Checkout orchestration:** `CheckoutFromPayload` -> `CreateOrderFromCartPayload` -> `PayOrderWithWallet` -> `CreateFulfillmentsForOrder`.
- **Custom amount validation:** min/max/step/hard-cap rules; server reprices via pricing domain + services.
- **Order snapshots:** `pricing_meta` persists decision inputs/results for auditability.

---

## 8. Financial core (wallet, topup, refund, settlement)

- **Wallet ledger:** posted tx sum mirrors stored balance; reconcile command validates and fixes drift.
- **Transaction types:** topup, purchase, refund, adjustment, settlement, **commission_credit**.
- **Topup creation:** `CreateTopupRequestAction` atomically creates topup request + pending wallet tx.
- **Topup conversion behavior:** TRY-entered topups are converted to USD ledger values using configured rate.
- **Topup proof UI behavior:** wallet page gates file requirement with `attachProof`; proof optional when disabled.
- **Refund posting:** `ApproveRefundRequest` enforces duplicate-refund protection before posting credit.
- **Settlement:** `profit:settle` posts platform settlement transactions idempotently.

---

## 9. Fulfillment operations

- **Claim model:** explicit `claimed_by` / `claimed_at`; claim only when queued/unclaimed.
- **Task cap:** claim flow enforces max active processing tasks per actor.
- **Ownership semantics:** non-admin updates tied to claim ownership; policies enforce boundaries.
- **Custom amount fulfillment:** one fulfillment per custom amount order line.

---

## 10. Referral and commissions domain

- **Referral attribution:** query param `?ref=` captured by `CaptureReferralFromQuery` middleware to signed cookie.
- **Config:** `config/referral.php` (`cookie_name`, `cookie_ttl_minutes`).
- **User fields:** referral code + referred-by linkage (`referral_code`, `referred_by_user_id`).
- **Commission model:** `commissions` table, `CommissionStatus` enum (`pending`, `credited`, `failed`), commission rate snapshots, optional `payout_batch_id`, and unique `wallet_transaction_id`.
- **Creation trigger:** commissions are generated in `PayOrderWithWallet` after order payment/fulfillment creation.
- **Failure interaction:** refund approval marks related pending commissions as failed.
- **Payout flow:** admins use `CreatePayoutBatch` through `/admin/commissions` (`can:manage_settlements`) to credit eligible completed/aged commissions to salesperson wallets. It creates `payout_batches`, posts `commission_credit` wallet transactions, records `wallet.commission.credited`, marks commissions `credited`, and notifies recipients after commit.
- **Eligibility:** commission must be pending, not already batched/credited, order paid older than `WebsiteSetting::getCommissionPayoutWaitDays()`, and related fulfillment(s) completed; payout total must meet `WebsiteSetting::getCommissionPayoutMinAmount()` unless explicitly bypassed for a single admin credit.
- **Salesperson dashboard:** `/salesperson-dashboard` (`can:view_referrals`) uses `SalespersonDashboardService` + `resources/views/components/dashboard/*` for KPI hero charts, payout card, leaderboard, orders table, and earnings history. Frontend `/referral-link` also requires `can:view_referrals`.

---

## 11. Realtime, notifications, and bugs

- **User private channel:** `private-App.Models.User.{id}`.
- **Admin channels:** fulfillments, topups, activities, system-events, bugs.
- **Bug operations:** quick report flow + `/admin/bugs` inbox + attachment access route.
- **Push hygiene:** dedup by notification id, invalid token cleanup, telemetry in `push_logs`.

---

## 12. Localization and currency presentation

- **Locales:** en/ar; session + persisted user preference (`locale`, `locale_manually_set`).
- **Language switch route:** `language/{locale}` updates session and authenticated user preference lock.
- **Login locale sync:** coordinated through `SyncAuthenticatedUserLocale` and `SupportedLocale`.
- **Currency display parity:** use `FrontendMoney` to keep Blade formatting aligned with JS behavior.

---

## 13. AI-safe implementation checklist

- Read first: `routes/web.php`, target Action class, relevant Policy, and related tests before editing.
- Preserve invariants in sections 4 and 8 for any money/order/fulfillment change.
- For pricing changes, verify both fixed and custom-amount paths.
- For permission changes, verify route middleware + policy + UI gating together.
- For realtime changes, ensure no event is emitted before transaction commit.
- Add/update tests in `tests/Feature` for regression-prone behavior.

---

## 14. Recent architecture milestones (last 30 days)

- **2026-03-31 to 2026-04-01:** custom amount pricing hardening and domain pricing layer introduction (`23f0a0d`, `f1306d3`, `dc4c2dd`).
- **2026-04-01 to 2026-04-02:** service worker/push reliability improvements (`55483be`, `a6958f6`, `f729038`, `76488d8`, `78e5414`).
- **2026-04-06:** settlement and refund behavior refinements (`f6c8d67`, `cd2831a`).
- **2026-04-09 to 2026-04-11:** tighter dashboard and price-entry permissions (`3cdb862`, `9b8d8d1`, `5760e1b`).
- **2026-04-10:** locale preference flow and USD/TRY support (`73d9847`, `fc3cdc3`, `be3ee4c`).
- **2026-04-24:** topup TRY->USD conversion behavior (`b4b5041`).
- **2026-04-30:** referral + commissions system launch, commission rate management, and salesperson dashboard display upgrades (`70a5844`, `3ff1205`, `f8df282`).
- **2026-05-02:** commission payout batching/wallet crediting and `view_sales` -> `view_referrals` permission migration (`f7d0d97`, `10cbfbb`).

---

## 15. Routes quick reference

- **Public:** `/`, `/categories/{category:slug}`, `/cart`, `/contact`, `/404`, `language/{locale}`.
- **Auth+verified (storefront):** `/profile`, `/wallet`, `/loyalty`, `/referral-link`, `/orders`, `/orders/{order_number}`, `/notifications`, `/topup-proofs/{proof}`, `/bug-attachments/{attachment}`, `POST /api/pricing/buy-now-custom-amount-quote`.
- **Backend:** `/dashboard` (`can:view_dashboard`), `/salesperson-dashboard` (`can:view_referrals`), `/categories`, `/packages`, `/products`, `/product-entry-prices` (`can:update_product_prices`), `/pricing-rules`, `/loyalty-tiers`, `/admin/orders/*`, `/admin/users/*`, `/admin/users/{user}/audit`, `/fulfillments`, `/refunds`, `/topups`, `/customer-funds`, `/settlements`, `/admin/commissions` (`can:manage_settlements`), `/admin/notifications`, `/admin/bugs/*`, `/admin/website-settings` (admin only).

---

## 16. Primary source files for AI prompts

- `routes/web.php`, `routes/channels.php`
- `config/permission.php`, `config/fortify.php`, `config/referral.php`
- `app/Actions/Orders/CheckoutFromPayload.php`, `CreateOrderFromCartPayload.php`, `PayOrderWithWallet.php`
- `app/Actions/Commissions/CreatePayoutBatch.php`
- `app/Actions/Refunds/ApproveRefundRequest.php`
- `app/Actions/Fulfillments/ClaimFulfillment.php`, `CreateFulfillmentsForOrder.php`
- `app/Services/SystemEventService.php`, `OperationalIntelligenceService.php`
- `app/Services/SalespersonDashboardService.php`, `resources/views/components/dashboard/*`
- `app/Domain/Pricing/*`
- `resources/js/app.js`
