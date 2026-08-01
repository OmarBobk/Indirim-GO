# Roles & permissions (seeded defaults)

Source: `database/seeders/RolesAndPermissionsSeeder.php` + `config/permission.php`.  
App authorization checks **permissions**, not role names. Backend entry requires at least one of `backend_permissions`.

## Roles

| Role | Default permissions (seeder) |
|------|------------------------------|
| **admin** | All permissions |
| **salesperson** | `view_referrals`, `manage_referred_users`, `view_orders`, `create_orders`, `edit_orders` |
| **supervisor** | `view_dashboard`, `view_referrals`, `view_orders`, `create_orders` |
| **customer** | `customer_profile` |

Ops surfaces (fulfillments, refunds, topups, credit facility, settlements, bugs, price drift, etc.) are granted by assigning the matching permissions — **not** implied by the supervisor role name alone.

## Permission catalog

### Backend-entry (`config/permission.backend_permissions`)

`view_dashboard`, `manage_users`, `manage_sections`, `manage_products`, `manage_topups`, `adjust_wallets`, `manage_wallet_credit`, `view_referrals`, `create_orders`, `edit_orders`, `delete_orders`, `view_orders`, `view_fulfillments`, `manage_fulfillments`, `view_refunds`, `process_refunds`, `view_activities`, `manage_settlements`, `manage_bugs`, `update_product_prices`.

### Other permissions

| Permission | Typical use |
|------------|-------------|
| `manage_user_prices` | Per-user pricing overrides (not a backend-entry key by itself) |
| `manage_loyalty_tiers` | Loyalty tier admin |
| `manage_referred_users` | Salesperson referred-user management (`/salesperson/users`) |
| `customer_profile` | Customer profile surfaces |
| `install_pwa_app` | PWA install button visibility |

## Notable route gates

| Surface | Gate |
|---------|------|
| `/credit-facility` | `can:manage_wallet_credit` |
| `/wallet-adjustments` | `can:adjust_wallets` |
| `/admin/commissions`, `/admin/payout-requests` | `can:manage_settlements` |
| `/price-drift`, `/product-entry-prices` | `can:update_product_prices` |
| `/wallet/earnings`, `/referral-link`, `/salesperson-dashboard` | `can:view_referrals` |
| `/admin/automation`, `/admin/assistant`, `/admin/website-settings` | `admin` middleware |
| `/admin/bugs/*` | `can:manage_bugs` |
