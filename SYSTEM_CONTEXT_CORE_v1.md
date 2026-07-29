# karman.store — AI Feature Delivery Context

Use this as the primary prompt context for AI tools that will plan or implement new features.

**Obsidian vault (`Vault/`):** feature briefs, domain notes, and the Ask → Plan → Agent pipeline. Start at `Vault/İndirimGo Index.md` when present (legacy `Vault/Karman Index.md` otherwise). ChatGPT Project setup: `Docs/CHATGPT_PROJECT_PROMPT.md`.

---

## 0. AI operator contract (read first)

- **Financial truth:** `wallet_transactions` + `wallets.balance` are authoritative. `system_events` is a mirror/timeline only.
- **Pricing truth:** server-side pricing is authoritative (`app/Domain/Pricing/*`, `CustomerPriceService`, `PriceCalculator`). Never trust client totals.
- **Cart model:** cart state is client-side (`localStorage` key `karman.cart.v1`), but checkout always revalidates and recalculates on server.
- **Access model:** backend routes are hidden by `backend` middleware and permission checks (404 on denial by design).
- **Mutation safety:** financial writes must stay transactional and idempotent (`lockForUpdate`, idempotency keys, `DB::afterCommit` side effects).
- **Mobile auth:** customer-only `/api/v1` requires real Sanctum bearer PATs (web-session fallback rejected) with `mobile:access`, explicit 30-day expiry, no refresh token, and Fortify-backed 2FA challenges. Contract: `docs/api/v1/openapi.yaml`.
- **Optional realtime isolation:** authentication and other authoritative flows must not fail when optional Reverb/Pusher publication fails. Durable activity rows remain; `ActivityLogBroadcaster` isolates `ActivityLogChanged` transport errors with safe logs (no signed broadcaster URLs/secrets).
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
- **Frontend:** Blade + Alpine + **Tailwind CSS 4.1** + Flux **FREE** components only (no Pro).
- **Flux overrides:** published views under `resources/views/flux/` (including modal); project CSS in `resources/css/app.css` (e.g. `.admin-themed-modal`).
- **Auth/ACL:** Laravel Fortify + Spatie permissions/roles.
- **AI/MCP:** `laravel/ai` (Ops Assistant agent) + `laravel/mcp` (`POST /mcp/ops-assistant`).
- **Realtime:** Reverb + Echo/Pusher protocol.
- **Testing/style:** Pest + PHPUnit, Pint.
- **PWA:** `erag/laravel-pwa` with permission-aware install button.

---

## 3. Architecture map (where to implement changes)

- **Routes:** `routes/web.php`, `routes/automation.php`, `routes/channels.php`, `routes/console.php`.
- **Domain actions:** `app/Actions/*` (Orders, Fulfillments, Topups, Refunds, Pricing, Users, Commissions, Packages, SupplierPrices, Dashboard, …).
- **Pricing domain:** `app/Domain/Pricing/PricingEngine.php`, `CustomAmountValidator.php`, `PriceQuoteDTO.php`.
- **Registration security domain:** `app/Domain/Security/*` (Turnstile, honeypot, registration rate limits) — public self-register only.
- **Financial services:** `SystemEventService`, `OperationalIntelligenceService`, `WalletSpendPolicy`, `WalletLedger`.
- **Fulfillment automation:** `FulfillmentAutomationService`, `app/Actions/Fulfillments/*Automation*`, `app/Jobs/DispatchFulfillmentAutomationJob.php`, worker callbacks in `FulfillmentAutomationCallbackController`.
- **Supplier price scans:** `SupplierPriceScanService`, `app/Actions/SupplierPrices/*`, admin UI `/price-drift`, worker price-scan callbacks.
- **Browser worker (Node/Playwright):** `automation-worker/` — executes supplier drivers + price scans; Laravel owns all business state.
- **UI/state boundary:** Livewire for server state; Alpine for UI/cart state. Dashboard UI uses Blade components under `resources/views/components/dashboard/*` plus service-built payloads. Customer order “delivered” display uses `CustomerDeliveredPayload` (filters automation internals, extracts image URLs).
- **Observability:** Spatie activity log + `system_events` + push logs + fulfillment automation run records/artifacts; admin exception badge counts via `GetAdminExceptionCounts`.

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
10. Customer wallet balance **may be negative** under an Active credit facility; spend checks use `WalletSpendPolicy` / `availableToSpend()`, not raw `balance >= total`. Platform wallets never overdraft.

---

## 5. Authentication, permissions, and roles

