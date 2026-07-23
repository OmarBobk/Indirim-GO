# Full Manual Testing Guide (For Non-Technical Testers)

This document is the **complete testing playbook** for the store. Testers do **not** need technical knowledge. Follow steps in order within each section, or use the checklists to track progress.

---

## How to read this guide

| Term | Meaning |
|------|--------|
| **Success message** | Green toast or positive text after saving. |
| **Error message** | Red text or warning when something is wrong. |
| **Wallet / balance** | Money stored in the customer account for purchases. Balance may show red/negative when the customer has an Active credit facility and has overdrawn. |
| **Staff area** | Pages after login for **Admin**, **Salesperson**, or **Supervisor** (sidebar on the left). |
| **Storefront** | The public shop (homepage, cart, wallet for customers). |

**Before testing:** Get test logins from your team. Use **staging** or a **test server**, not real customer data unless approved.

---

## Table of contents

1. [Before you start](#before-you-start)
2. [Roles and who sees what](#roles-and-who-sees-what)
3. [Part A — Customer](#part-a--customer)
4. [Part B — Admin](#part-b--admin) (includes **Bug reports** for permitted accounts)
5. [Part C — Salesperson](#part-c--salesperson)
6. [Part D — Supervisor](#part-d--supervisor)
7. [Part E — Cross-role scenarios](#part-e--cross-role-scenarios)
8. [Part F — Live updates (no refresh)](#part-f--live-updates-no-refresh)
9. [Part G — Push notifications (phone)](#part-g--push-notifications-phone)
10. [Part H — Account security (settings)](#part-h--account-security-settings)
11. [Part I — What each notification means (simple)](#part-i--what-each-notification-means-simple)
12. [Part J — Master checklist (every main screen)](#part-j--master-checklist-every-main-screen)
13. [Part K — Final smoke test](#part-k--final-smoke-test)
14. [Reporting a bug](#reporting-a-bug)

---

## Before you start

- [ ] You have the website address (URL) for testing.
- [ ] You have login details for **Customer**, **Admin**, and at least one of **Salesperson** / **Supervisor** if your team uses them.
- [ ] Use a normal browser (Chrome, Edge, or Firefox).
- [ ] For phone tests: use a real device when the guide says so.

**Tip:** For “customer does something → staff sees it,” open **two browser windows** (or one normal window + one private window) with different accounts.

---

## Roles and who sees what

The system has four **roles**. Your menus depend on **permissions** your company assigned. If something is missing, ask your team.

| Role | Storefront | Staff area (sidebar) |
|------|------------|----------------------|
| **Customer** | Yes | No (must not open staff links) |
| **Salesperson** | Optional | Yes — usually **Dashboard**, **Orders**, **Notifications** only |
| **Supervisor** | Optional | Same idea as Salesperson; **editing** orders may be **off** (see Part D) |
| **Admin** | Optional | Full menus: catalog, operations, money, users, **Bug reports** (if allowed), **Website settings** |

**Important:** Only **Admin** should see **Website settings** in the default setup.

**Bug reports (in-app):** The store has a built-in **Report Bug** tool and a **Bug Inbox** for staff. In the default database setup, only the **admin** role gets the **manage bugs** permission. Your company can give that permission to other staff accounts too. **Normal customers** do **not** see **Report Bug** unless they were given that permission.

---

## Part A — Customer

### Scenario Name: Browse the shop and switch language

**Who is acting:** Anyone (logged out or logged in)

**Goal:** See the homepage and switch **English** / **Arabic** if available.

**Steps:**

1. Open the website (homepage).
2. Scroll and confirm you see **categories**, **packages**, and **products** sections.
3. Find the **language** control (header, footer, or menu — your site may use a link or switch).
4. Switch to the other language.
5. If Arabic is used, check that layout direction feels correct (right-to-left).

**Expected Result:**

- No blank or broken page.
- Text changes after switching language.

**Validation:**

- Switch language **twice** — page should stay readable.

---

### Scenario Name: Create an account and confirm email

**Who is acting:** New user

**Goal:** Register and complete email verification if your team requires it.

**Steps:**

1. Open **Register** (from the user menu or login page).
2. Fill **all required fields** (name, email, username, password — follow the rules on the form).
3. Submit.
4. If the site asks you to verify email, open your inbox and **click the link** or enter the code.

**Expected Result:**

- You can log in after registration (and after verification, if required).

**Error cases:**

- Password and “confirm password” do not match → error, account not created.
- Username or email already used → clear error (not a white screen).

---

### Scenario Name: Log in and log out

**Who is acting:** Customer

**Goal:** Sign in with **username** and password and sign out.

**Steps:**

1. Open **Login**.
2. Enter **username** and **password** (this app uses **username**, not email, on login).
3. Sign in.
4. Open the **user menu** (person icon) → **Logout**.

**Expected Result:**

- After login, you see **Wallet**, **My orders**, etc., when logged in.
- After logout, private items (like wallet) are gone from the header.

**Error cases:**

- Wrong password → error, you stay logged out.
- **Blocked** or **inactive** test user → login refused with a message (if your team has such tests).

---

### Scenario Name: Forgot password

**Who is acting:** Customer

**Goal:** Reset password by email.

**Steps:**

1. On **Login**, click **Forgot your password?** (if shown).
2. Enter the account **email** and submit.
3. Open the email → follow the link.
4. Set a **new** password.
5. Log in with the **new** password.

**Expected Result:**

- Email arrives (may take a minute).
- Old password stops working; new password works.

---

### Scenario Name: Open Wallet and read balance

**Who is acting:** Customer (logged in; email verified if your rules say so)

**Goal:** See wallet balance and history.

**Steps:**

1. Go to the homepage.
2. Click **Add sufficient** in the top bar **or** open the user menu → **Wallet**.
3. Read the **balance**.
4. Scroll to **transaction history** (if shown).

**Expected Result:**

- Balance matches what your test team expects.
- No error page.

**Note:** If the store **hides prices** in settings, the header may not show money — still open **Wallet** to test.

---

### Scenario Name: Request a top-up (add money)

**Who is acting:** Customer

**Goal:** Submit a top-up with amount, payment method, and receipt file.

**Steps:**

1. Open **Wallet**.
2. Enter an amount (must be a **number greater than zero**, e.g. `10` or `25.50`).
3. Choose a **payment method** (e.g. Sham Cash, bank transfer — whatever appears).
4. Attach a **receipt file**: **JPG, PNG, WEBP, or PDF**.
5. Submit the form.

**Expected Result:**

- **Success** message.
- Only **one pending** top-up at a time — if you already have a pending request, the site should **warn** you and block a second one.

**At the same time (cross-role):**

- **Admin** should get an **in-app notification** about a new top-up.
- **Admin** **Topups** page should list the new row (immediately or after refresh — see Part F).

---

### Scenario Name: Top-up — validation tests

**Invalid amount:**

- `0` or negative number → error.
- Letters (e.g. `abc`) → error.
- Empty amount → error.

**Invalid file:**

- File type not JPG / PNG / WEBP / PDF → error.
- File **larger than about 5 megabytes** → error (the limit in the system is **5 MB**).

**Error cases:**

- Click **Submit** twice very fast → only **one** request should exist (or second is blocked).
- **Refresh** the page before submitting → typed data may be lost; **nothing** saved until a successful submit.

---

### Scenario Name: View or download top-up receipt (proof)

**Who is acting:** Customer (or Admin when reviewing)

**Goal:** Open the proof file you uploaded (only for **your** request, or admin).

**Steps:**

1. Customer: from **Wallet** or your team’s link, open the proof **view** or **download** if the UI offers it.
2. Admin: on **Topups**, open the request and open the **receipt** / **proof**.

**Expected Result:**

- File opens or downloads (image or PDF).
- Another customer **cannot** open someone else’s proof (access denied).

---

### Scenario Name: Shopping cart and checkout

**Who is acting:** Customer

**Goal:** Add items and pay with wallet balance (or available credit when a facility is Active).

**Steps:**

1. From the homepage, open a **package** or **product** (e.g. **Add to cart** / **Buy**).
2. Click the **cart** icon in the header (dropdown may open).
3. Go to **Cart** page if needed.
4. Fill **required fields** for each product (IDs, notes — follow labels).
5. Click **Checkout** (or pay).

**Expected Result:**

- Enough available to spend (prepaid balance and/or Active credit headroom) → **payment successful** and an **order number**.
- Not enough available to spend → clear message; **no** successful payment.
- With Active credit, balance may go negative after purchase; topups reduce debt automatically (no separate repay flow).

**Validation:**

- Checkout while **logged out** → message to **sign in**.
- Missing required fields → validation messages **before** payment.

**Error cases:**

- **Refresh** during checkout → no surprise paid order without confirming.

---

### Scenario Name: Buy Now custom amount + pricing estimate (important)

**Who is acting:** Customer

**Goal:** Verify the custom amount pricing system is accurate and safely validated.

**Steps:**

1. Open a product that uses **custom amount** (your team should give you one test product).
2. Open the **Buy Now** modal.
3. Enter an amount and watch the live **estimate** panel.
4. Try a valid amount that follows product rules and place the order.
5. Open the created order and compare shown prices.

**Expected Result:**

- Estimate appears and updates quickly while typing.
- Checkout succeeds for valid values.
- Final charged total is consistent between checkout message, order details, and wallet transaction.

**Validation tests (must run):**

- Enter amount below minimum → clear error.
- Enter amount above maximum → clear error.
- Enter amount not matching step rule (for example step is 5, enter 12) → clear error.
- Leave amount empty → clear error.

**Stress / error tests:**

- Type/change amount very quickly for 10-15 seconds.
- The page should not break; if temporary warning appears, user can still continue after entering a valid value.

---

### Scenario Name: View orders and order details

**Who is acting:** Customer

**Goal:** List **your** orders and open one order.

**Steps:**

1. Click **My orders** (top bar or user menu).
2. Confirm only **your** orders appear.
3. Click one order for **details**.

**Expected Result:**

- Pagination works if there are many orders.
- Statuses show (paid, processing, etc.).

**Error cases:**

- Open another person’s order link → **access denied** or **not found**, not their data.

---

### Scenario Name: Request a refund (when allowed)

**Who is acting:** Customer

**Goal:** Ask staff to refund an eligible line.

**Steps:**

1. Open **My orders** → open an order your team says is **eligible**.
2. On **Order details**, find **Request refund** (or similar) for the right line.
3. Confirm the message.

**Expected Result:**

- Message says **waiting for approval** (or similar).
- Staff with refund access get a **notification**.

**Error cases:**

- Refund not allowed for that line → **not allowed** message.

---

### Scenario Name: Retry a failed delivery

**Who is acting:** Customer

**Goal:** Retry when the UI offers it.

**Steps:**

1. Open an order with a **failed** delivery (test data from your team).
2. Click **Retry** (or similar).
3. Read the message.

**Expected Result:**

- Delivery goes back to **queued / preparing**, or you see **not allowed**.

---

### Scenario Name: Profile page

**Who is acting:** Customer

**Goal:** View profile summary.

**Steps:**

1. Open **Profile** from the menu or top navigation.

**Expected Result:**

- Page loads with your information.

---

### Scenario Name: Edit profile (profile edit page)

**Who is acting:** Customer

**Goal:** Update name, phone, country code, timezone, etc.

**Steps:**

1. Open **Profile** → **Edit** (or go to **Edit profile** / `profile/edit` if your menu shows it).
2. Change **name** or allowed fields.
3. If **country code** is a dropdown, it may only allow **+963** or **+90** (test both allowed and disallowed if your team asks).
4. Save.

**Expected Result:**

- Success message; data after refresh matches.

**Validation:**

- Invalid email format → error (if email is editable).
- Save **empty required** fields → error.

---

### Scenario Name: Loyalty page (if visible)

**Who is acting:** Customer (only if **Loyalty** appears in the menu)

**Goal:** View tier and points / spend.

**Steps:**

1. Open **Loyalty** from the navigation.
2. Read tier name and any progress.

**Expected Result:**

- Page loads with no error.

---

### Scenario Name: Notifications (bell and full page)

**Who is acting:** Customer

**Goal:** See in-app alerts.

**Steps:**

1. Click the **bell** in the header (if present) — list may drop down.
2. Open **Notifications** from the user menu for the **full page**.

**Expected Result:**

- List loads; after **top-up approved**, **refund**, etc., new rows appear (refresh or bell may update).

---

### Scenario Name: Contact page

**Who is acting:** Anyone

**Goal:** Open contact information.

**Steps:**

1. Click **Contact us** in the navigation.

**Expected Result:**

- Page loads; no error.

---

### Scenario Name: Report Bug button on the shop (only some accounts)

**Who is acting:** User logged in **with** bug-report permission (usually **Admin** browsing the shop)

**Goal:** Confirm the floating bug reporter appears when allowed and is hidden for normal customers.

**Steps:**

1. Log in as a **normal customer** (no bug permission).
2. Open the homepage or **Wallet**.

**Expected Result:**

- There is **no** **Report Bug** button in the **bottom corner** of the screen.

3. Log in as **Admin** (default: has bug permission).
4. Open the **storefront** (homepage, cart, etc.).

**Expected Result:**

- A **Report Bug** button appears **fixed near the bottom corner** of the screen (same idea on staff pages after login).

**Note:** This is separate from the plain-text “Reporting a bug” section at the end of this guide (that is how to tell humans about problems). The **Report Bug** button sends a structured report **inside** the app.

---

### Scenario Name: Dark mode (storefront)

**Who is acting:** Anyone

**Goal:** Toggle dark/light if a **moon** or theme button is visible.

**Steps:**

1. Click the **moon** / theme button in the header (if shown).
2. Confirm background and text change.

**Expected Result:**

- Theme switches; no broken layout.

---

### Scenario Name: Open staff dashboard from storefront (Admin / Supervisor)

**Who is acting:** Admin or Supervisor (logged in on storefront)

**Goal:** Shortcut to staff area.

**Steps:**

1. Open the **user menu**.
2. If **Dashboard** appears (for admin/supervisor), click it.

**Expected Result:**

- Staff **Dashboard** opens.

---

## Part B — Admin

**Default:** Full sidebar — **Dashboard**, catalog (categories, packages, products, pricing rules, loyalty tiers), **Operations** (notifications, fulfillments, orders, refunds), **Financials** (topups, customer funds, settlements), **Audit** (activities, system events, users), **Bug reports** + **Website settings** (same sidebar group; **Website settings** is Admin-role only).

---

### Scenario Name: Enter staff area

**Who is acting:** Admin

**Steps:**

1. Log in as Admin.
2. Open **Dashboard** from the sidebar.

**Expected Result:**

- Dashboard loads.

**Error cases:**

- Customer account opening a **staff URL** → **page not found** or safe denial — **not** the real admin dashboard.

---

### Scenario Name: Topups — list, filter, approve, reject, view proof

**Who is acting:** Admin

**Goal:** Handle customer money requests.

**Steps:**

1. Open **Topups** (sidebar may show a **badge** when pending items exist).
2. Use **search** and **status** filter if present — click **Apply**.
3. Open a **Pending** request.
4. Open the **receipt / proof** and confirm it matches the amount.
5. **Approve** one test request.

**Expected Result:**

- Approved request leaves pending (or shows approved).
- Customer **wallet balance** increases by the correct amount.
- Customer gets **in-app notification** (approved).

6. Create or pick another **Pending** request → **Reject** → add a **reason** in the box if shown → confirm.

**Expected Result:**

- Request rejected; customer gets **notification** (rejected).

**Sidebar:**

- Pending count **badge** should update when requests change (or after refresh).

---

### Scenario Name: Fulfillments — search, filter, details modal

**Who is acting:** Admin (must have **Fulfillments** menu)

**Goal:** View deliveries and open details.

**Steps:**

1. Open **Fulfillments** (sidebar may show an indicator).
2. Type in **search** and choose **status** filter → **Apply**.
3. Click **Details** (or a row) to open the **details** window.
4. Read order info, customer, and logs.

**Expected Result:**

- List loads; modal shows correct data.

---

### Scenario Name: Fulfillments — claim task from unclaimed queue

**Who is acting:** Staff with fulfillment access (Admin or another allowed staff account)

**Goal:** Move one task from **Unclaimed** into your own processing list.

**Steps:**

1. Open **Fulfillments**.
2. In **Unclaimed tasks**, click **Claim task** on one row.
3. Check **Processing tasks / My tasks** area.

**Expected Result:**

- Success message appears.
- Task disappears from unclaimed list and appears in your processing list.

---

### Scenario Name: Fulfillments — claim limit for non-admin users

**Who is acting:** Salesperson or Supervisor account that can process fulfillments

**Goal:** Confirm non-admin users cannot keep more than 5 active claimed tasks.

**Steps:**

1. Claim tasks one by one until you have 5 active tasks.
2. Try to claim one more.

**Expected Result:**

- Claim button is disabled or blocked with a clear warning.
- You cannot exceed the 5-task limit.

---

### Scenario Name: Fulfillments — admin oversight tabs and interventions

**Who is acting:** Admin

**Goal:** Validate new operations views and admin intervention actions.

**Steps:**

1. Open **Fulfillments**.
2. Switch between tabs: **Queue View**, **Supervisor Distribution**, **Global Task Table**.
3. From queue or table, use **Intervene** menu (Force complete / Force fail) on a test task.

**Expected Result:**

- Each tab loads with correct counts.
- Intervention actions open the right modals and apply to the selected task.

---

### Scenario Name: Fulfillments — mark completed (manual delivery)

**Who is acting:** Admin

**Goal:** Complete a delivery with delivery details.

**Steps:**

1. Open **Complete** (or similar) for a test fulfillment your team prepared.
2. If the form asks for **delivery details** (sometimes JSON or text), **paste what your team gives you** or use the **auto done** shortcut if the screen offers it.
3. Confirm **Complete**.

**Expected Result:**

- Status **Completed**; customer may see **delivery completed** on the order.
- Customer may get **notification** (completed).

**Validation:**

- Try **Complete** with **empty** required fields → error if the form requires input.

---

### Scenario Name: Fulfillments — mark failed (with reason)

**Who is acting:** Admin

**Goal:** Record a failure.

**Steps:**

1. Open **Fail** / **Mark failed** for a test row.
2. Enter a **reason** (required — up to a long message, about 500 characters max).
3. Submit **without** “refund after fail” first.

**Expected Result:**

- Status **Failed**; customer may see **failed** and get a **notification**.

---

### Scenario Name: Fulfillments — fail and auto-refund (optional)

**Who is acting:** Admin (must have **refund** permission)

**Goal:** Fail and immediately refund the customer.

**Steps:**

1. Open **Fail** for a suitable test fulfillment.
2. Enter **reason**.
3. Turn on **refund after fail** (if shown) and confirm.
4. Save.

**Expected Result:**

- Fulfillment failed **and** customer wallet refunded per business rules; success message mentions refund if applicable.

---

### Scenario Name: Fulfillments — retry

**Who is acting:** Staff processing fulfillments (non-admin path is important)

**Goal:** Put a failed delivery back in the queue.

**Steps:**

1. Find a **Failed** fulfillment (test row).
2. Click **Retry** / **Queue again**.

**Expected Result:**

- Status returns to **Queued** (or processing); success message.

---

### Scenario Name: Refund requests — approve and reject

**Who is acting:** Admin (with refund access)

**Steps:**

1. Open **Refund requests** (sidebar may show a **red dot** when pending).
2. **Approve** one pending test refund.

**Expected Result:**

- Success message; customer **wallet** increases; customer **notification**.

3. Create another pending refund from customer side → **Reject**.

**Expected Result:**

- Reject message; customer **notification**.

---

### Scenario Name: Orders — list and filters

**Who is acting:** Admin

**Steps:**

1. Open **Orders**.
2. Enter **search** text and set **status** / **fulfillment** filters and **dates** if shown.
3. Click **Apply** / **Filter**.
4. Click **Reset** to clear filters.

**Expected Result:**

- List updates; order detail opens correct customer.

---

### Scenario Name: Categories, Packages, Products, Pricing rules, Loyalty tiers

**Who is acting:** Admin (with catalog permissions)

**For each screen, do a light test:**

1. **Categories** — create or rename a **test** category; save; **delete** only if your team allows.
2. **Packages** — open a package; edit **safe** text; save or cancel.
3. **Products** — open a product; save or cancel.
4. **Pricing rules** — open panel; save a **test** rule or cancel.
5. **Loyalty tiers** — view tiers; edit **test** values only.

**Expected Result:**

- Each page opens; saves show success.

---

### Scenario Name: Customer funds and Settlements

**Who is acting:** Admin

**Steps:**

1. Open **Customer funds** — scroll the table; open a row if there is a link.
2. Open **Settlements** — read the list.

**Expected Result:**

- No blank page; numbers look sensible for test data.

---

### Scenario Name: Activities and System events

**Who is acting:** Admin

**Steps:**

1. **Activities** — scroll; filter if available.
2. **System events** — scroll; filter if available.

**Expected Result:**

- Recent actions from your testing session may appear (after refresh or live update).

---

### Scenario Name: Users — full flow

**Who is acting:** Admin

**Steps:**

1. **Users** → **Create** a disposable test user (fake email).
2. Open that user → **Edit** name or role **only if** your team allows.
3. **Block** user → try to log in as that user → **must fail**.
4. **Unblock** → login **works**.
5. **Reset password** (if available) → complete email flow if mail works.
6. **Delete** only **throwaway** users.
7. **Export** users (download **Excel**) → file opens.

**Expected Result:**

- Each step gives clear feedback.

---

### Scenario Name: User audit timeline

**Who is acting:** Admin

**Steps:**

1. **Users** → pick a user → **Audit timeline** (sidebar item when viewing a user).

**Expected Result:**

- Timeline loads with dated entries.

---

### Scenario Name: Website settings (Admin only)

**Who is acting:** Admin

**Steps:**

1. Open **Website settings** (only Admin in default setup).
2. Change **one** approved test setting (e.g. show/hide prices).
3. Save.
4. Open the **storefront** and check behavior (e.g. prices visible or hidden).

**Expected Result:**

- Save succeeds; storefront matches the setting.

**Error cases:**

- **Salesperson** / **Supervisor** should **not** see this menu (default).

---

### Scenario Name: Staff notifications page

**Who is acting:** Admin

**Steps:**

1. Open **Notifications** in the staff sidebar.
2. Scroll through items.

**Expected Result:**

- Items match recent actions (top-ups, fulfillments, etc.).

---

### Scenario Name: Open storefront from staff sidebar

**Who is acting:** Admin

**Steps:**

1. In staff sidebar, click **Homepage** / **Store** link (opens in new tab if your layout does that).

**Expected Result:**

- Public homepage opens in customer view.

---

### Scenario Name: Bug inbox — list and filters

**Who is acting:** Staff with **Bug reports** access (in the default setup this is **Admin**; your team may grant it to others)

**Goal:** Open the bug list and use filters.

**Steps:**

1. Log in to the **staff area**.
2. In the sidebar, under the same group as **Website settings**, click **Bug Reports** (bug icon). A small **indicator** may show when there are open items.
3. Confirm the page title is **Bug Inbox** (or similar).
4. Set **Status**, **Severity**, and **Scenario** filters (e.g. Notification, Topup / Payment, Fulfillment, Dashboard, Other).
5. Click **Apply** (or submit the filter form).

**Expected Result:**

- Table loads with bug rows.
- Filters change the list (try **All** vs one specific status).

---

### Scenario Name: Submit a bug report (floating button)

**Who is acting:** Same as above (logged in **with** bug permission)

**Goal:** File a structured bug from the **Report Bug** button.

**Steps:**

1. Click **Report Bug** (bottom corner on **storefront** or **staff** layout).
2. Complete the **wizard** screens:
   - Choose **area** (scenario), **subtype**, and **severity** (e.g. low / medium / high / critical).
   - Optional short **description** (if shown — max about **250 characters**).
   - Enter **at least two** clear **steps to reproduce** (how you got the problem). You can add more steps (up to about **six** total) with **Add step** if the form offers it.
   - Attach **screenshots**: you must add **at least one** image, and you may add **up to five**. Each file must be an **image** (not PDF) and **no larger than about 5 megabytes** each.
3. Click **Submit bug report** (or the final submit button).

**Expected Result:**

- Success feedback; the modal **closes**.
- A new row appears in **Bug Inbox**.
- If the system thinks it might be a **duplicate**, you may see a **warning** with another report number — note it for triage.

**Validation tests:**

- Submit with **only one** reproduction step filled → should **not** save; error about needing **at least 2 steps**.
- Submit with **no** screenshots → should **not** save.
- Upload **six** screenshots → should **not** allow (max **five**).
- Upload a **non-image** file → error.
- Upload an image **over ~5 MB** → error.

**Error cases:**

- Close the modal with **X** before submitting → report is **not** created.

---

### Scenario Name: Bug detail — read report and update status

**Who is acting:** Staff with **Bug reports** access

**Goal:** Open one report, read evidence, change workflow status.

**Steps:**

1. Open **Bug Inbox** → click **Open** (or the row) for one bug.
2. Read **severity**, **status**, **reporter**, **description**, **reproduction steps**, and **screenshots** (open images if links are shown).
3. Under **Update Status**, pick a new value (e.g. **in progress**, **resolved**) and click **Save**.

**Expected Result:**

- Status saves with confirmation.
- **Linked records** (e.g. order or top-up) may show **Open** buttons — use them only if your test data is safe.

**Attachment downloads:**

- If the report includes file attachments, opening them should only work for **logged-in** users who are allowed to see that bug (same as top-up proof rules in spirit).

---

### Scenario Name: File access control — topup proofs and bug attachments

**Who is acting:** Customer + Admin + one unauthorized test account

**Goal:** Ensure sensitive files can only be opened by allowed users.

**Steps:**

1. As customer, open **your own** topup proof file.
2. As admin, open the same proof from Topups.
3. As another customer (not owner), try opening that proof link directly.
4. For bug reports, open one bug attachment as authorized bug staff.
5. Try the same bug attachment link as a user without bug permission.

**Expected Result:**

- Allowed users can open files.
- Unauthorized users are blocked (no file exposure).

---

## Part C — Salesperson

**Default (seeded permissions):** Usually **Dashboard**, **Orders**, **Notifications**.  
Usually **no** Topups, Refunds, Fulfillments, or catalog editing — unless your company added permissions.

---

### Scenario Name: Salesperson — menus and orders

**Who is acting:** Salesperson

**Steps:**

1. Log in.
2. Open **Dashboard**.
3. Open **Orders** — list and search.
4. Try to open **Topups** or **Categories** by typing URL (only if your team asks for security test).
5. If **Bug Reports** is **not** in your menu, ask your team whether to try opening the bugs page URL directly — you should get **no access** / **not found** (default: only bug-enabled accounts).

**Expected Result:**

- Allowed pages work.
- Forbidden pages show **not found** or **no access** — **not** real data from other areas.

---

### Scenario Name: Salesperson — edit orders (if allowed)

**Who is acting:** Salesperson

**Steps:**

1. Open **Orders** → open **one order** detail.
2. Perform any **edit** actions your screen shows.

**Expected Result:**

- Matches your company policy (Salesperson can **edit** in default seed).

---

## Part D — Supervisor

**Default (seeded permissions):** View sales, view orders, **create** orders — **no** `edit_orders` in the default database seed.

---

### Scenario Name: Supervisor — orders without edit

**Who is acting:** Supervisor

**Steps:**

1. Log in → **Orders**.
2. Open an order detail.
3. Try **edit** actions.

**Expected Result:**

- If your seed is default, **edit** may be **missing** or **denied** — confirm with your team what is correct **today**.

---

## Part E — Cross-role scenarios

### Customer top-up → Admin sees it

1. Customer submits top-up.
2. Admin: check **Notifications** and **Topups** (see Part F for “without refresh”).

**Expected:** Admin alert + customer row in Topups.

---

### Customer refund → Admin approves → Customer balance

1. Customer: request refund.
2. Admin: **Approve** in **Refund requests**.
3. Customer: **Wallet** and **Notifications**.

**Expected:** Balance increases; notification received.

---

### Admin blocks customer

1. Admin: **Block** user.
2. Customer: login **fails**.
3. Admin: **Unblock**.
4. Customer: login **works**.

---

### Staff submits Report Bug → appears in Bug Inbox

1. Log in as staff with **Bug reports** access (default: **Admin**).
2. Submit a **Report Bug** from the floating button (any page where it shows).
3. Open **Bug Inbox** (sidebar → **Bug Reports**).

**Expected:** The new report appears in the list; open it to see steps and screenshots.

---

### Fulfillment event visibility by role (live updates permissions)

1. Open **Fulfillments** with an account that has fulfillment view access (not necessarily admin).
2. Trigger a fulfillment change from another session.
3. Watch for auto-update for a few seconds.

**Expected:** Live updates work for accounts with fulfillment access, not only admin.

---

## Part F — Live updates (no refresh)

**When:** Your team says live updates are **on** for this environment.

**Test:**

1. Admin: **Fulfillments** or **Topups** open on **Computer A**.
2. Customer: submit **top-up** or trigger an order that creates a fulfillment.
3. Wait **a few seconds** without clicking **Refresh**.

**Expected:** List or badges **may** update automatically. If not, press **Refresh** once — data should still be correct.

**Also:**

- After **login** / **logout**, **Activities** may show new lines (refresh if needed).
- **Bug Inbox:** With **Bug Inbox** open on one screen, submit a new **Report Bug** from another tab (same user). The list **may** refresh on its own; if not, use **Refresh** once.

---

## Part G — Push notifications (phone)

**For:** Staff (usually **Admin**) with the **PWA app** or **installed app** and push enabled.

**Steps:**

1. **Lock** the phone.
2. Customer: submit **top-up** (or trigger fulfillment event).
3. Check lock screen:

   - [ ] Notification appears.
   - [ ] Tap opens **Topups** or **Fulfillments** (depends on event).
   - [ ] Sound plays **if** your team enabled sounds.

**If push never arrives:** check internet; confirm with team whether push is enabled on this server. Still verify **in-app** notifications on PC.

---

## Part H — Account security (settings)

Paths may be: `/settings/profile`, `/settings/password`, `/settings/appearance`, `/settings/two-factor`.

### Two-factor authentication (2FA)

1. Log in → **Settings** → **Two-factor authentication**.
2. Turn on 2FA using the QR / codes shown.
3. Log out → log in → enter **second factor**.

**Expected:** Cannot log in without second factor.

### Change password

1. **Settings** → **Password** → set new password.
2. Log out → log in with **new** password only.

### Appearance

1. **Settings** → **Appearance** → switch theme.

---

## Part I — What each notification means (simple)

These are **in-app** alerts users see. **Customer** and **Admin** get different ones.

| Situation (plain language) | Who usually gets it |
|---------------------------|---------------------|
| Customer asked for **top-up** | **Admins** |
| **Top-up approved** or **rejected** | **Customer** |
| New **delivery** created for an order | **Admins** |
| **Delivery completed** | **Customer** |
| **Delivery failed** | **Customer** + **Admins** |
| **Delivery** problem during automatic processing | **Admins** |
| Customer asked for **refund** | **Admins** |
| **Refund approved** or **rejected** | **Customer** |
| **Payment failed** at checkout | **Customer** |
| **Loyalty tier** changed | **Customer** |
| Account **blocked** or **unblocked** | **That user** |
| **Wallet** fixed by maintenance (reconcile) | **Admins** |
| **Profit settlement** created | **Admins** |

*(Exact wording on screen comes from your language files.)*

---

## Part J — Master checklist (every main screen)

Use this as a **full regression** list. Check each box when done.

### Storefront (customer)

- [ ] Home (`/`)
- [ ] Contact (`/contact`)
- [ ] Cart (`/cart`)
- [ ] Login / Register
- [ ] Profile (`/profile`)
- [ ] Edit profile (`/profile/edit`)
- [ ] Wallet (`/wallet`)
- [ ] Loyalty (`/loyalty`) — if visible for user
- [ ] My orders (`/orders`)
- [ ] Order detail (`/orders/{order number}`)
- [ ] Notifications (`/notifications`)
- [ ] Buy Now custom amount product (min/max/step + estimate checks)
- [ ] Language switch (EN / AR)
- [ ] 404 page (`/404` or wrong link)

### Staff (permission-based)

- [ ] Dashboard (`/dashboard`)
- [ ] Categories (`/categories`)
- [ ] Packages (`/packages`)
- [ ] Products (`/products`)
- [ ] Pricing rules (`/pricing-rules`)
- [ ] Loyalty tiers (`/loyalty-tiers`)
- [ ] Staff orders (`/admin/orders`)
- [ ] Staff order detail (`/admin/orders/{id}`)
- [ ] Activities (`/admin/activities`)
- [ ] System events (`/admin/system-events`)
- [ ] Users (`/admin/users`)
- [ ] User detail (`/admin/users/{id}`)
- [ ] User audit (`/admin/users/{id}/audit`)
- [ ] Users export (download Excel)
- [ ] Fulfillments (`/fulfillments`)
- [ ] Refunds (`/refunds`)
- [ ] Topups (`/topups`)
- [ ] Customer funds (`/customer-funds`)
- [ ] Settlements (`/settlements`)
- [ ] Staff notifications (`/admin/notifications`)
- [ ] Bug Inbox (`/admin/bugs`) — **only if your account has Bug reports permission**
- [ ] Bug detail (`/admin/bugs/{id}`) — same permission
- [ ] Website settings (`/admin/website-settings`) — **Admin only**

### Bug attachments (logged-in users)

- [ ] Open a bug attachment link only when your team gives you a **safe test link** — must be logged in as someone allowed to see that report
- [ ] Topup proof link access check (`/topup-proofs/{proof}`) — owner/admin allowed, others blocked

### Account settings (Fortify)

- [ ] Settings profile (`/settings/profile`)
- [ ] Password (`/settings/password`)
- [ ] Appearance (`/settings/appearance`)
- [ ] Two-factor (`/settings/two-factor`)

---

## Part K — Final smoke test (before go-live)

Quick **must-pass** list:

- [ ] **Login** (customer + staff) and **logout**
- [ ] **Homepage** and **Cart**
- [ ] **Checkout** (success **or** clear insufficient-funds message)
- [ ] **Wallet** + **top-up request** + **Admin** sees it
- [ ] **Credit facility** (`/credit-facility`) grant/update when testing overdraft (permission `manage_wallet_credit`)
- [ ] **Orders** (customer list + staff list)
- [ ] **Pricing system**: custom amount min/max/step validation works, and totals are consistent
- [ ] **Pricing precision**: decimal-heavy prices still match between checkout, order details, and wallet
- [ ] **Notifications** (customer + staff)
- [ ] **Admin dashboard** + **Users** list
- [ ] **Fulfillment claim flow**: claim task works, non-admin 5-task cap enforced, admin intervention works
- [ ] **Bug Inbox** opens; **Report Bug** submits a test row (if your Admin has bug access)
- [ ] **Website settings** save (Admin only) — optional if time is short

If **any** fails, **stop** and report before release.

---

## Reporting a bug (for testers — email / chat / ticket)

When you tell your team about a problem **outside** the app (email, chat, ticket), include:

1. **Where** you were (menu names).
2. **What** you expected.
3. **What** happened (copy **error text** if any).
4. **Role** (Customer / Admin / …).
5. **Browser** and **approximate time**.

**Using the in-app tool:** If you see **Report Bug** (bottom corner) and your account is allowed to use it, prefer filing there during test cycles — it captures **steps**, **screenshots**, and **page context** automatically. You can still send a separate email for urgent production issues if your company asks for that.

---

*End of full manual testing guide.*
