# Roles & permissions (karman.store)

Authorization uses **Spatie Laravel Permission**. The app checks **permission names**, not role names, at routes and in Livewire/actions. Roles are convenience bundles assigned in `database/seeders/RolesAndPermissionsSeeder.php`.

**Backend access:** Any route in the `backend` middleware group requires the user to hold **at least one** permission listed in `config/permission.php` → `backend_permissions`. Users without any of those permissions get **404** (not 403). See `App\Http\Middleware\EnsureBackendAccess`.

**Admin-only routes:** Some routes use the `admin` middleware (`EnsureAdmin`), which requires the **`admin` role** specifically (not just a permission).

**Track B note:** Commission clawback permissions and routes ship on branch `local/commission-policy`. **Staging/main may not include them until that branch is merged.** When absent, clawback menu items and routes will not exist.

---

## Default roles

| Role | Permissions (default seeder) | Backend access |
|------|------------------------------|----------------|
| **admin** | All permissions (`Permission::all()`) | Yes |
| **salesperson** | `view_referrals`, `manage_referred_users`, `view_orders`, `create_orders`, `edit_orders` | Yes (`view_referrals` ∈ backend_permissions) |
| **supervisor** | `view_dashboard`, `view_referrals`, `view_orders`, `create_orders` | Yes (`view_dashboard` ∈ backend_permissions) |
| **customer** | `customer_profile` only | No (storefront only) |

Direct permission grants to individual users override role defaults.

---

## Permission catalogue

### Core permissions

| Permission | Purpose |
|------------|---------|
| `view_dashboard` | Admin ops dashboard (`/dashboard`) |
| `manage_users` | User manager, export, audit |
| `manage_sections` | Categories (sections) manager |
| `manage_products` | Packages, products, pricing rules |
| `manage_loyalty_tiers` | Loyalty tier config |
| `manage_topups` | Top-ups inbox, customer funds |
| `adjust_wallets` | Manual wallet adjustments (`/wallet-adjustments`) |
| `manage_wallet_credit` | Customer credit facility (`/credit-facility`) |
| `view_referrals` | Referral link, salesperson dashboard, **`/wallet/earnings`** |
| `manage_referred_users` | Salesperson referred-users list |
| `create_orders` / `edit_orders` / `delete_orders` / `view_orders` | Order policies |
| `view_fulfillments` / `manage_fulfillments` | Fulfillment queue |
| `view_refunds` / `process_refunds` | Refunds ops |
| `view_activities` | Activity log, system events |
| `manage_settlements` | Settlements, `/admin/commissions`, `/admin/payout-requests` |
| `manage_bugs` | Bug inbox + report tool |
| `update_product_prices` | Product entry prices, `/price-drift` |
| `customer_profile` | Customer storefront profile (not backend) |
| `install_pwa_app` | PWA install prompt |
| `manage_user_prices` | Per-user pricing overrides |

### Track B — Commission clawbacks (`local/commission-policy`)

| Permission | Purpose |
|------------|---------|
| `view_commission_clawbacks` | Clawback inbox + `CLB-*` detail |
| `process_commission_clawbacks` | Retry / stale recovery |
| `waive_commission_clawbacks` | Waive clawback (full or partial) |
| `manage_commission_clawback_disputes` | Open/resolve disputes |
| `correct_commission_clawbacks` | Erroneous-reversal correction credits |
| `view_historical_commission_exposure` | Historical exposure report + review markers |

Default: only **admin** receives these (via all-permissions). Not granted via `adjust_wallets` / `manage_settlements` alone.

---

## `backend_permissions` (config gate list)

Users need **≥1** of these to enter any `backend` route.

**staging today:**  
`view_dashboard`, `manage_users`, `manage_sections`, `manage_products`, `manage_topups`, `adjust_wallets`, `manage_wallet_credit`, `view_referrals`, `create_orders`, `edit_orders`, `delete_orders`, `view_orders`, `view_fulfillments`, `manage_fulfillments`, `view_refunds`, `process_refunds`, `view_activities`, `manage_settlements`, `manage_bugs`, `update_product_prices`

**After Track B merge — add:**  
`view_commission_clawbacks`, `process_commission_clawbacks`, `waive_commission_clawbacks`, `manage_commission_clawback_disputes`, `correct_commission_clawbacks`, `view_historical_commission_exposure`

---

## Notable route gates

| Surface | Gate |
|---------|------|
| `/wallet`, transactions, topups, refunds | Authenticated customer (own wallet) |
| `/wallet/earnings`, `/referral-link`, `/salesperson-dashboard` | `can:view_referrals` |
| `/credit-facility` | `can:manage_wallet_credit` |
| `/wallet-adjustments` | `can:adjust_wallets` |
| `/admin/commissions`, `/admin/payout-requests` | `can:manage_settlements` |
| `/price-drift`, `/product-entry-prices` | `can:update_product_prices` |
| `/admin/commission-clawbacks`, `{CLB-*}` | `can:view_commission_clawbacks` (Track B) |
| `/admin/commission-clawbacks/historical-exposure` | `can:view_historical_commission_exposure` (Track B) |
| `/admin/automation`, `/admin/assistant`, `/admin/website-settings` | `admin` role middleware |
| `/admin/bugs/*` | `can:manage_bugs` |

---

## Role × capability matrix (default seeder)

| Capability | admin | salesperson | supervisor | customer |
|------------|:-----:|:-----------:|:----------:|:--------:|
| Staff dashboard | ✓ | — | ✓ | — |
| Earnings / referrals | ✓ | ✓ | ✓ | — |
| Fulfillments / refunds / topups / settlements | ✓ | — | — | — |
| Wallet adjustments / credit facility | ✓ | — | — | — |
| Commission clawbacks (Track B) | ✓ | — | — | — |
| Website settings / automation / Ops Assistant | ✓ (role) | — | — | — |
| Storefront Financial Centre | ✓ | ✓ | ✓ | ✓ |

---

## Implementation references

| Item | Location |
|------|----------|
| Permission config | `config/permission.php` |
| Role seeder | `database/seeders/RolesAndPermissionsSeeder.php` |
| Backend middleware | `app/Http/Middleware/EnsureBackendAccess.php` |
| Routes | `routes/web.php` |
| Sidebar gates | `resources/views/layouts/app/sidebar.blade.php` |