- **Fortify config reality:** `username` auth key, `lowercase_usernames=true`, `home='/'`, registration currently enabled in features array.
- **Public registration security (self-register only):** `App\Http\Controllers\Auth\RegisteredUserController` runs `GuardRegistrationAttempt` before `CreateNewUser`. Controls: honeypot (`config('security.registration.honeypot_field')`), IP/email rate limits (`config/security.php` / `REGISTRATION_*` env), Cloudflare Turnstile (`config('services.turnstile')` / `TURNSTILE_*`). Local: set `TURNSTILE_ENABLED=false`. Admin/salesperson-created users bypass these guards.
- **Backend gate:** `EnsureBackendAccess` checks `config('permission.backend_permissions')` and returns 404 when blocked.
- **Backend permissions list:** `view_dashboard`, `manage_users`, `manage_sections`, `manage_products`, `manage_topups`, `adjust_wallets`, `manage_wallet_credit`, `view_referrals`, `create_orders`, `edit_orders`, `delete_orders`, `view_orders`, `view_fulfillments`, `manage_fulfillments`, `view_refunds`, `process_refunds`, `view_activities`, `manage_settlements`, `manage_bugs`, `update_product_prices`.
- **Important nuance:** `manage_user_prices` exists for per-user price overrides but is not itself a backend-entry permission.
- **Roles:** admin, supervisor, salesperson, customer.

---

## 6. Role-based feature surface

- **Customer:** browse catalog, cart, buy-now/custom amount, wallet + topups (balance may be negative when credit facility is active), orders/details, loyalty, referral link page when allowed by `view_referrals`, notifications, locale switch.
- **Supervisor/operations:** fulfillment queues and claim workflow, refunds, topups, customer funds, settlements, bugs inbox; credit facility ops when granted `manage_wallet_credit`.
- **Salesperson:** `view_referrals` dashboard, referral link, referral-driven order/commission analytics, eligible payout visibility.
- **Admin:** all ops pages + system events + user management + commissions management + website settings + **credit facility** (`/credit-facility`, `can:manage_wallet_credit`) + **fulfillment automation admin** (`/admin/automation`) + **Ops Assistant** (`/admin/assistant`, read-only AI lookups) + **Wasim price drift** (`/price-drift`, `can:update_product_prices`).

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

## 8. Financial core (wallet, topup, refund, settlement, credit facility)

- **Wallet ledger:** posted tx sum mirrors stored balance; reconcile command validates and fixes drift.
- **Transaction types:** topup, purchase, refund, adjustment, settlement, **commission_credit**.
- **Topup creation:** `CreateTopupRequestAction` atomically creates topup request + pending wallet tx.
- **Topup conversion behavior:** TRY-entered topups are converted to USD ledger values using configured rate.
- **Topup proof UI behavior:** wallet page gates file requirement with `attachProof`; proof optional when disabled.
- **Refund posting:** `ApproveRefundRequest` enforces duplicate-refund protection before posting credit.
- **Settlement:** `profit:settle` posts platform settlement transactions idempotently.

### Credit facility / overdraft (customer wallets)

- **Model:** one customer wallet per user; `wallets.balance` **may go negative** when a credit facility is granted and Active. Platform wallets never have a credit facility (effective limit always `0`).
- **Debt repayment:** topups/credits increase balance via normal arithmetic — there is **no** separate repayment flow. Paying down debt is just posting credits.
- **Fields (keep both grant + operational status):**
  - `credit_enabled` — facility granted (bool)
  - `credit_limit` — max overdraft ceiling (decimal)
  - `payment_terms_days` — Net N terms (nullable when not granted)
  - `credit_status` — nullable `Active`/`Suspended` when granted; **must be `null` when not granted**
