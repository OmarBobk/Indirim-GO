<?php

declare(strict_types=1);

use App\DTOs\Earnings\CommissionDTO;
use App\DTOs\Earnings\SalespersonEarningsFilters;
use App\DTOs\Earnings\SalespersonEarningsPageDTO;
use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\CommissionStatus;
use App\Enums\FinancialDestinationType;
use App\Models\User;
use App\Support\SalespersonEarningsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('presents pending and credited labels in en and ar', function (): void {
    $user = User::factory()->create(['locale' => 'en']);

    $dto = new SalespersonEarningsPageDTO(
        pendingTotal: '10.00',
        eligibleTotal: '5.00',
        creditedTotal: '40.00',
        creditedThisMonth: '12.00',
        failedTotal: '3.00',
        generatedTotal: '53.00',
        pendingCount: 1,
        creditedCount: 2,
        failedCount: 1,
        walletAvailableToSpend: '40.00',
        walletCurrency: 'USD',
        payoutThreshold: '10.00',
        waitDays: 7,
        canRequestPayout: false,
        payoutRequestStatus: null,
        payoutRequestEligibleAmount: null,
        payoutRequestCreatedAt: null,
        items: [
            new CommissionDTO(
                stableKey: 'com:1',
                status: CommissionStatus::Pending,
                amount: '10.00',
                currency: 'USD',
                ratePercent: '20.00',
                orderNumber: 'ORD-1',
                orderTotal: '50.00',
                customerSafeLabel: 'Ada',
                createdAt: Carbon::parse('2026-07-01'),
                creditedAt: null,
                isEligible: false,
                isIntegrityAnomaly: false,
                walletTransactionPublicRef: null,
                actorNextKey: 'messages.earnings_actor_wait',
                transactionDestination: null,
                orderDestination: null,
            ),
        ],
        filters: new SalespersonEarningsFilters,
        currentPage: 1,
        perPage: 20,
        total: 1,
        lastPage: 1,
        recentCredits: [],
        pricesVisible: true,
        walletDestination: new FinancialDestinationDTO(FinancialDestinationType::Wallet),
        transactionsDestination: new FinancialDestinationDTO(FinancialDestinationType::WalletTransactions),
        dashboardDestination: new FinancialDestinationDTO(FinancialDestinationType::SalespersonDashboard),
    );

    app()->setLocale('en');
    $en = app(SalespersonEarningsPresenter::class)->present($dto, $user);
    expect($en['items'][0]['status_label'])->toBe(__('messages.earnings_status_pending'))
        ->and($en['items'][0]['amount']['dir'])->toBe('ltr')
        ->and($en['summary']['pending_not_spendable'])->toBe(__('messages.earnings_pending_not_spendable'));

    app()->setLocale('ar');
    $ar = app(SalespersonEarningsPresenter::class)->present($dto, $user);
    expect($ar['items'][0]['status_label'])->toBe(__('messages.earnings_status_pending'));
});
