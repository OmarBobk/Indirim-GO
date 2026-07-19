<?php

return [
    'currency' => 'USD',
    'currency_symbol' => '$',
    'checkout_fee_fixed' => 0,
    /** Minutes after payment during which an identical cart reuses the same Paid order (double-submit protection). */
    'checkout_paid_idempotency_minutes' => (int) env('BILLING_CHECKOUT_PAID_IDEMPOTENCY_MINUTES', 5),
    'custom_amount_hard_cap' => (int) env('BILLING_CUSTOM_AMOUNT_HARD_CAP', 100000000),
    /** Maximum single admin wallet adjustment amount (ledger currency). */
    'wallet_adjustment_max_amount' => env('BILLING_WALLET_ADJUSTMENT_MAX_AMOUNT', '10000.00'),
];