- **Invalid combos forbidden:** disabled ⇒ `credit_status` null; enabled ⇒ `Active`|`Suspended` only. Do **not** consolidate `credit_enabled` into status.
- **Wallet helpers:** `effectiveCreditLimit()`, `minimumAllowedBalance()`, `availableToSpend()`, `availableCredit()`, `outstandingDebt()`, `isOverdrawn()`. Effective limit requires customer type + `credit_enabled` + status `Active` (Suspended / disabled / platform ⇒ `0.00`).
- **Spend gate:** `WalletSpendPolicy` + `WalletSpendDecision` + `WalletSpendFailureReason` + `WalletSpendDeniedException`. `PayOrderWithWallet` calls the policy after wallet lock (`assertCanDebit` vs `availableToSpend`). Purchase path still posts via direct balance decrement + `WalletTransaction` — **not** migrated onto `WalletLedger` yet. `WalletLedger` still rejects debits that would go below zero (no overdraft floor in ledger for this milestone).
- **Admin UI:** `/credit-facility` (`can:manage_wallet_credit`) — ops list with filters (relevant/granted/active/suspended/overdrawn/not_granted), review-before-save confirm, `UpdateCreditFacility` action. Limit cannot be set below outstanding debt. Audit: activity + system event `wallet.credit_facility.updated` with `previous_*` / `new_*` props (limit, terms, enabled, status).
- **Customer UX:** `CustomerWalletDisplay` — stacked header balance (green positive / red debt), Limit/Available secondary when facility Active; mobile header chip surfaces limit/available without opening wallet. Wallet timeline humanized via `CustomerSystemEventPresenter` when timeline `audience="customer"`.
- **Config:** `billing.wallet_credit_limit_max`, `billing.wallet_payment_terms_days` (`config/billing.php`).
- **Out of scope (still true):** debt forgiveness / write-off; migrating purchase debits onto `WalletLedger`.

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

### Supplier price scanning (Wasim)

- **Purpose:** Compare catalog entry prices vs live Wasim product prices; surface drift for staff with `update_product_prices`.
- **UI:** `/price-drift` (`pages::backend.price-drift.index`) — start scan, review drift, apply scanned entry prices; related display helpers on `/product-entry-prices`.
- **Orchestration:** `StartSupplierPriceScan` → `DispatchSupplierPriceScan` → worker `POST /v1/price-scans` → `IngestSupplierPriceScanResult` (`POST /internal/automation/price-scans/{uuid}/result`). Stale sweep: `wasim:sweep-stale-price-scans`.
- **Reactive flags:** fulfillment ingest can flag products (`FlagProductSupplierPriceFromFulfillment`) when supplier price/margin issues appear; optional notifications to `update_product_prices` holders.
- **Config:** `config('fulfillment_automation.price_scan')` (enabled, schedule, drift tolerance, notify flags). Requires automation enabled + worker.

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

## 11. Realtime, notifications, Activity, and bugs

- **User private channel:** `private-App.Models.User.{id}` (notifications + `CustomerActivityInvalidated`).
- **Admin channels:** fulfillments, topups, activities (`ActivityLogChanged` via `ActivityLogBroadcaster`), system-events, bugs, **`admin.automation`** (automation run inbox). Activity-log realtime is optional and must not fail originating requests (including mobile login).
- **Customer notifications = delivery + authoritative unread truth** (`notifications` table / `unreadNotifications()`).
- **Customer Activity = projection only** (not financial/ops truth). Canonical route `/activity` (`activity.index`); `/notifications` is a compatibility alias to the same Livewire page.
- **Read model:** `GetCustomerActivity` orchestrates `NotificationActivityReader` + `TopupActionRequiredReader` + `RefundActionRequiredReader` + `OrderActionRequiredReader` → `CustomerActivityMerger` → DTOs → `CustomerActivityPresenter` (typed destinations; never trust stored notification URLs).
- **Activity filters:** `all` | `unread` | `action_required` (+ optional category). Action-required rows use domain unresolved state; unread never means unresolved.
- **Home:** authenticated home keeps Command → Personal → Browse → Catalog; Operational zone is a hidden placeholder (Needs attention island not mounted on Home). Action-required items surface on `/activity`.
- **Realtime invalidation:** domain/notification → after-commit broadcast → private user channel → JS coalescer (~600ms) → Livewire `customer-activity-invalidate`. Activity page 1 refreshes feed; page 2+ sets pending-refresh banner + `skipRender()` (zero feed reads until Refresh). Coordinator owns one unread COUNT and dispatches `customer-unread-count-updated`.
- **Bell:** notifications only; latest-five lazy until dropdown open; unread badge from coordinator. Mobile top bar shows wallet chrome + bell.
- **Perf notes:** request-local Activity fetch memo; `WebsiteSetting::instance()` request-attribute memo; fulfillments `order_id` / `order_item_id` indexes (`2026_07_28_183808_add_fulfillments_order_indexes_if_missing`).
- **Deploy:** `BROADCAST_CONNECTION=reverb`, Reverb app/Vite keys, restrict `allowed_origins`, `SESSION_SECURE_COOKIE` + HTTPS + `SESSION_DOMAIN`, run migration, `npm run build`, keep Reverb/queue workers healthy.
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
- Add/update tests in `tests/Feature` for regression-prone behavior (`FulfillmentAutomationTest`, `AutomationAdminTest`, `CheckoutFlowTest`, `AiAssistant/*`, `PackagesPageTest`, `SupplierPriceScanTest`, `Auth/RegistrationTest`).

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
- **2026-07:** **Supplier price scans** + `/price-drift` UI, reactive fulfillment price flags, stale-scan sweep; **admin exception counts** (`GetAdminExceptionCounts`) for dashboard/sidebar badges; **`CustomerDeliveredPayload`** for safe customer-facing delivered payload rendering (incl. image URLs); **registration security** — Cloudflare Turnstile + honeypot + registration rate limits (`app/Domain/Security/*`); **wallet credit facility / overdraft** — `credit_enabled` + `credit_limit` + `payment_terms_days` + `credit_status`, `WalletSpendPolicy`, `/credit-facility` (`manage_wallet_credit`), customer header/wallet display + humanized facility timeline events.
- **2026-07 (M5):** **Customer Activity** — read-model spine + Activity page + action-required domain readers + realtime invalidation (M5.4) + query-budget hardening (M5.4.1). Home Needs attention island was shipped then rolled back (wallet chrome restored on mobile top bar). See `Vault/Features/Customer Activity.md`.

