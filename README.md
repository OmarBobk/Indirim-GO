# indirimGo

Laravel 12 e-commerce and wallet platform powering the İndirimGo storefront and operations backend.  
The system covers catalog browsing, wallet-based checkout, fulfillment workflows, topups/refunds, loyalty, notifications, and permission-based admin tooling.

## Highlights

- Customer storefront with categories, packages, products, cart, orders, wallet, loyalty, and notifications.
- Wallet-first checkout pipeline with server-side validation and fulfillment creation.
- Operations backend for fulfillments, topups, refunds, customer funds, settlements, users, activities, and system events.
- Browser fulfillment automation (Wasim) plus supplier price-drift monitoring (`/price-drift`).
- Ops Assistant admin chat + MCP (`/admin/assistant`, `/mcp/ops-assistant`) for read-only ops lookups.
- Permission-driven access control with Spatie roles/permissions and hidden backend routes for unauthorized users.
- Realtime + push stack (Reverb/Echo and Firebase FCM) for operational and user notifications.
- Public registration protected by Cloudflare Turnstile, honeypot, and rate limits.

## Tech Stack

- PHP 8.2+ (`8.4.x` recommended in project context)
- Laravel 12
- Livewire 4 + Flux (free components only)
- Tailwind CSS 4.1 + Alpine.js + Vite 7
- Fortify (auth/2FA), Reverb (broadcast), Spatie Permission/Activitylog/Backup
- `laravel/ai` + `laravel/mcp` (Ops Assistant)
- Pest 3 + PHPUnit 11

## Quick Start (Local)

### 1) Install dependencies and bootstrap

```bash
composer run setup
```

This runs:

- `composer install`
- copy `.env.example` to `.env` (if missing)
- `php artisan key:generate`
- `php artisan migrate --force`
- `npm install`
- `npm run build`

### 2) Run development services

```bash
composer run dev
```

This starts Laravel server, queue listener, and Vite in one command.

### 3) Optional seed data

```bash
php artisan db:seed
```

## Sail / Docker Option

Laravel Sail is included.

```bash
bash sail up -d
bash sail artisan migrate
bash sail npm run dev
```

Dockerfiles are available under `docker/` (including PHP 8.4/8.5 variants).

## Core Commands

```bash
# Dev
composer run dev
php artisan serve
npm run dev

# Build assets
npm run build

# Database
php artisan migrate
php artisan db:seed

# Queue
php artisan queue:work --queue=push,default

# Scheduler (local worker mode)
php artisan schedule:work

# Lint / format
composer lint
vendor/bin/pint --dirty

# Tests
composer test
php artisan test --compact
```

## Configuration Notes

Start from `.env.example`:

- Defaults are SQLite + database-backed queue/session/cache.
- `BROADCAST_CONNECTION=log` by default; switch to Reverb/Pusher when enabling realtime broadcasts.
- Firebase/FCM variables are present for admin push notifications (`FIREBASE_*`, `VITE_FIREBASE_*`, `VITE_FIREBASE_VAPID_KEY`).
- Registration bot protection: `TURNSTILE_*` (local: `TURNSTILE_ENABLED=false`) and optional `REGISTRATION_*` rate-limit overrides.
- Ops Assistant: `OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_BASE_URL`.
- Fulfillment automation + price scans: see `FULFILLMENT_AUTOMATION_*` and `config/fulfillment_automation.php`.

For production-like behavior, review:

- `config/broadcasting.php`
- `config/reverb.php`
- `config/queue.php`
- `config/firebase.php`
- `config/permission.php`
- `config/security.php`
- `config/services.php` (Turnstile / OpenAI)

## Architecture Overview

See **[`SYSTEM_CONTEXT_CORE_v1.md`](SYSTEM_CONTEXT_CORE_v1.md)** for condensed AI delivery context, and **[`Docs/PROJECT_STRUCTURE.md`](Docs/PROJECT_STRUCTURE.md)** for the full Laravel project map (skeleton, route table, Actions/Livewire conventions, models, and financial guardrails).

```text
app/Actions/          Domain application actions (Orders, Fulfillments, Topups, Refunds, Pricing...)
app/Domain/           Core domain logic (e.g., pricing engine)
app/Services/         Cross-cutting services/integrations (push, events, notifications...)
app/Livewire/         Livewire component classes
resources/views/      Blade layouts/pages/components (frontend + backend UIs)
resources/js/         Alpine stores, Echo/realtime wiring, frontend behavior
routes/               Web/settings/channels/console/ai route entrypoints
database/             Migrations, factories, seeders
tests/                Pest feature/unit tests
Docs/                 Deeper project docs
Vault/                Obsidian knowledge base (feature briefs, AI pipeline)
```

## Testing & QA

- Fast path: `php artisan test --compact`
- Full project test entry: `composer test`
- Manual role-based test scenarios: [`Docs/ManualTestingPlaybook.md`](Docs/ManualTestingPlaybook.md)

## Security, Permissions, and Data Safety

- Backend access is permission-gated (`backend` middleware) and hidden from unauthorized users.
- Notifications and broadcasts are dispatched after successful DB commits to reduce false/duplicate state.
- Financial/event integrity is documented through system-event and notification mapping docs.

Reference docs:

- [`SYSTEM_CONTEXT_CORE_v1.md`](SYSTEM_CONTEXT_CORE_v1.md) — AI/feature delivery context (invariants, routes, automation)
- [`Docs/PROJECT_STRUCTURE.md`](Docs/PROJECT_STRUCTURE.md) — Laravel 12 layout, routes, conventions, domain map
- [`Docs/CHATGPT_PROJECT_PROMPT.md`](Docs/CHATGPT_PROJECT_PROMPT.md) — ChatGPT Project instructions for Ask → Plan → Agent
- [`Vault/Karman Index.md`](Vault/Karman%20Index.md) — Obsidian vault entry (feature briefs, domain notes)
- [`Docs/roles.md`](Docs/roles.md)
- [`Docs/DB.md`](Docs/DB.md)
- [`Docs/system_events_map.md`](Docs/system_events_map.md)
- [`NOTIFICATIONS.md`](NOTIFICATIONS.md)

## Contributing

- Follow existing conventions in `CLAUDE.md` and `.cursor/rules/*`.
- Keep changes small and diff-friendly.
- Run formatter and related tests before opening a PR:

```bash
vendor/bin/pint --dirty
php artisan test --compact
```

## License

`composer.json` currently declares `MIT`.  
If this repository is intended for public distribution, add an explicit `LICENSE` file to make licensing terms unambiguous.
