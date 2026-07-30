<?php

declare(strict_types=1);

use App\Enums\CustomerFinancialInvalidationReason;
use App\Support\Financial\CustomerFinancialRealtimeScope;

it('keeps only allowlisted financial reasons', function (): void {
    $reasons = CustomerFinancialRealtimeScope::reasons([
        'reasons' => [
            'topup_state_changed',
            'crafted_reason',
            'transaction_posted',
            'topup_state_changed',
        ],
    ]);

    expect($reasons)->toBe([
        CustomerFinancialInvalidationReason::TopupStateChanged,
        CustomerFinancialInvalidationReason::TransactionPosted,
    ]);
});

it('maps reasons to only affected financial surfaces', function (): void {
    expect(CustomerFinancialRealtimeScope::isRelevant(
        ['reasons' => ['payout_request_state_changed']],
        CustomerFinancialRealtimeScope::SURFACE_EARNINGS,
    ))->toBeTrue()
        ->and(CustomerFinancialRealtimeScope::isRelevant(
            ['reasons' => ['payout_request_state_changed']],
            CustomerFinancialRealtimeScope::SURFACE_OVERVIEW,
        ))->toBeFalse()
        ->and(CustomerFinancialRealtimeScope::isRelevant(
            ['reasons' => ['refund_state_changed']],
            CustomerFinancialRealtimeScope::SURFACE_REFUND_DETAIL,
        ))->toBeTrue()
        ->and(CustomerFinancialRealtimeScope::isRelevant(
            ['reasons' => ['refund_state_changed']],
            CustomerFinancialRealtimeScope::SURFACE_TOPUP_LIST,
        ))->toBeFalse()
        ->and(CustomerFinancialRealtimeScope::isRelevant(
            ['reasons' => ['transaction_posted']],
            CustomerFinancialRealtimeScope::SURFACE_LEDGER,
        ))->toBeTrue();
});

it('allows bounded lifecycle reconciliation on every mounted surface', function (): void {
    expect(CustomerFinancialRealtimeScope::isRelevant(
        ['isReconcile' => true, 'reasons' => []],
        CustomerFinancialRealtimeScope::SURFACE_TRANSACTION_DETAIL,
    ))->toBeTrue();
});

it('ignores empty crafted browser payloads', function (): void {
    expect(CustomerFinancialRealtimeScope::isRelevant(
        ['reasons' => ['not_allowed']],
        CustomerFinancialRealtimeScope::SURFACE_OVERVIEW,
    ))->toBeFalse();
});
