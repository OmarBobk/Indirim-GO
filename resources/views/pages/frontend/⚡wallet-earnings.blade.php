<?php

use App\Actions\Commissions\RequestSalespersonPayout;
use App\Actions\Earnings\GetSalespersonEarnings;
use App\DTOs\Earnings\SalespersonEarningsFilters;
use App\DTOs\Earnings\SalespersonEarningsPageDTO;
use App\Support\SalespersonEarningsPresenter;
use App\Support\Financial\CustomerFinancialRealtimeScope;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::frontend')] class extends Component
{
    use WithPagination;

    public int $perPage = SalespersonEarningsFilters::PER_PAGE;

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: '')]
    public string $search = '';

    public bool $hasPendingRefresh = false;

    public bool $isBusy = false;

    public ?string $payoutFlash = null;

    private ?SalespersonEarningsPageDTO $pageResult = null;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        abort_unless(auth()->user()?->can('view_referrals'), 403);
        $this->normalizeFilters();
        $this->loadPage();
    }

    public function updatedStatus(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
        $this->hasPendingRefresh = false;
        $this->forgetPage();
    }

    public function updatedSearch(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
        $this->hasPendingRefresh = false;
        $this->forgetPage();
    }

    public function updatedPage(mixed $page): void
    {
        if ((int) $page <= 1) {
            $this->hasPendingRefresh = false;
        }

        $this->forgetPage();
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedStatus();
    }

    public function clearFilters(): void
    {
        $this->status = 'all';
        $this->search = '';
        $this->resetPage();
        $this->hasPendingRefresh = false;
        $this->forgetPage();
    }

    public function applyPendingRefresh(): void
    {
        $this->hasPendingRefresh = false;
        $this->resetPage();
        $this->forgetPage();
        $this->loadPage();
        $this->dispatch('earnings-updated');
    }

    #[On('customer-financial-invalidate')]
    public function handleFinancialInvalidate(array $reasons = [], bool $isReconcile = false): void
    {
        if (! CustomerFinancialRealtimeScope::isRelevant(
            ['reasons' => $reasons, 'isReconcile' => $isReconcile],
            CustomerFinancialRealtimeScope::SURFACE_EARNINGS,
        )) {
            $this->skipRender();

            return;
        }

        if ($this->getPage() > 1) {
            $this->hasPendingRefresh = true;
            $this->skipRender();

            return;
        }

        $this->forgetPage();
        $this->loadPage();
        $this->dispatch('earnings-updated');
    }

    public function requestPayout(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->can('view_referrals'), 403);

        $result = app(RequestSalespersonPayout::class)->handle($user);

        $this->payoutFlash = match ($result) {
            'created' => __('messages.earnings_payout_request_created'),
            'already_pending' => __('messages.earnings_payout_request_already_pending'),
            'below_min' => __('messages.earnings_payout_request_below_min'),
            default => __('messages.earnings_payout_request_failed'),
        };

        $this->forgetPage();
        $this->loadPage();
    }

    private function normalizeFilters(): void
    {
        $parsed = SalespersonEarningsFilters::fromInput([
            'status' => $this->status,
            'search' => $this->search,
            'page' => $this->getPage(),
        ]);
        $this->status = $parsed->status;
        $this->search = $parsed->search;
    }

    private function loadPage(): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $this->isBusy = true;

        $this->pageResult = app(GetSalespersonEarnings::class)->handle(
            $user,
            SalespersonEarningsFilters::fromInput([
                'status' => $this->status,
                'search' => $this->search,
                'page' => $this->getPage(),
            ])
        );

        $this->isBusy = false;
    }

    private function forgetPage(): void
    {
        $this->pageResult = null;
    }

    private function page(): SalespersonEarningsPageDTO
    {
        if ($this->pageResult === null) {
            $this->loadPage();
        }

        /** @var SalespersonEarningsPageDTO $page */
        $page = $this->pageResult;

        return $page;
    }

    public function getEarningsProperty(): array
    {
        return app(SalespersonEarningsPresenter::class)->present($this->page(), auth()->user());
    }

    public function getPaginatorProperty(): LengthAwarePaginator
    {
        $page = $this->page();

        return new LengthAwarePaginator(
            items: $page->items,
            total: $page->total,
            perPage: $page->perPage,
            currentPage: $page->currentPage,
            options: [
                'path' => route('wallet.earnings.index'),
                'pageName' => 'page',
            ]
        );
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.earnings_title'));
    }
};
?>

@php
    $earnings = $this->earnings;
@endphp

<x-storefront.page
    width="work"
    data-test="wallet-earnings-page"
    data-financial-surface="earnings"
    aria-busy="{{ $this->isBusy ? 'true' : 'false' }}"
>
    <div class="storefront-section-stack" aria-live="polite">
        <x-storefront.page-header
            :title="__('messages.earnings_title')"
            :description="__('messages.earnings_subtitle')"
            :show-back="true"
            :back-fallback="route('wallet')"
        />

        <x-wallet.financial-centre-nav active="earnings" />

        @if ($this->hasPendingRefresh)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100" data-test="earnings-refresh-banner" role="status">
                <p>{{ $earnings['a11y']['new_available'] ?? __('messages.earnings_updates_available') }}</p>
                <button type="button" wire:click="applyPendingRefresh" class="mt-2 font-semibold underline">
                    {{ __('messages.financial_ledger_return_latest') }}
                </button>
            </div>
        @endif

        @if ($this->payoutFlash)
            <p class="text-sm text-zinc-700 dark:text-zinc-200" data-test="earnings-payout-flash" role="status">{{ $this->payoutFlash }}</p>
        @endif

        <x-earnings.summary :summary="$earnings['summary'] ?? []" :links="$earnings['links'] ?? []" />

        <x-earnings.payout-request :payout="$earnings['payout'] ?? []" />

        <x-earnings.filters
            :filters="$earnings['filters'] ?? []"
            :a11y="$earnings['a11y'] ?? []"
        />

        <x-earnings.commission-list
            :items="$earnings['items'] ?? []"
            :is-empty="$earnings['is_empty'] ?? false"
            :is-filtered-empty="$earnings['is_filtered_empty'] ?? false"
            :search="$earnings['filters']['search'] ?? ''"
        />

        @if ($earnings['pagination']['has_pages'] ?? false)
            <div class="flex justify-center" data-test="earnings-pagination">
                {{ $this->paginator->links() }}
            </div>
        @endif

        @if (! empty($earnings['recent_credits']))
            <x-earnings.payout-history :credits="$earnings['recent_credits']" />
        @endif
    </div>
</x-storefront.page>