---

## 15. Routes quick reference

- **Public:** `/`, `/categories/{category:slug}`, `/cart`, `/contact`, `/404`, `language/{locale}`.
- **Auth+verified (storefront):** `/profile`, `/wallet`, `/loyalty`, `/referral-link`, `/orders`, `/orders/{order_number}`, **`/activity`** (`activity.index`; **`/notifications`** alias), `/topup-proofs/{proof}`, `/bug-attachments/{attachment}`, `POST /api/pricing/buy-now-custom-amount-quote`.
- **Backend:** `/dashboard` (`can:view_dashboard`), `/salesperson-dashboard` (`can:view_referrals`), `/categories`, `/packages`, `/products`, `/product-entry-prices` (`can:update_product_prices`), **`/price-drift`** (`can:update_product_prices`), `/pricing-rules`, `/loyalty-tiers`, `/admin/orders/*`, `/admin/users/*`, `/admin/users/{user}/audit`, `/fulfillments`, `/refunds`, `/topups`, `/customer-funds`, **`/credit-facility`** (`can:manage_wallet_credit`), `/settlements`, `/admin/commissions` (`can:manage_settlements`), `/admin/notifications`, `/admin/bugs/*`, `/admin/website-settings` (admin only), **`/admin/automation`** (admin only), **`/admin/assistant`** (admin only, throttled).
- **Automation (internal):** `POST /internal/automation/runs/{uuid}/result`, `POST /internal/automation/runs/{uuid}/artifacts`, **`POST /internal/automation/price-scans/{uuid}/result`** (HMAC-signed, CSRF exempt). Worker: `POST /v1/runs`, **`POST /v1/sessions/clear`**, **`POST /v1/price-scans`** (HMAC).
- **AI/MCP:** `POST /mcp/ops-assistant` (admin MCP server for read-only ops tools).

---

## 16. Primary source files for AI prompts

