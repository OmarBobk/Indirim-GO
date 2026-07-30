<?php

use App\Actions\Financial\GetCustomerFinancialOverview;
use App\Support\CustomerFinancialPresenter;
use App\Support\Financial\CustomerFinancialRealtimeScope;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

new #[Layout('layouts::frontend')] class extends Component
{
    use Toastable;

    public bool $isRefreshing = false;

    /** @var array<string, mixed>|null */
    public ?array $overview = null;

    public function mount(GetCustomerFinancialOverview $getOverview, CustomerFinancialPresenter $presenter): void
    {
        abort_unless(auth()->check(), 403);

        if (session()->pull('topup_submitted')) {
            $this->success(__('messages.topup_request_created'));
        }

        $this->loadOverview($getOverview, $presenter);
    }

    #[On('customer-financial-invalidate')]
    public function onFinancialInvalidate(
        GetCustomerFinancialOverview $getOverview,
        CustomerFinancialPresenter $presenter,
        array $reasons = [],
        bool $isReconcile = false,
    ): void {
        if (! CustomerFinancialRealtimeScope::isRelevant(
            ['reasons' => $reasons, 'isReconcile' => $isReconcile],
            CustomerFinancialRealtimeScope::SURFACE_OVERVIEW,
        )) {
            $this->skipRender();

            return;
        }

        $this->isRefreshing = true;
        $this->loadOverview($getOverview, $presenter);
        $this->isRefreshing = false;
        $this->dispatch('financial-overview-updated');
    }

    public function refreshOverview(
        GetCustomerFinancialOverview $getOverview,
        CustomerFinancialPresenter $presenter,
    ): void {
        $this->onFinancialInvalidate($getOverview, $presenter);
    }

    private function loadOverview(
        GetCustomerFinancialOverview $getOverview,
        CustomerFinancialPresenter $presenter,
    ): void {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $dto = $getOverview->handle($user);
        $this->overview = $presenter->present($dto, $user);
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.financial_overview_title'));
    }
};
?>

@php
    /** @var array<string, mixed> $overview */
    $overview = $this->overview ?? [
        'balance' => null,
        'actions' => [],
        'pending' => ['items' => [], 'is_empty' => true],
        'recent' => ['items' => [], 'is_empty' => true],
        'a11y' => [],
    ];
@endphp

<x-storefront.page
    width="work"
    data-test="wallet-page"
    data-financial-surface="overview"
    aria-busy="{{ $this->isRefreshing ? 'true' : 'false' }}"
    wire:key="financial-overview"
>
    <div
        class="storefront-section-stack"
        data-test="financial-overview"
        @financial-overview-updated.window="$el.setAttribute('data-financial-updated', Date.now())"
    >
        <x-storefront.page-header
            :title="__('messages.financial_overview_title')"
            :description="__('messages.financial_overview_subtitle')"
        />

        <x-wallet.financial-centre-nav active="overview" />

        <x-wallet.overview-balance :balance="$overview['balance']" :busy="$this->isRefreshing" />

        <x-wallet.primary-actions :actions="$overview['actions']" />

        <x-wallet.pending-summary :pending="$overview['pending']" />

        <x-wallet.recent-transactions :recent="$overview['recent']" :actions="$overview['actions']" />

        @if (($overview['actions']['show_salesperson_link'] ?? false) && ($overview['actions']['salesperson_href'] ?? null))
            <p class="text-sm text-zinc-600 dark:text-zinc-400" data-test="financial-salesperson-link">
                <a href="{{ $overview['actions']['salesperson_href'] }}" wire:navigate class="font-medium text-(--color-accent) hover:underline">
                    {{ __('messages.financial_salesperson_earnings_link') }}
                </a>
            </p>
        @endif

        <p class="text-center text-sm text-zinc-500 dark:text-zinc-400" data-test="financial-loyalty-link">
            <a href="{{ $overview['actions']['loyalty_href'] ?? route('loyalty') }}" wire:navigate class="underline-offset-2 hover:underline">
                {{ __('messages.financial_loyalty_link') }}
            </a>
        </p>
    </div>
</x-storefront.page>
