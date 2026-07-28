## Cursor Cloud specific instructions

### Services overview

This is a Laravel 12 + Livewire 4 e-commerce/wallet platform (İndirimGo). The default dev stack is:

- **PHP 8.4** with SQLite (file-based at `database/database.sqlite`)
- **Queue/Cache/Session**: all database-backed (no Redis required)
- **Frontend**: Vite 7 + Tailwind CSS 4 + Alpine.js + Flux (free)
- **Broadcast**: defaults to `log` (no Reverb needed for basic dev)

### Running the application

```bash
composer run dev
```

This starts three processes concurrently via `npx concurrently`:
1. `php artisan serve` (HTTP server on port 8000)
2. `php artisan queue:listen --tries=1` (queue worker)
3. `npm run dev` (Vite HMR)

Alternatively, run them individually in separate terminals.

### Key commands

| Task | Command |
|------|---------|
| Lint/format | `vendor/bin/pint --dirty` |
| Tests | `php artisan test --compact` |
| Full test + lint | `composer test` |
| Build assets | `npm run build` |
| Migrate | `php artisan migrate --force` |
| Seed roles/permissions | `php artisan db:seed` |

### Non-obvious caveats

- **Registration is disabled** in `config/fortify.php` (`Features::registration()` is commented out). To create test users, use `php artisan tinker` or factories.
- **Login uses username**, not email. The `username` column on users must be set.
- The `CategorySeeder` uses randomized `order` values with a unique index, so running it more than once will fail with constraint violations. Use `php artisan migrate:fresh --seed` if you need a clean slate.
- Some tests require the `admin` role to exist. Run `php artisan db:seed` (which seeds `RolesAndPermissionsSeeder`) before running the full test suite.
- The lock file pins packages requiring PHP 8.4+. PHP 8.3 will fail `composer install`.
- `composer run dev` requires the `concurrently` npm package (installed via `npm install`).
- Frontend assets must be built (`npm run build`) at least once for `php artisan serve` to serve pages without Vite manifest errors. In dev, `npm run dev` handles this via HMR.
