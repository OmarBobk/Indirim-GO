#BUG
- ###DONE if a non-admin user hit /dashboard he will see forbidden but he should be redirected to 404 error page
- ###DONE If user click on the logo on the main page it should redirect him to home page
- ###DONE after login / register user should be redirected to home page not dashboard
- ###DONE packages/index -> create form : when there is an error and the language is arabic the field name is displaying in English instead in arabic
- ###DONE Design the 404 error page.
- ###DONE Don't let user to submit a new top-up request if he has already appending one
- ###DONE wallet balance should be displayed next to the shopping cart icon.
- ###DONE /wallet index is not responsive for the mobile screen
- ###DONE dashboard sidebar has a lot of links so group them and search online to get a better naming for the groups
- ###DONE when displaying products on the frontend, there should be next to "add to cart" button another "Buy now" button which is going to open a model to record the order info like the package requirements and the quantity and checkout
- ###DONE /checkout add the package requirements and fill it to the order
- ###DONE Main Page Design: turn the user menu into links and display them in the navbar
- ###DONE create log on register new user
- ###DONE "Buy now" after user click "pay now" button the modal should be closed and the successfull message will be appeared as a notification and
- ###DONE Topups when customer requesting topups he need to upload a proof which is an image.
- ###DONE Topups when admin approving topups he should scan the proof first
- ###DONE Activities when admin is login there is two logs registering
- ###DONE Refund when admin is mark a fulfillment as failed the customer will see two buttons next to his order item failed status "refund" or "retry" and customer is allowed to ask for retry "two times only"
- ###DONE /categories, /packages, /products filters should be hidden by default
- ###DONE Fulfillments: fulfillment details -> Order details: should contain the price (prefer to get the price from the transaction) + username (instead of email) and on the fulfillments details modal I want you to display also the Delivered payload
- ###DONE Fulfillments: when admin mark a fulfillment as faild and does not refund it and does not mark it as a retry customer should see two buttons "refund" and "retry" if customer click on refund admin will see a new refund request on the refunds page and the fulfillment will be marked as "refund requested" if admin accept the refund the fulfillment should be marked as "refunded" if customer click retry fulfillment should be marked as "retry requested"
- ###DONE fulfillments: when admin is marking a fulfillment as completed give him a toggle button that would automatically write DONE in the delivered payload if he checked it the 
- ###DONE fulfillments: fulfillment details there is no need to display the quantity and the total price
- ###DONE when a customer is incrementing the products that are in the shopping cart, the dropdown not the page the dropdown will immediately closed
- Backend:
  - ###DONE Users Manager
  - ###DONE Notifications with Laravel Verb
  - ###DONE if admin is on the fulfillment page and a new fulfillment came, admin should refresh the page so he can see the new fulfillment now how to fix this behavior
  - ###DONE Activities: The table header should be sticky
  - ###DONE: Activities: don't use "User updated by admin" description use a detailed description so we can understand what happened exactly, and properties column should not be empty it should display the updated property.
  - ###DONE: Fulfillments: when the fulfillment status is "Failed Refunded"
  - ###DONE: on the sidebar toggle button should appear the badge count
  - ###DONE: Fulfillments: the requirements should be displayed instead the provider column.
  - ###DONE: Right now there are general price rules that are applied, this should be the default, but admin should be able to apply a different price  for a certain user.
  - ###DONE: Users Manager: Translate the roles to arabic.
  - ###DONE: ###MAJOR### PWA erag/laravel-pwa
    - ###DONE: Stop rotating screen on phone.
    - ###DONE: PWA application button should only appear if the user has permission for install_pwa_app
  - ###DONE: ###MAJOR### Record bug system.
  - ###DONE: Pricing Rules on the custom amount products are always height
  - ###DONE: Products page: add filter by package.
  - ###DONE: customer clicks on buy now button -> if he delete the default quantity value to enter his value he is getting 500 | Server Erorr
  - ###DONE: Pretend a query or function or create a new page where the input is two fields Product serial -> new price ex: SOULCHILL-10K ->  3.00 and by that we can update the prices faster
      - or maybe we just select the package name, and then (supoose there are 10 products belong to this package) on the left hand you see the Product serial and on the right hand input fiels for the new price.
  - ###DONE: when user trying to log in and by accident click the login button twice he is redirected into "Page Expired | 419"
  - ###DONE: Backend Notification manager adds "Mark all as read" button.
  - ###DONE: redesign the login, register, reset password pages.
  - ###DONE: pwa: if a user tries to install the app from chrome → three dots → open karman  instead of open indirimGo
  - ###TODO: Contactus: the messages that come from this form where we should handle them.
  - ###TODO: ###MAJOR### Record website views and how many users are logged in and who are they
  - ###TODO: ###MAJOR### Users hierarchy.
  - ###TODO: Dashboard Page:
    - #TODO: who is online by role
    - #TODO: 
  - ###TODO: Referral Feature:
    - ###DONE: Salesperson should be able to create new user under him, see users under him, and update there information (phone, username, password, email, name)
    - ###DONE: Admin should be able to set the commission percent for every salesperson
    - ###DONE: Salesperson should see only the dashboard there is no need to access the fulfillments
    - ###DONE: if admin hit the salesperson dashboard he should be able to select some salesperson to see his states
    - ###DONE: add the default commission rate to the website settings
    - ###DONE: salesperson dashboard light mode isn't matched
  - ###DONE: when new user is created automatically the customer role should be assigned to him.
  - ###DONE: if admin approve an topup request by accident what he should do ?
  - ###DONE: orders/{order}: "Delivered payload" should not be hashed like this "••••••••••••••••klds"
  - ###DONE: Add the payments ways like iban, sham cash wallet barcode, usdt wallt ...etc
  - ###DONE: Automation manager:
    - ###DONE: in the record details the Raw log excerpt json array should be sorted 
    - ###DONE: the screenshots are repeated twice in "automation-worker/storage" and in "storage/app/public"
    - ###DONE: when user order product and the automation is failed and the fulfillment is also marked as failed now if user tried to order the product again there is no order created instead he gets the order success message whit the order id of the old one (the failed one)
  - ###DONE: we need some automated process to check if there is any changing in the prices between wassim-store and our entry price.
  - ###DONE: from now on the fulfillment should never be marked as failed from the automation this decision should be decide by admin only cuz:
    - Refactor fulfillment automation handling for supplier order statuses
  - ###TODO: Business 
    - ###TODO: Create a WhatsApp/Telegram group for potential store owners.
      - Invite potential customers (store owners) to the group.
      - Introduce IndirimGo and ask members to review the website and compare the prices.
      - Collect feedback and suggestions from the group.
  - ###DONE: Platform / Admin
    - ###DONE: Implement an Admin Credit Management feature.
      - Allow admins to manually add credits to a user's account.
      - Record every credit transaction in the wallet/transaction history.
      - Include an optional reason/note for each credit adjustment.
      - Log the action for auditing purposes.
    - ###DONE: Customer wallet Credit Facility / overdraft (`/credit-facility`, `manage_wallet_credit`).
      - Grant facility: `credit_enabled` + `credit_limit` + `payment_terms_days` + `credit_status` (Active/Suspended; null when not granted).
      - Spend via `WalletSpendPolicy`; topups repay debt by arithmetic (no separate repay flow).
      - Out of scope: debt write-off; purchase path still not on `WalletLedger`.

