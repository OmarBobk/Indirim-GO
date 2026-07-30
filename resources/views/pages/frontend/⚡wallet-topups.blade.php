<?php

use App\Actions\Topups\GetCustomerTopupRequests;
use App\DTOs\Topups\CustomerTopupFilters;
use App\DTOs\Topups\CustomerTopupPageDTO;
use App\Support\CustomerTopupPresenter;
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

    public int $perPage = CustomerTopupFilters::PER_PAGE;

    #[Url(except: 'all')]
    public string $filter = 'all';

    #[Url(except: '')]
    public string $search = '';

    public bool $hasPendingRefresh = false;

    public bool $isBusy = false;

    private ?CustomerTopupPageDTO $pageResult = null;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        $this->normalizeFilters();
        $this->loadPage();
    }

    public function updatedFilter(): void
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

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->updatedFilter();
    }

    public function clearFilters(): void
    {
        $this->filter = 'all';
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
    }

    #[On('customer-financial-invalidate')]
    public function handleFinancialInvalidate(array $reasons = [], bool $isReconcile = false): void
    {
        if (! CustomerFinancialRealtimeScope::isRelevant(
            ['reasons' => $reasons, 'isReconcile' => $isReconcile],
            CustomerFinancialRealtimeScope::SURFACE_TOPUP_LIST,
        )) {
            $this->skipRender();

            return;
        }

        if ($this->getPage() > 1) {
            $this->hasPendingRefresh = true;
            $this->skipRender();

            return;
        }

        $this->hasPendingRefresh = false;
        $this->forgetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPresentedProperty(): array
    {
        $page = $this->loadPage();

        return app(CustomerTopupPresenter::class)->presentPage($page, auth()->user());
    }

    public function getPaginatorProperty(): LengthAwarePaginator
    {
        $page = $this->loadPage();

        return (new LengthAwarePaginator(
            $page->items,
            $page->total,
            $page->perPage,
            $page->currentPage,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        ))->withQueryString();
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.topups_title'));
    }

    private function normalizeFilters(): void
    {
        $normalized = CustomerTopupFilters::fromInput([
            'filter' => $this->filter,
            'search' => $this->search,
            'page' => $this->getPage(),
        ]);

        $this->filter = $normalized->filter;
        $this->search = $normalized->search;
    }

    private function loadPage(): CustomerTopupPageDTO
    {
        if ($this->pageResult !== null) {
            return $this->pageResult;
        }

        $user = auth()->user();
        abort_unless($user !== null, 403);

        $filters = CustomerTopupFilters::fromInput([
            'filter' => $this->filter,
            'search' => $this->search,
            'page' => $this->getPage(),
        ]);

        $result = app(GetCustomerTopupRequests::class)->handle($user, $filters);

        if ($result->lastPage > 0 && $this->getPage() > $result->lastPage) {
            $this->setPage($result->lastPage);
            $filters = CustomerTopupFilters::fromInput([
                'filter' => $this->filter,
                'search' => $this->search,
                'page' => $this->getPage(),
            ]);
            $result = app(GetCustomerTopupRequests::class)->handle($user, $filters);
        }

        return $this->pageResult = $result;
    }

    private function forgetPage(): void
    {
        $this->pageResult = null;
    }
};
?>

@php
    $presented = $this->presented;
@endphp

<x-storefront.page
    width="work"
    data-test="wallet-topups-page"
    data-financial-surface="topup-list"
    aria-busy="{{ $this->isBusy ? 'true' : 'false' }}"
>
    <div class="storefront-section-stack">
        <x-storefront.page-header
            :title="__('messages.topups_title')"
            :description="__('messages.topups_subtitle')"
            :show-back="true"
            :back-fallback="route('wallet')"
        >
            <x-slot:actions>
                <flux:button
                    as="a"
                    href="{{ route('wallet.topup') }}"
                    wire:navigate
                    variant="primary"
                    size="sm"
                    class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                    data-test="topups-add-funds"
                >
                    {{ __('messages.wallet_add_funds') }}
                </flux:button>
            </x-slot:actions>
        </x-storefront.page-header>

        <x-wallet.financial-centre-nav active="topups" />

        <x-wallet.topups-pending-refresh />

        <x-wallet.topups-filters
            :filter="$filter"
            :search="$search"
            :has-active="$presented['filters']['has_active'] ?? false"
        />

        <section
            class="storefront-card storefront-card--pad-md"
            data-test="topups-list"
            aria-labelledby="topups-heading"
            wire:loading.class="opacity-70"
        >
            <h2 id="topups-heading" class="sr-only">{{ __('messages.topups_region_label') }}</h2>

            @if ($presented['is_empty'])
                <x-wallet.topup-empty :add-funds-href="$presented['add_funds_href']" />
            @elseif ($presented['is_filtered_empty'])
                <x-wallet.topup-empty-filtered :search="$search" />
            @else
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800" role="list">
                    @foreach ($presented['items'] as $item)
                        <x-wallet.topup-row :item="$item" wire:key="{{ $item['stable_key'] }}" />
                    @endforeach
                </ul>

                @if ($presented['pagination']['has_pages'])
                    <div class="mt-4" data-test="topups-pagination">
                        {{ $this->paginator->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-storefront.page>
