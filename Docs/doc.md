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
  - ###TODO: Referral Feature:
    - ###DONE: Salesperson should be able to create new user under him, see users under him and update there information (phone, username, password, email, name)
    - ###DONE: Admin should be able to set the commission percent for every salesperson
    - ###DONE: Salesperson should see only the dashboard there is no need to access the fulfillments
    - ###DONE: if admin hit the salesperson dashboard he should be able to select some salesperson to see his states
    - ###DONE: add the default commission rate to the website settings
    - ###DONE: salesperson dashboard light mode isn't matched
  - ###TODO: admin/users/{user}: customer price needs to be fixed
  - ###TODO: Add new products
  - ###TODO: Set the testing environment
  - ###DONE: when new user is created automatically the customer role should be assigned to him.
  - ###DONE: if admin approve an topup request by accident what he should do ?
  - ###DONE: orders/{order}: "Delivered payload" should not be hashed like this "••••••••••••••••klds"
  - ###DONE: Add the payments ways like iban, sham cash wallet barcode, usdt wallt ...etc
  - ###TODO: Automation manager:
    - ###DONE: in the record details the Raw log excerpt json array should be sorted 
    - ###DONE: the screenshots are repeated twice in "automation-worker/storage" and in "storage/app/public"
    - ###TODO: when user order product and the automation is failed and the fulfillment is also marked as failed now if user tried to order the product again there is no order created instead he gets the order success message whit the order id of the old one (the failed one)
  
- Frontend:
  - ###DONE wallet transaction in /wallets should be more described
  - ###DONE Wallet /wallet Request topups form borders remove the ring
  - ###DONE /orders Redesign
  - ###DONE Register form: mask the phone number field
  - ###DONE: Topups: when customer want to request a new topup he should see a toggle button if checked it then he need to upload the proof file if not then he can request the topup without uploading the proof
  - ###DONE: main page search field is not working


You are an expert UI designer and full-stack Laravel developer with 20+ years exp. You build visually stunning, production-grade interfaces using Laravel 13, Livewire 4, TailwindCSS, and Alpine.js.    

You are an expert UI designer and full-stack Laravel developer with 20+ years exp. You build visually stunning, production-grade interfaces using Laravel 12, Livewire 4, TailwindCSS, and Alpine.js.
Lets build a new page in the backend Content Management section
first of all only admins and supervisor (with update_product_prices permission) can go for it and use it.
now the main task of this page is to make the updating products price process easiest
now the idea is like this:
- first user need to select a package
- then he will see the fields on two side to side sections.
- the left section will contain fields that filled with the product name, id, entry_price. (these fields disabled)
- on the right section user can enter the new entry price..


Admin Profit: 19.25 USD
Salesperson Profit: 5.69 USD




**🔒 Ticket → Audit → Fix → Lock**


You a senior prompt Generator with 20+ years exp
first you can scan the uploaded file to understand my system.
second lets go Ask → Plan → Agent → Review → Fix to Make Cursor implements this fix as a senior Laravel 12, Livewire 4, Tailwind and Alpinejs
now sometime cursor is overengineering so tell him what you need to tell to not do that.
and by there are a lot of places where there a better path for performance for high quality code and fast and even best practices that cursor doesn't take 
Cursor should take the best approach in everything code readability, maintance, high quality, better performance and all what Expert Developer are care about


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