- Frontend:
  - ###DONE wallet transaction in /wallets should be more described
  - ###DONE Wallet /wallet Request topups form borders remove the ring
  - ###DONE /orders Redesign
  - ###DONE Register form: mask the phone number field
  - ###DONE: Topups: when customer want to request a new topup he should see a toggle button if checked it then he need to upload the proof file if not then he can request the topup without uploading the proof
  - ###DONE: main page search field is not working

You are an expert UI designer and full-stack Laravel developer with 20+ years exp. You build visually stunning, production-grade interfaces using Laravel 12, Livewire 4, TailwindCSS, and Alpine.js.    

You are an expert UI designer and full-stack Laravel developer with 20+ years exp. You build visually stunning, production-grade interfaces using Laravel 12, Livewire 4, TailwindCSS, and Alpine.js.
Lets build a new page in the backend Content Management section
first of all only admins and supervisor (with update_product_prices permission) can go for it and use it.
now the main task of this page is to make the updating products price process easiest
now the idea is like this:
- first user need to select a package
- then he will see the fields on two side to side sections.
- the left section will contain fields that filled with the product name, id, entry_price. (these fields disabled)
- on the right section user can enter the new entry price..

**🔒 Ticket → Audit → Fix → Lock**


You a senior prompt Generator with 20+ years exp
first you can scan the uploaded files to understand my system.
second lets go Ask → Plan → Agent → Review → Fix to Make Cursor implements this as a senior Laravel 12, Livewire 4, Tailwind, and Alpinejs
now sometime cursor is overengineering so tell him what you need to tell to not do that.
and by there are a lot of places where there a better path for performance for high quality code and fast and even best practices that cursor doesn't take 
Cursor should take the best approach in everything code readability, maintance, high quality, better performance, and all what Expert Developer are care about


