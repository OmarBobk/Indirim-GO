# karman.store — AI Feature Delivery Context

Use this as the primary prompt context for AI tools that will plan or implement new features.

---

## 0. AI operator contract (read first)

- **Financial truth:** `wallet_transactions` + `wallets.balance` are authoritative. `system_events` is a mirror/timeline only.
- **Pricing truth:** server-side pricing is authoritative (`app/Domain/Pricing/*`, `CustomerPriceService`, `PriceCalculator`). Never trust client totals.
- **Cart model:** cart state is client-side (`localStorage` key `karman.cart.v1`), but checkout always revalidates and recalculates on server.
- **Access model:** backend routes are hidden by `backend` middleware and permission checks (404 on denial by design).
- **Mutation safety:** financial writes must stay transactional and idempotent (`lockForUpdate`, idempotency keys, `DB::afterCommit` side effects).
- **Agent rules:** follow `.cursor/rules/laravel-boost.mdc` for stack versions, conventions, and karman.store financial guardrails.

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

- **Routes:** `routes/web.php`, `routes/automation.php`, `routes/channels.php`, `routes/console.php`.
- **Domain actions:** `app/Actions/*` (Orders, Fulfillments, Topups, Refunds, Pricing, Users, Commissions, Packages, …).
- **Pricing domain:** `app/Domain/Pricing/PricingEngine.php`, `CustomAmountValidator.php`, `PriceQuoteDTO.php`.
- **Financial services:** `SystemEventService`, `OperationalIntelligenceService`.
- **Fulfillment automation:** `FulfillmentAutomationService`, `app/Actions/Fulfillments/*Automation*`, `app/Jobs/DispatchFulfillmentAutomationJob.php`, worker callbacks in `FulfillmentAutomationCallbackController`.
- **Browser worker (Node/Playwright):** `automation-worker/` — executes supplier drivers; Laravel owns all business state.
- **UI/state boundary:** Livewire for server state; Alpine for UI/cart state. Dashboard UI uses Blade components under `resources/views/components/dashboard/*` plus service-built payloads.
- **Observability:** Spatie activity log + `system_events` + push logs + fulfillment automation run records/artifacts.

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
- **Admin:** all ops pages + system events + user management + commissions management + website settings + **fulfillment automation admin** (`/admin/automation`) + **Ops Assistant** (`/admin/assistant`, read-only AI lookups).

---

## 7. Pricing and checkout flow

- **Buy-now quote API is active (not a stub):** `POST /api/pricing/buy-now-custom-amount-quote` via `BuyNowCustomAmountQuoteController`.
- **Checkout orchestration:** `CheckoutFromPayload` -> `CreateOrderFromCartPayload` -> `PayOrderWithWallet` -> `CreateFulfillmentsForOrder`.
- **Checkout result:** `CheckoutFromPayload::handle()` returns `CheckoutResult` (`order` + `reusedExistingOrder`) for honest UI messaging.
- **Cart idempotency (`cart_hash` in order meta):**
  - **`PendingPayment`** orders with the same cart fingerprint are always reused (resume abandoned checkout).
  - **`Paid`** orders are reused only within **`config('billing.checkout_paid_idempotency_minutes')`** (default 5; env `BILLING_CHECKOUT_PAID_IDEMPOTENCY_MINUTES`) — double-submit protection, not lifetime dedup.
  - Older paid orders with the same cart allow **repurchase** (fixes ghost success when admin failed fulfillments without refund).
  - Cart + buy-now show `checkout_order_already_placed` when reusing a recent paid order; otherwise `payment_successful_order_processing`.
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

### Browser fulfillment automation

- **Provider assignment:** packages store `fulfillment_provider` (`null` = manual, `browser:{supplier}` = automated). Packages admin table has a pill toggle (`TogglePackageFulfillment`); edit form selects supplier + optional `package_api`.
- **Eligibility:** `FulfillmentAutomationService::isEligible()` — requires env config + `WebsiteSetting::automation_enabled` + queued unclaimed browser fulfillment on paid order, no active/succeeded run, no blocking refund. Separate **`isEligibleForReconcile()`** for Wasim reconcile phase.
- **Run lifecycle (purchase):** `ReserveFulfillmentAutomationRun` → `DispatchFulfillmentAutomationRun` (HMAC-signed POST to worker) → worker callbacks → `IngestFulfillmentAutomationResult`.
- **Ingest outcomes:** `success`, `failed`, `needs_review`, **`submitted`** (Wasim purchase accepted at supplier — fulfillment stays processing), **`pending_reconcile`** (order not terminal yet on supplier orders page).
- **Wasim two-phase flow:**
  1. **Purchase** (`automation_phase: purchase`) — worker submits order on product page; popup `Processing_OK_wait` + supplier order id → `submitted` → schedule reconcile.
  2. **Reconcile** (`automation_phase: reconcile`) — worker opens Wasim customer orders page, checks Cancelled → Completed → New tabs; outcomes map to failed (cancelled + auto-refund path), success (complete fulfillment), or `pending_reconcile` (retry with backoff).
  - Jobs: `ScheduleWasimOrderReconcile`, `DispatchWasimReconcileJob`, `ReserveFulfillmentAutomationReconcileRun`.
