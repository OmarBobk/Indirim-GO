# AGENTS.md

## Cursor Cloud specific instructions

This is a **Laravel 12 + Livewire 4** e-commerce/digital-wallet app ("İndirimGo"). It uses PHP 8.4, Composer, and Node/Vite. There is also an optional `automation-worker/` Node + Playwright microservice (only needed when `FULFILLMENT_AUTOMATION_ENABLED=true`, which defaults to `false`).

### Local defaults (already configured in `.env`)
- DB is **SQLite** (`database/database.sqlite`); sessions, cache, and queue all use the `database` driver.
- Broadcasting is `log` and mail is `log`, so Reverb / a mail server are **not** required to boot. Reverb (`php artisan reverb:start`) is only needed to exercise realtime UI.
- `.env` is gitignored. If it is ever missing, `composer install` will fail during `package:discover` (broadcast config resolves to `reverb` with null keys). The update script guards this by copying `.env.example` → `.env` when absent; if you recreate `.env` manually, also run `php artisan key:generate` and `php artisan migrate --seed`.

### Running the app (dev)
- All-in-one: `composer run dev` (runs `php artisan serve` + `queue:listen` + `npm run dev` concurrently). Or run them separately.
- `npm run dev` (Vite) must be running, or run `npm run build`, otherwise pages throw a Vite manifest error.
- Auth note: **login is by `username`, not email** (Laravel Fortify). Registration works locally out of the box — Cloudflare Turnstile is disabled (`TURNSTILE_ENABLED=false`).
- Backend/admin routes require Spatie roles/permissions; run `php artisan db:seed` (seeds `RolesAndPermissionsSeeder`) so those gates exist.

### Lint / test / build
- Lint: `vendor/bin/pint` (fix) / `vendor/bin/pint --test` (check).
- Tests: `php artisan test --compact`. See `composer.json`, `phpunit.xml`, and the Pest rules for filters. Tests use in-memory SQLite + `sync` queue, so no external services are needed.
- Build: `npm run build`.

### Known gotcha (pre-existing, not caused by setup)
- Running the **full** test suite fatals with `Cannot redeclare function adminUser()` because both `tests/Feature/AutomationAdminTest.php` and `tests/Feature/CategoriesProductsValidationTest.php` declare a global `adminUser()` helper. Until that collision is resolved in the repo, run those files/directories individually (e.g. `php artisan test tests/Unit`, or a single file) instead of the whole suite at once.
