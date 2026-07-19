## Logging Map

Activity log events (spatie/laravel-activitylog): where each `activity()->log()` is emitted, subject model, and key properties. Where relevant, `Log::` usage is noted.

### Payments
| Event | Logged in | Subject | Key properties |
| --- | --- | --- | --- |
| `topup.requested` | `resources/views/pages/frontend/⚡wallet.blade.php` (Livewire) | `TopupRequest` | `topup_request_id`, `wallet_id`, `user_id`, `amount`, `currency`, `method` |
| `topup.approved` | `app/Actions/Topups/ApproveTopupRequest.php` | `TopupRequest` | `topup_request_id`, `wallet_id`, `user_id`, `amount`, `currency`, `transaction_id` |
| `topup.rejected` | `app/Actions/Topups/RejectTopupRequest.php` | `TopupRequest` | `topup_request_id`, `wallet_id`, `user_id`, `amount`, `currency`, `transaction_id` |
| `wallet.credited` | `app/Actions/Topups/ApproveTopupRequest.php`, `app/Actions/Refunds/ApproveRefundRequest.php`, `app/Actions/Wallets/AdjustWallet.php` | `Wallet` | `wallet_id`, `user_id`, `amount`, `currency`, `transaction_id`, `source` (`topup`/`commission`/`adjustment`) |
| `wallet.adjusted` | `app/Actions/Wallets/AdjustWallet.php` | `WalletTransaction` | `wallet_id`, `target_user_id`, `actor_id`, `amount`, `currency`, `adjustment_kind`, `reason`, `previous_balance`, `new_balance`, `transaction_id`, `idempotency_key`, `ip_address` |
| `wallet.debited` | `app/Actions/Orders/PayOrderWithWallet.php` | `WalletTransaction` | `wallet_id`, `order_id`, `transaction_id`, `amount`, `currency`, `direction` |
| `wallet.reconciled` | `app/Console/Commands/WalletReconcile.php` | `Wallet` | `wallet_id`, `user_id`, `stored_balance`, `expected_balance`, `diff` |
| `refund.requested` | `app/Actions/Orders/RefundOrderItem.php` | `WalletTransaction` | `transaction_id`, `order_id`, `order_item_id`, `fulfillment_id`, `wallet_id`, `amount`, `currency`, `note` |
| `refund.approved` | `app/Actions/Refunds/ApproveRefundRequest.php` | `WalletTransaction` | `transaction_id`, `idempotency_key`, `order_id`, `wallet_id`, `amount`, `currency`, `reason` |
| `refund.rejected` | `app/Actions/Refunds/RejectRefundRequest.php` | `WalletTransaction` | `transaction_id`, `order_id`, `order_item_id`, `fulfillment_id`, `amount`, `currency` |

### Orders
| Event | Logged in | Subject | Key properties |
| --- | --- | --- | --- |
| `order.created` | `app/Actions/Orders/CreateOrderFromCartPayload.php` | `Order` | `order_id`, `order_number`, `item_count`, `subtotal`, `fee`, `total`, `currency`, `status_to` |
| `order.paid` | `app/Actions/Orders/PayOrderWithWallet.php` | `Order` | `order_id`, `order_number`, `amount`, `currency`, `status_from`, `status_to`, `transaction_id`, `wallet_id` |
| `order.refunded` | `app/Actions/Refunds/ApproveRefundRequest.php` | `Order` | `order_id`, `order_number`, `status_from`, `status_to`, `amount`, `currency`, `transaction_id` |

### Fulfillment
| Event | Logged in | Subject | Key properties |
| --- | --- | --- | --- |
| `fulfillment.queued` | `app/Actions/Fulfillments/CreateFulfillmentsForOrder.php` | `Fulfillment` | `fulfillment_id`, `order_id`, `order_item_id`, `provider`, `status_to` |
| `fulfillment.processing` | `app/Actions/Fulfillments/StartFulfillment.php` | `Fulfillment` | `fulfillment_id`, `order_id`, `order_item_id`, `provider`, `status_from`, `status_to`, `attempts`, `actor`, `actor_id` |
| `fulfillment.completed` | `app/Actions/Fulfillments/CompleteFulfillment.php` | `Fulfillment` | `fulfillment_id`, `order_id`, `order_item_id`, `provider`, `status_from`, `status_to`, `actor`, `actor_id` |
| `fulfillment.failed` | `app/Actions/Fulfillments/FailFulfillment.php` | `Fulfillment` | `fulfillment_id`, `order_id`, `order_item_id`, `provider`, `status_from`, `status_to`, `reason`, `actor`, `actor_id` |
| `fulfillment.retry_requested` | `app/Actions/Fulfillments/RetryFulfillment.php` | `Fulfillment` | `fulfillment_id`, `order_id`, `order_item_id`, `status_from`, `status_to`, `retry_count`, `actor`, `actor_id` |
| `fulfillment.process_failed` | `app/Console/Commands/ProcessFulfillments.php` | `Fulfillment` | `fulfillment_id`, `order_id`, `order_item_id`, `provider`, `error` (also `Log::error` in same command) |

### Admin & Security
| Event | Logged in | Subject | Key properties |
| --- | --- | --- | --- |
| `admin.login` | `app/Actions/Fortify/LoginResponse.php` | `User` | `user_id`, `email` |
| `admin.logout` | `app/Providers/AppServiceProvider.php` | `User` | `user_id`, `email` |
| `user.login` | `app/Actions/Fortify/LoginResponse.php` | `User` | `user_id`, `email` |
| `user.logout` | `app/Providers/AppServiceProvider.php` | `User` | `user_id`, `email` |
| `user.registered` | `app/Actions/Fortify/CreateNewUser.php` | `User` | `user_id`, `email`, `username`, `country_code`, `timezone` |
| `user.created` | `app/Actions/Users/CreateUser.php` | `User` | `user_id`, `email`, `username` |
| `user.updated` | `app/Actions/Users/UpdateUser.php` | `User` | `user_id`, `email` |
| `user.deleted` | `app/Actions/Users/DeleteUser.php` | — | `user_id`, `email`, `username` |
| `user.blocked` | `app/Actions/Users/BlockUser.php` | `User` | `user_id`, `email` |
| `user.unblocked` | `app/Actions/Users/UnblockUser.php` | `User` | `user_id`, `email` |
| `user.password_reset` | `app/Actions/Users/AdminResetUserPassword.php` | `User` | `user_id`, `email` |
| `user.email_verified` | `app/Actions/Users/VerifyUserEmail.php` | `User` | `user_id`, `email` |
| `user.roles_updated` | `app/Actions/Users/UpdateUser.php` | `User` | `user_id`, `roles`, `previous_roles` |
| `user.permissions_updated` | `app/Actions/Users/UpdateUser.php` | `User` | `user_id`, `permissions`, `previous_permissions` |
| `category.deleted` | `app/Actions/Categories/DeleteCategoryTree.php` | `Category` | `root_category_id`, `deleted_count`, `deleted_ids` |
| `product.deleted` | `app/Actions/Products/DeleteProduct.php` | `Product` | `product_id`, `name`, `package_id` |
| `package.deleted` | `app/Actions/Packages/DeletePackage.php` | `Package` | `package_id`, `name`, `category_id` |
