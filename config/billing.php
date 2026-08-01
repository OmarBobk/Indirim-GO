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

    /** Maximum credit_limit that can be granted on a customer wallet. */
    'wallet_credit_limit_max' => env('BILLING_WALLET_CREDIT_LIMIT_MAX', '100000.00'),

    /**
     * Allowed payment terms in days (UI may label as Net N).
     *
     * @var list<int>
     */
    'wallet_payment_terms_days' => [15, 30, 45, 60, 90],

    /**
     * M7.1 commission clawback policy (prospective only).
     * When effective_at is null, policy is active from application boot after M7.1 deploy.
     */
    'commission_clawback' => [
        'policy_version' => (int) env('BILLING_COMMISSION_CLAWBACK_POLICY_VERSION', 1),
        'effective_at' => env('BILLING_COMMISSION_CLAWBACK_EFFECTIVE_AT'),
        /** Minutes after attempted_at before processing is considered stale. */
        'processing_stale_minutes' => (int) env('BILLING_COMMISSION_CLAWBACK_STALE_MINUTES', 30),
    ],
];
