<?php

use App\Actions\Financial\GetCustomerTransactionDetail;
use App\Models\WebsiteSetting;
use App\Support\CustomerTransactionDetailPresenter;
use App\Support\Financial\CustomerFinancialRealtimeScope;
use App\Support\WalletTransactionPublicRef;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

new #[Layout('layouts::frontend')] class extends Component
{
    public string $publicRef = '';

    /** @var array<string, mixed>|null */
    public ?array $detail = null;

    public bool $isBusy = false;

    public bool $isPrinting = false;

    public bool $hasDeferredRefresh = false;

    public function mount(string $transaction): void
    {
        abort_unless(auth()->check(), 403);

        $this->publicRef = WalletTransactionPublicRef::normalize($transaction);
        $this->loadDetail();
    }

    #[On('customer-financial-invalidate')]
    public function handleFinancialInvalidate(array $reasons = [], bool $isReconcile = false): void
    {
        if (! CustomerFinancialRealtimeScope::isRelevant(
            ['reasons' => $reasons, 'isReconcile' => $isReconcile],
            CustomerFinancialRealtimeScope::SURFACE_TRANSACTION_DETAIL,
        )) {
            $this->skipRender();

            return;
        }

        if ($this->isPrinting) {
            $this->hasDeferredRefresh = true;
            $this->skipRender();

            return;
        }

        $this->loadDetail();
        $this->dispatch('transaction-detail-updated');
    }

    public function markPrinting(): void
    {
        $this->isPrinting = true;
    }

    public function clearPrinting(): void
    {
        $this->isPrinting = false;

        if (! $this->hasDeferredRefresh) {
            $this->skipRender();

            return;
        }

        $this->hasDeferredRefresh = false;
        $this->loadDetail();
        $this->dispatch('transaction-detail-updated');
    }

    private function loadDetail(): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $this->isBusy = true;

        try {
            $dto = app(GetCustomerTransactionDetail::class)->handle($user, $this->publicRef);
            $this->detail = app(CustomerTransactionDetailPresenter::class)->present(
                $dto,
                $user,
                WebsiteSetting::getPricesVisible()
            );
        } catch (NotFoundHttpException) {
            abort(404);
        }

        $this->isBusy = false;
    }

    public function render(): View
    {
        $ref = $this->detail['public_reference'] ?? $this->publicRef;

        return $this->view()->title(__('messages.transaction_detail_title').' · '.$ref);
    }
};
?>

@php
    $detail = $this->detail ?? [];
@endphp

<div
    class="transaction-detail-page"
    data-test="wallet-transaction-detail-page"
    data-financial-surface="transaction-detail"
    data-printing="{{ $this->isPrinting ? 'true' : 'false' }}"
    aria-busy="{{ $this->isBusy ? 'true' : 'false' }}"
    x-data
    @beforeprint.window="$wire.markPrinting()"
    @afterprint.window="$wire.clearPrinting()"
>
    <x-storefront.page width="work" class="transaction-detail-screen">
        <div class="storefront-section-stack transaction-detail-no-print-nav">
            <x-storefront.page-header
                :title="__('messages.transaction_detail_title')"
                :description="$detail['public_reference'] ?? ''"
                :show-back="true"
                :back-fallback="route('wallet.transactions.index')"
            />

            <x-wallet.financial-centre-nav active="transactions" />
        </div>

        <div
            class="storefront-section-stack"
            wire:key="tx-detail-{{ $detail['stable_key'] ?? $this->publicRef }}"
            aria-live="polite"
            aria-label="{{ $detail['a11y']['region'] ?? '' }}"
        >
            <x-wallet.transaction-detail-header :detail="$detail" />

            <x-wallet.transaction-facts :detail="$detail" />

            <x-wallet.transaction-balance-impact :detail="$detail" />

            <x-wallet.transaction-source :detail="$detail" />

            <x-wallet.transaction-timeline :timeline="$detail['timeline'] ?? []" />

            <x-wallet.transaction-actions :detail="$detail" class="transaction-detail-no-print" />

            <x-wallet.transaction-receipt :detail="$detail" />
        </div>
    </x-storefront.page>
</div>