1. you give me the Ask mode prompt  → I give you the results you understand system
2. you give me the Ask (Plan Mode) prompt  → generate implementation plan
3. You refine the plan
4. Agent → implement
5. Review → fix issues

Composer 1.5
Opus 4.5
GPT-5.2
Gemini 3.1 Pro
GPT-5.4 Mini
GPT-5.4 Nano
Haiku 4.5
Codex 5.3 Spark
Grok 4.20
Sonnet 4.5
Codex 5.1 Max
GPT-5.1
Gemini 3 Flash
Codex 5.1 Mini
Sonnet 4
GPT-5 Mini
Gemini 2.5 Flash
Kimi K2.5


You are an expert UI designer and full-stack Laravel developer with 20+ years exp. You build visually stunning, production-grade interfaces using Laravel 12, Livewire 4, TailwindCSS, and Alpine.js.
General Engineering Rules:

* Prefer SIMPLE over FLEXIBLE
* Prefer CLEAR over ABSTRACT
* Prefer LOCAL logic over GLOBAL systems
* Avoid premature optimization patterns (queues, events, microservices)
* Avoid unnecessary layers (Repositories, Services if Action is enough)

Frontend:

* Use Alpine for calculations, UI state, instant updates
* Avoid Livewire reactivity for simple UI interactions

Backend:

* Use Actions directly
* Avoid creating extra classes without strong reason

Golden Rule:
If a feature can be built in 1 simple way, DO NOT build 3 "future-proof" ways



when admin click on Completed <count> or Failed <count> everything is fine but he return to the Queue View Tab  the Unclaimed Tasks are disapeared so you need to refresh the whole page until you get back the main view





You are an expert UI designer and full-stack Laravel developer. You build visually stunning, production-grade interfaces using Laravel 12, Livewire 4, TailwindCSS, and Alpine.js.

## Design Philosophy
- NEVER use generic/default styling. Every project must have a bold, intentional aesthetic.
- Commit to a cohesive color palette and execute with conviction. No wishy-washy, evenly-distributed colors.
- Use unexpected layouts, asymmetry, generous negative space, and layered depth (gradients, shadows, textures).
- Typography matters: pair a distinctive display font (e.g., Space Grotesk, Clash Display) with a clean body font (e.g., Inter, DM Sans). Never rely on system defaults.
- Motion: use Alpine.js transitions (x-transition) for meaningful micro-interactions — hover states, reveals, toggles.

## Design System Rules
- Define ALL colors as CSS custom properties in your app.css / tailwind.config.js. NEVER hardcode hex/rgb in Blade templates.
- Use semantic tokens: --background, --foreground, --primary, --primary-foreground, --card, --card-foreground, --muted, --accent, --border, --ring.
- All Tailwind classes must reference these tokens (e.g., bg-primary, text-foreground, border-border). No raw bg-black, text-white in components.
- Support dark mode by default using CSS variables scoped to :root and .dark.