- **Run statuses:** `reserved`, `dispatched`, `running`, `succeeded`, `failed`, `needs_review`, `cancelled` (`FulfillmentAutomationRunStatus`).
- **Scheduled dispatch:** `fulfillment:dispatch-automation` (every minute when enabled); stale sweep: `fulfillment:sweep-stale-automation-runs`.
- **Admin UI:** `/admin/automation` (admin role) — runs inbox (Reverb live updates, no polling), needs-review queue, worker health/build check, Wasim credential form, **clear browser session**, collapsible flow guide, purchase/reconcile detail columns.
- **Wasim credentials:** `website_settings.wasim_automation_username` / `wasim_automation_password` (encrypted) override env; saving credentials calls worker **`POST /v1/sessions/clear`** for `wasim-main` session. Worker stores credential fingerprint per session and invalidates stale Playwright `storageState` when credentials change.
- **Intervention actions:** `CancelFulfillmentAutomationRun`, `RetryFulfillmentAutomation`, admin claim cancels active runs (`ClaimFulfillment`).
- **Worker:** `automation-worker/` Playwright service; Wasim drivers: `submitPurchase`, `reconcileOrder`, `ordersPageHelpers`; suppliers in `config/fulfillment_automation.php`.
- **Callbacks:** `POST /internal/automation/runs/{uuid}/result|artifacts` (CSRF exempt, HMAC middleware). Worker also exposes **`POST /v1/sessions/clear`** (HMAC). Artifacts at `admin/fulfillment-automation/runs/{run}/artifact`.

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
- **Admin channels:** fulfillments, topups, activities, system-events, bugs, **`admin.automation`** (automation run inbox).
- **Bug operations:** quick report flow + `/admin/bugs` inbox + attachment access route.
- **Push hygiene:** dedup by notification id, invalid token cleanup, telemetry in `push_logs`.

---

## 11b. Ops Assistant (admin AI)

- **Page:** `/admin/assistant` — `App\Livewire\Admin\AssistantChat` (admin role + `throttle:20,1`).
- **Sidebar:** Operations group link **Ops Assistant** (`chat-bubble-left-right` icon) for `@role('admin')`.
- **Agent:** `App\Ai\Agents\OpsAssistant` — read-only lookups via Laravel AI tools (`LookupOrderTool`, `LookupWalletTool`, `LookupFulfillmentTool`) backed by `app/Actions/AiAssistant/Fetch*Data.php`.
- **Persistence:** per-admin conversation key `ops_assistant.conversation.{user_id}` (Laravel AI conversations).
- **Config:** `OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_BASE_URL` in `.env`; UI errors when missing/invalid/quota.
- **MCP (optional):** `routes/ai.php` — `POST /mcp/ops-assistant` (`OpsAssistantServer`) for external MCP clients; admin-authenticated.

---

## 12. Localization and currency presentation

- **Locales:** en/ar; session + persisted user preference (`locale`, `locale_manually_set`).
- **Language switch route:** `language/{locale}` updates session and authenticated user preference lock.
- **Login locale sync:** coordinated through `SyncAuthenticatedUserLocale` and `SupportedLocale`.
- **Currency display parity:** use `FrontendMoney` to keep Blade formatting aligned with JS behavior.

---

## 13. AI-safe implementation checklist

- Read first: `routes/web.php`, `routes/automation.php`, target Action class, relevant Policy, and related tests before editing.
- Preserve invariants in sections 4 and 8 for any money/order/fulfillment change.
- For pricing changes, verify both fixed and custom-amount paths.
- For permission changes, verify route middleware + policy + UI gating together.
- For realtime changes, ensure no event is emitted before transaction commit.
- For automation changes, preserve HMAC callback verification, idempotent run ingestion, and eligibility guards; do not move financial truth into worker callbacks.
- Prefer Actions over new Services unless orchestration/IO warrants it; keep Livewire thin.
- Add/update tests in `tests/Feature` for regression-prone behavior (`FulfillmentAutomationTest`, `AutomationAdminTest`, `CheckoutFlowTest`, `AiAssistant/*`, `PackagesPageTest`).

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
- **2026-05-28 to 2026-06-02:** browser fulfillment automation — worker service, run model, dispatch/sweep commands, HMAC callbacks, admin automation page, package fulfillment toggles, `WebsiteSetting::automation_enabled` kill switch.
- **2026-06:** Wasim **two-phase** automation (purchase `submitted` → reconcile on supplier orders page); Wasim admin credentials + worker session clear; checkout **`cart_hash`** idempotency limited to pending + short paid window (`CheckoutResult`); **Ops Assistant** admin chat (`/admin/assistant`, sidebar nav, read-only order/wallet/fulfillment lookups).