- `routes/web.php`, `routes/automation.php`, `routes/channels.php`, `routes/console.php`, `routes/ai.php`
- **Mobile API:** `routes/api.php`, `config/mobile_api.php`, `app/Actions/MobileAuth/*`, `app/Http/Controllers/Api/V1/*`, `app/Http/Resources/Api/V1/*`, `docs/api/v1/openapi.yaml`, `app/Support/ActivityLogBroadcaster.php`
- `config/permission.php`, `config/fortify.php`, `config/referral.php`, **`config/fulfillment_automation.php`** (incl. `price_scan`), **`config/billing.php`**, **`config/security.php`**, `config/services.php` (`turnstile`, `openai`)
- `app/Actions/Orders/CheckoutFromPayload.php`, **`CheckoutResult.php`**, `CreateOrderFromCartPayload.php`, `PayOrderWithWallet.php`
- `app/Actions/Wallets/UpdateCreditFacility.php`, `AdjustWallet.php`
- `app/Services/WalletSpendPolicy.php`, `WalletLedger.php`
- `app/Models/Wallet.php` (credit helpers), `app/Enums/CreditFacilityStatus.php`, `WalletSpendFailureReason.php`
- `app/DTOs/WalletSpendDecision.php`, `app/Exceptions/WalletSpendDeniedException.php`
- `app/Support/CustomerWalletDisplay.php`, `CustomerSystemEventPresenter.php`
- `app/Actions/Commissions/CreatePayoutBatch.php`
- `app/Actions/Refunds/ApproveRefundRequest.php`
- `app/Actions/Fulfillments/ClaimFulfillment.php`, `CreateFulfillmentsForOrder.php`, **`DispatchFulfillmentAutomationRun.php`**, **`IngestFulfillmentAutomationResult.php`**, **`ScheduleWasimOrderReconcile.php`**, **`RetryFulfillmentAutomation.php`**
- `app/Actions/Packages/TogglePackageFulfillment.php`, `UpsertPackage.php`
- `app/Actions/SupplierPrices/*`, `app/Services/SupplierPriceScanService.php`
- `app/Actions/Dashboard/GetAdminExceptionCounts.php`
- `app/Actions/AiAssistant/FetchOrderData.php`, `FetchWalletData.php`, `FetchFulfillmentData.php`
- `app/Actions/Activity/GetCustomerActivity.php`, `app/Support/Activity/*`, `app/Support/CustomerActivityPresenter.php`, `app/Support/CustomerActivityBroadcaster.php`
- `app/Livewire/CustomerNotificationCoordinator.php`, `app/Livewire/NotificationBellDropdown.php`
- `resources/js/customer-activity-invalidation.js`, `resources/js/echo.js`
- `app/Domain/Security/*`, `app/Http/Controllers/Auth/RegisteredUserController.php`
- `app/Support/CustomerDeliveredPayload.php`
- `app/Services/FulfillmentAutomationService.php` (incl. **`clearWorkerBrowserSession()`**), `SystemEventService.php`, `OperationalIntelligenceService.php`
- `app/Services/SalespersonDashboardService.php`, `resources/views/components/dashboard/*`
- `app/Domain/Pricing/*`
- `app/Ai/Agents/OpsAssistant.php`, `app/Mcp/Servers/OpsAssistantServer.php`, `app/Livewire/Admin/AssistantChat.php`, `app/Livewire/Admin/AutomationMonitor.php`
- `app/Models/FulfillmentAutomationRun.php`, `SupplierPriceScan.php`, `WebsiteSetting.php` (`automation_enabled`, **`wasim_automation_*`**)
- `resources/views/livewire/admin/automation-monitor.blade.php`, `resources/views/livewire/admin/assistant-chat.blade.php`, `resources/views/pages/backend/fulfillments/⚡index.blade.php`, `resources/views/pages/backend/price-drift/⚡index.blade.php`
- `resources/views/layouts/app/sidebar.blade.php` (Automation + Ops Assistant + price-drift + credit-facility nav)
- `resources/views/pages/backend/credit-facility/⚡index.blade.php`
- `resources/views/flux/modal/index.blade.php`, `resources/css/app.css`
- `automation-worker/README.md`, `automation-worker/src/drivers/wasim/*`, `automation-worker/src/server.ts` (`/v1/sessions/clear`)
- `resources/js/app.js`
- **Agent rules:** `.cursor/rules/laravel-boost.mdc` (stack versions, financial guardrails, testing/Pint/Livewire conventions)
- **Companion map:** `Docs/PROJECT_STRUCTURE.md` (full layout); backlog scratchpad: `Docs/doc.md` (verify code — do not trust outdated “not installed” notes without checking `composer.json`)
- **Obsidian + ChatGPT pipeline:** `Vault/Karman Index.md`, `Vault/Workflow/Ask → Plan → Agent Pipeline.md`, `Docs/CHATGPT_PROJECT_PROMPT.md`, active feature notes under `Vault/Features/`
- **Mobile M1.1 context:** `Vault/Features/Mobile M1.1 — Laravel API Foundation and Authentication.md`, `Vault/Decisions/Mobile M1.1 Authentication Architecture.md`
- **Mobile M1.2/M1.3 context:** `Vault/Features/Mobile M1.2 — Flutter Foundation and Authentication.md`, `Vault/Decisions/Mobile M1.2 Flutter Authentication Architecture.md`, `Vault/Features/Mobile M1.3 — Local Integration and Closeout.md` (Flutter repo `OmarBobk/indirimGo-mobile` `main`; local emulator API `http://10.0.2.2:8000/api/v1`; no staging API URL yet; do not merge Laravel `staging`→`main` merely to close M1.3; next milestone Mobile Commerce Shell after approved catalog OpenAPI)
- **Vault sync rule (Cursor agents):** `.cursor/rules/050-vault-sync.mdc` — update feature notes after meaningful work; end with `Vault sync: …`