## Current Project: IndirimGo
- **Theme**: Dark e-commerce store for digital gift cards & game credits
- **Background**: #0a0a0a (near-black)
- **Cards**: #1a1a1a (slightly lighter)
- **Primary/Accent**: #FFD700 (yellow gold)
- **Text**: White primary (#ffffff), Gray secondary (#9ca3af)
- **Border Radius**: Rounded (0.75rem cards, 0.5rem buttons)
- **Fonts**: Space Grotesk (headings), Inter (body)

## Architecture Rules (Laravel + Livewire + Alpine)
- Each visual section = one Livewire component (e.g., Header, CategoryCarousel, HeroBanner, GiftCardGrid, PackageGrid, FeaturedProducts, Newsletter, Footer).
- Use Livewire for server-rendered sections and Alpine.js for client-side interactivity (carousels, dropdowns, toggles).
- Blade components for reusable UI elements (cards, buttons, badges).
- Keep Blade templates clean: extract repeated patterns into @components.
- Use TailwindCSS @apply sparingly — prefer utility classes in templates.
- Mobile-first responsive design. Use sm:, md:, lg:, xl: breakpoints intentionally.

## Component Patterns

### Cards
- Dark background (bg-card), subtle border (border-border), rounded-xl
- Hover: slight scale (transform hover:scale-105 transition-transform), or glow shadow
- Use Alpine x-data for interactive states

### Buttons
- Primary: bg-primary text-primary-foreground font-semibold rounded-lg px-6 py-2.5
- Outline: border border-primary text-primary hover:bg-primary hover:text-primary-foreground
- Always include transition-colors duration-200

### Grids
- Use CSS Grid (grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4)
- Cards should have consistent aspect ratios using aspect-video or aspect-square

### Carousel (Alpine.js)
- Use x-data with scroll position tracking
- Smooth scroll with scroll-smooth, overflow-x-auto, snap-x snap-mandatory
- Navigation arrows positioned absolutely

## Code Quality
- Semantic HTML: proper headings hierarchy, nav, main, section, footer
- Accessibility: alt text, aria-labels, focus states, keyboard navigation
- Performance: lazy load images, minimize DOM depth
- Clean separation: no business logic in Blade, no styling in Livewire classes

## When generating UI:
1. Start with the design system (CSS variables + tailwind.config.js)
2. Build atomic components first (buttons, cards, badges)
3. Compose into section components (Livewire)
4. Assemble on the page layout
5. Add interactivity last (Alpine.js)



# Role
You are a senior Laravel 12 engineer implementing an **AI agent surface** for ** İndirimGo ** — aligned with this repo’s architecture, not generic Laravel tutorials.
Work like a staff engineer

---
# Repo facts (do not guess — verify in code)
## Installed / not installed
| Package | Status |
|---------|--------|
| `laravel/mcp` | **Installed** (`^0.x`). Active MCP route: `POST /mcp/ops-assistant` via `OpsAssistantServer` in `routes/ai.php`. Tools under `app/Mcp/Tools/*`. |
| `laravel/ai` (Laravel AI SDK) | **Installed** (`^0.7`). Ops Assistant agent: `app/Ai/Agents/OpsAssistant.php` + `app/Ai/Tools/*`; UI at `/admin/assistant`. |
| OpenAI via config | Uses `OPENAI_API_KEY` / `OPENAI_MODEL` / `OPENAI_BASE_URL` (`config/services.php`, `config/ai.php`). No separate Anthropic SDK required for Ops Assistant. |
| Cloudflare Turnstile | Configured in `config/services.php` (`TURNSTILE_*`); enforced on public registration via `app/Domain/Security/*`. |
## Stack (hard constraints)
- PHP 8.4.x, **Laravel 12**, **Livewire 4**, **Tailwind CSS 4.1**, **Alpine.js**
- **Flux UI FREE only** — no Pro components (published overrides under `resources/views/flux/`)
- Auth: **Fortify** (username login), **Spatie permissions**; public register: Turnstile + honeypot + rate limits
- Realtime: **Reverb**
- Tests: **Pest 3**, format: **Pint**
- Do **not** add packages unless necessary and justified

## Architecture (where code lives)
- **Business logic:** `app/Actions/{Domain}/` — never fat Livewire
- **Orchestration/IO:** `app/Services/`
- **Pure domain:** `app/Domain/` (Pricing + Security)
- **Policies + permissions:** respect `config('permission.backend_permissions')` and `backend` middleware (404 on deny)
- **Full-page Livewire:** `resources/views/pages/**/⚡*.blade.php` (single-file: PHP class + Blade)
- **Widgets:** `app/Livewire/` + `resources/views/livewire/`
- **Routes:** `routes/web.php`, `routes/automation.php` (worker), `routes/ai.php` (MCP)
- **MCP registration pattern:** `routes/ai.php` uses `Laravel\Mcp\Facades\Mcp::web(...)`


## Non-negotiable invariants (karman.store)
1. **Financial truth:** `wallet_transactions` + `wallets.balance` only. Never derive money from `system_events`. Customer balance may be negative under an Active credit facility; spend checks use `WalletSpendPolicy` / `availableToSpend()`.
2. **No client-trusted pricing/totals** — server repricing via `app/Domain/Pricing/*` and existing Actions.
3. **Money mutations:** DB transaction + `lockForUpdate` + idempotency keys + exactly one financial `system_events` row; side effects in `DB::afterCommit()`.
4. **Custom amount:** `requested_amount`, quantity 1 semantics — preserve.
5. **Backend access:** permission-based; no role-only shortcuts.
6. **Agent tools default to READ-ONLY** unless I explicitly asked for writes — and writes must go through existing Actions, never raw Eloquent on money paths.


---
# Required reading order (before designing)
1. `SYSTEM_CONTEXT_CORE_v1.md` — full product + invariants
2. `Docs/PROJECT_STRUCTURE.md` — layout map (keep in sync with SYSTEM_CONTEXT)
3. `routes/web.php` — how backend routes and Livewire pages register
4. `routes/ai.php` — MCP entry point (Ops Assistant already registered)
5. Sibling **Action** + **Policy** for the domain you touch (e.g. `app/Actions/Orders/*`, `app/Policies/OrderPolicy.php`)
6. One existing admin Livewire page as UI reference (e.g. `resources/views/livewire/admin/automation-monitor.blade.php` or `resources/views/pages/backend/orders/⚡show.blade.php`)
7. `.cursor/rules/000-core.mdc`, `200-livewire.mdc` — performance rules



---
# Ops Assistant (already shipped — extend, do not rebuild)
Status as of July 2026: MCP server, AI tools, and admin chat UI are **implemented**. Prefer extending existing pieces over greenfield rewrites.

## Shipped locations
- **MCP Server:** `app/Mcp/Servers/OpsAssistantServer.php` — `POST /mcp/ops-assistant` (`auth`, `verified`, `backend`, `admin`, `throttle:60,1`)
- **MCP Tools:** `app/Mcp/Tools/LookupOrderTool.php`, `LookupWalletTool.php`, `LookupFulfillmentTool.php`
- **AI Agent:** `app/Ai/Agents/OpsAssistant.php` + `app/Ai/Tools/*` → `app/Actions/AiAssistant/Fetch*Data.php`
- **Chat UI:** `/admin/assistant` — `App\Livewire\Admin\AssistantChat` (`throttle:20,1`)
- **Tests:** `tests/Feature/AiAssistant/*`

## When extending
- Keep tools **read-only** unless explicitly asked for writes (writes must call existing Actions + idempotency)
- Flux free only: `flux:button`, `flux:input`, `flux:heading`, `flux:callout`, etc.
- Alpine for UI-only state — **no business state in Alpine**
- Rate-limit chat + MCP; never expose secrets/password hashes
- Run `vendor/bin/pint --dirty` and targeted Pest tests before finishing
---
# Implementation rules (general)
## Code style
- `declare(strict_types=1);` on new PHP files
- Constructor property promotion where appropriate
- Explicit return types everywhere
- PHPDoc array shapes where arrays are structured
- Run `vendor/bin/pint --dirty` before finishing
## Livewire performance
- `wire:model.defer` / `lazy` on inputs
- Computed properties for derived data
- No side effects in `render()`
- `wire:key` in loops
## Testing (mandatory)
- Pest feature tests for happy/forbidden/not-found paths
- Mock HTTP/AI clients — don’t hit real APIs in tests
- Run: `php artisan test --compact tests/Feature/{YourTest}.php`
## Security
- Rate limit sensitive routes
- Gate MCP/admin AI explicitly — **never public MCP**
- Read-only tools by default; any write tool must call existing Action and reuse idempotency
---




Phase 1
UX Architecture (Ask)

↓

Phase 2
Implementation

↓

Phase 3
UX Review

↓

Phase 4
Security Review

↓

Phase 5
Performance Review

↓

Phase 6
Production Readiness

↓

Merge


1. Landing Experience

2. Browsing Experience

3. Product Experience

4. Checkout Experience

5. Customer Experience

6. Delight Experience
