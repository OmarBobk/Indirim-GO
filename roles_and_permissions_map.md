## Roles and Permissions Map

Roles and permissions are provided by spatie/laravel-permission. Roles and their permissions are defined in the seeder; this map lists where each role or permission is **checked** (middleware, policies, views, actions). The app checks **permissions** (via policies and `@can`), not role names.

### Roles

| Role | Defined in | Permissions | Checked in |
| --- | --- | --- | --- |
| `admin` | `database/seeders/RolesAndPermissionsSeeder.php` | All permissions | No direct role checks; access via permissions only. |
| `supervisor` | `database/seeders/RolesAndPermissionsSeeder.php` | `view_dashboard`, `view_referrals`, `view_orders`, `create_orders` | — |
| `salesperson` | `database/seeders/RolesAndPermissionsSeeder.php` | `view_referrals`, `view_orders`, `create_orders`, `edit_orders` | — |
| `customer` | `database/seeders/RolesAndPermissionsSeeder.php` | `customer_profile` | — |

### Permissions

| Permission | Granted to roles | Checked in |
| --- | --- | --- |
| `manage_users` | admin | `app/Policies/UserPolicy.php` (all gates), `resources/views/layouts/app/sidebar.blade.php` (Users nav link) |
| `manage_sections` | admin | `resources/views/pages/backend/categories/⚡index.blade.php` (mount), `resources/views/layouts/app/sidebar.blade.php` (Categories nav link) |
| `manage_products` | admin | `resources/views/pages/backend/packages/⚡index.blade.php`, `resources/views/pages/backend/products/⚡index.blade.php` (mount), `resources/views/layouts/app/sidebar.blade.php` (Packages, Products nav) |
| `manage_topups` | admin | `resources/views/pages/backend/topups/⚡index.blade.php` (mount), `resources/views/layouts/app/sidebar.blade.php` (Topups nav), `app/Http/Controllers/TopupProofController.php` (view proof) |
| `view_referrals` | admin, salesperson, supervisor | `routes/web.php` (`referral-link`, `salesperson.dashboard`), `resources/views/pages/frontend/⚡referral-link.blade.php` (mount), `resources/views/pages/backend/⚡salesperson-dashboard.blade.php` (mount), `resources/views/layouts/frontend/header.blade.php`, `resources/views/layouts/app/sidebar.blade.php`; `config/permission.php` `backend_permissions` |
| `view_orders` | admin, salesperson, supervisor | `app/Policies/OrderPolicy.php` (viewAny, view), `resources/views/layouts/app/sidebar.blade.php` (Orders nav) |
| `create_orders` | admin, salesperson, supervisor | `app/Policies/OrderPolicy.php` (create) |
| `edit_orders` | admin, salesperson | `app/Policies/OrderPolicy.php` (update) |
| `delete_orders` | admin | `app/Policies/OrderPolicy.php` (delete, restore, forceDelete) |
| `view_fulfillments` | admin | `app/Policies/FulfillmentPolicy.php` (viewAny, view), `resources/views/layouts/app/sidebar.blade.php` (Fulfillments nav) |
| `manage_fulfillments` | admin | `app/Policies/FulfillmentPolicy.php` (create, update, delete, etc.), `resources/views/pages/backend/fulfillments/⚡index.blade.php` (authorize update before Start/Complete/Fail/Retry) |
| `view_refunds` | admin | `resources/views/pages/backend/refunds/⚡index.blade.php` (mount), `resources/views/layouts/app/sidebar.blade.php` (Refunds nav) |
| `process_refunds` | admin | `resources/views/pages/backend/refunds/⚡index.blade.php` (approveRefund, rejectRefund + button visibility), `app/Actions/Orders/RefundOrderItem.php` (refund after fail) |
| `view_activities` | admin | `resources/views/pages/backend/activities/⚡index.blade.php` (mount), `resources/views/layouts/app/sidebar.blade.php` (Activities nav) |
| `customer_profile` | admin, customer | — |

### Backend access

- **Route guard:** Backend routes use `middleware(['auth', 'verified', 'backend'])` in `routes/web.php`. The `backend` middleware (`app/Http/Middleware/EnsureBackendAccess.php`) allows users who have **at least one** of the permissions listed in `config('permission.backend_permissions')`; others are redirected to 404.
- **Page-level checks:** Each Livewire page authorizes via policy (`$this->authorize('viewAny', Model::class)`) or `abort_unless(auth()->user()?->can('permission'), 403)`.
- **UI:** Sidebar items are wrapped in `@can('permission')` so only users with the right permission see each nav link.
