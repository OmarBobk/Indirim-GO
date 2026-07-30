<?php

declare(strict_types=1);

use App\DTOs\Financial\CustomerTransactionDetailDTO;
use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\FinancialDestinationType;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Support\CustomerTransactionDetailPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('presents en ar money direction receipt disclaimer and destinations', function (): void {
    $user = User::factory()->create(['locale' => 'en']);

    $dto = new CustomerTransactionDetailDTO(
        stableKey: 'wtx:1',
        publicReference: 'WTX-AABBCCDDEE',
        transactionType: WalletTransactionType::Refund,
        direction: WalletTransactionDirection::Credit,
        status: 'posted',
        amount: '12.50',
        currency: 'USD',
        postedAt: Carbon::parse('2026-07-01 12:00:00'),
        balanceBefore: '10.00',
        balanceAfter: '22.50',
        moneyIn: true,
        hasBalanceSnapshots: true,
        isIntegrityAnomaly: false,
        sourceTitle: null,
        sourceDescription: null,
        relatedOrderNumber: 'ORD-1',
        relatedTopupPublicRef: null,
        relatedRefundPublicRef: 'WTX-AABBCCDDEE',
        paymentMethodName: null,
        productLabel: 'Pack A',
        customerSafeReason: null,
        timeline: [
            ['key' => 'posted', 'label_key' => 'messages.transaction_timeline_posted', 'occurred_at' => '2026-07-01T12:00:00+00:00'],
        ],
        sourceDestination: new FinancialDestinationDTO(
            FinancialDestinationType::WalletRefundDetail,
            ['public_ref' => 'WTX-AABBCCDDEE']
        ),
        listDestination: new FinancialDestinationDTO(FinancialDestinationType::WalletTransactions),
        receiptVersion: 1,
    );

    app()->setLocale('en');
    $en = app(CustomerTransactionDetailPresenter::class)->present($dto, $user);

    expect($en['direction_label'])->toBe(__('messages.transaction_money_in'))
        ->and($en['amount']['dir'])->toBe('ltr')
        ->and($en['amount']['formatted'])->toStartWith('+')
        ->and($en['amount']['formatted'])->toContain('12.50')
        ->and($en['balance_before']['available'])->toBeTrue()
        ->and($en['receipt']['disclaimer'])->toBe(__('messages.transaction_receipt_disclaimer'))
        ->and($en['actions']['source_label'])->toBe(__('messages.transaction_view_refund'))
        ->and($en['actions']['print_label'])->toBe(__('messages.transaction_print_receipt'));

    app()->setLocale('ar');
    $ar = app(CustomerTransactionDetailPresenter::class)->present($dto, $user);

    expect($ar['heading'])->toBe(__('messages.transaction_detail_title'))
        ->and($ar['direction_label'])->toBe(__('messages.transaction_money_in'))
        ->and($ar['receipt']['title'])->toBe(__('messages.transaction_receipt_title'));
});

it('presents unavailable balances without fabricating amounts', function (): void {
    $user = User::factory()->create();

    $dto = new CustomerTransactionDetailDTO(
        stableKey: 'wtx:2',
        publicReference: 'WTX-1122334455',
        transactionType: WalletTransactionType::Adjustment,
        direction: WalletTransactionDirection::Credit,
        status: 'posted',
        amount: '5.00',
        currency: 'USD',
        postedAt: now(),
        balanceBefore: null,
        balanceAfter: null,
        moneyIn: true,
        hasBalanceSnapshots: false,
        isIntegrityAnomaly: true,
        sourceTitle: null,
        sourceDescription: null,
        relatedOrderNumber: null,
        relatedTopupPublicRef: null,
        relatedRefundPublicRef: null,
        paymentMethodName: null,
        productLabel: null,
        customerSafeReason: 'Safe reason',
        timeline: [],
        sourceDestination: null,
        listDestination: new FinancialDestinationDTO(FinancialDestinationType::WalletTransactions),
        receiptVersion: 1,
    );

    $view = app(CustomerTransactionDetailPresenter::class)->present($dto, $user);

    expect($view['has_balance_snapshots'])->toBeFalse()
        ->and($view['balance_before']['available'])->toBeFalse()
        ->and($view['source']['customer_reason'])->toBe('Safe reason');
});