---

## 15. Routes quick reference

- **Public:** `/`, `/categories/{category:slug}`, `/cart`, `/contact`, `/404`, `language/{locale}`.
- **Auth+verified (storefront):** `/profile`, `/wallet`, `/loyalty`, `/referral-link`, `/orders`, `/orders/{order_number}`, `/notifications`, `/topup-proofs/{proof}`, `/bug-attachments/{attachment}`, `POST /api/pricing/buy-now-custom-amount-quote`.
- **Backend:** `/dashboard` (`can:view_dashboard`), `/salesperson-dashboard` (`can:view_referrals`), `/categories`, `/packages`, `/products`, `/product-entry-prices` (`can:update_product_prices`), `/pricing-rules`, `/loyalty-tiers`, `/admin/orders/*`, `/admin/users/*`, `/admin/users/{user}/audit`, `/fulfillments`, `/refunds`, `/topups`, `/customer-funds`, `/settlements`, `/admin/commissions` (`can:manage_settlements`), `/admin/notifications`, `/admin/bugs/*`, `/admin/website-settings` (admin only), **`/admin/automation`** (admin only), **`/admin/assistant`** (admin only, throttled).
- **Automation (internal):** `POST /internal/automation/runs/{uuid}/result`, `POST /internal/automation/runs/{uuid}/artifacts` (HMAC-signed, CSRF exempt). Worker: `POST /v1/runs`, **`POST /v1/sessions/clear`** (HMAC).
- **AI/MCP:** `POST /mcp/ops-assistant` (admin MCP server for read-only ops tools).

---

## 16. Primary source files for AI prompts

- `routes/web.php`, `routes/automation.php`, `routes/channels.php`, `routes/console.php`
- `config/permission.php`, `config/fortify.php`, `config/referral.php`, **`config/fulfillment_automation.php`**, **`config/billing.php`** (`checkout_paid_idempotency_minutes`)
- `app/Actions/Orders/CheckoutFromPayload.php`, **`CheckoutResult.php`**, `CreateOrderFromCartPayload.php`, `PayOrderWithWallet.php`
- `app/Actions/Commissions/CreatePayoutBatch.php`
- `app/Actions/Refunds/ApproveRefundRequest.php`
- `app/Actions/Fulfillments/ClaimFulfillment.php`, `CreateFulfillmentsForOrder.php`, **`DispatchFulfillmentAutomationRun.php`**, **`IngestFulfillmentAutomationResult.php`**, **`ScheduleWasimOrderReconcile.php`**, **`RetryFulfillmentAutomation.php`**
- `app/Actions/Packages/TogglePackageFulfillment.php`, `UpsertPackage.php`
- `app/Actions/AiAssistant/FetchOrderData.php`, `FetchWalletData.php`, `FetchFulfillmentData.php`
- `app/Services/FulfillmentAutomationService.php` (incl. **`clearWorkerBrowserSession()`**), `SystemEventService.php`, `OperationalIntelligenceService.php`
- `app/Services/SalespersonDashboardService.php`, `resources/views/components/dashboard/*`
- `app/Domain/Pricing/*`
- `app/Ai/Agents/OpsAssistant.php`, `app/Livewire/Admin/AssistantChat.php`, `app/Livewire/Admin/AutomationMonitor.php`
- `app/Models/FulfillmentAutomationRun.php`, `WebsiteSetting.php` (`automation_enabled`, **`wasim_automation_*`**)
- `resources/views/livewire/admin/automation-monitor.blade.php`, `resources/views/livewire/admin/assistant-chat.blade.php`, `resources/views/pages/backend/fulfillments/⚡index.blade.php`
- `resources/views/layouts/app/sidebar.blade.php` (Automation + Ops Assistant nav)
- `automation-worker/README.md`, `automation-worker/src/drivers/wasim/*`, `automation-worker/src/server.ts` (`/v1/sessions/clear`)
- `resources/js/app.js`
- **Agent rules:** `.cursor/rules/laravel-boost.mdc` (stack versions, financial guardrails, testing/Pint/Livewire conventions)
