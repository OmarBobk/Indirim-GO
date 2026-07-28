# Orders & Checkout

Storefront purchase flow: cart, buy-now, checkout, pay with wallet.

## Invariants

- Cart: client `localStorage` key `karman.cart.v1`; server revalidates at checkout
- Pricing: server-side `app/Domain/Pricing/*` — never trust client totals
- Custom-amount lines: quantity 1, `requested_amount` semantic

## Key files

- `app/Actions/Orders/CheckoutFromPayload.php`, `PayOrderWithWallet.php`, `CreateOrderFromCartPayload.php`
- `routes/web.php` — `/orders`, checkout endpoints
- `resources/js/app.js` — cart state

## Features

- [[Customer Activity]] — order status in timeline

## Related

- [[Fulfillments & Automation]]
- [[Wallet & Ledger]]
