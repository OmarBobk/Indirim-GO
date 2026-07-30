<?php

use App\Actions\Refunds\GetCustomerRefunds;
use App\DTOs\Refunds\CustomerRefundFilters;
use App\DTOs\Refunds\CustomerRefundPageDTO;
use App\Support\CustomerRefundPresenter;
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

    public int $perPage = CustomerRefundFilters::PER_PAGE;

    #[Url(except: 'all')]
    public string $filter = 'all';

    #[Url(except: '')]
    public string $search = '';

    public bool $hasPendingRefresh = false;

    public bool $isBusy = false;

    private ?CustomerRefundPageDTO $pageResult = null;

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
            CustomerFinancialRealtimeScope::SURFACE_REFUND_LIST,
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

        return app(CustomerRefundPresenter::class)->presentPage($page, auth()->user());
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
        return $this->view()->title(__('messages.refunds_title'));
    }

    private function normalizeFilters(): void
    {
        $normalized = CustomerRefundFilters::fromInput([
            'filter' => $this->filter,
            'search' => $this->search,
            'page' => $this->getPage(),
        ]);

        $this->filter = $normalized->filter;
        $this->search = $normalized->search;
    }

    private function loadPage(): CustomerRefundPageDTO
    {
        if ($this->pageResult !== null) {
            return $this->pageResult;
        }

        $user = auth()->user();
        abort_unless($user !== null, 403);

        $filters = CustomerRefundFilters::fromInput([
            'filter' => $this->filter,
            'search' => $this->search,
            'page' => $this->getPage(),
        ]);

        $result = app(GetCustomerRefunds::class)->handle($user, $filters);

        if ($result->lastPage > 0 && $this->getPage() > $result->lastPage) {
            $this->setPage($result->lastPage);
            $filters = CustomerRefundFilters::fromInput([
                'filter' => $this->filter,
                'search' => $this->search,
                'page' => $this->getPage(),
            ]);
            $result = app(GetCustomerRefunds::class)->handle($user, $filters);
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
    data-test="wallet-refunds-page"
    data-financial-surface="refund-list"
    aria-busy="{{ $this->isBusy ? 'true' : 'false' }}"
>
    <div class="storefront-section-stack">
        <x-storefront.page-header
            :title="__('messages.refunds_title')"
            :description="__('messages.refunds_subtitle')"
            :show-back="true"
            :back-fallback="route('wallet')"
        >
            <x-slot:actions>
                <flux:button
                    as="a"
                    href="{{ route('orders.index') }}"
                    wire:navigate
                    variant="primary"
                    size="sm"
                    class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                    data-test="refunds-view-orders"
                >
                    {{ __('messages.orders') }}
                </flux:button>
            </x-slot:actions>
        </x-storefront.page-header>

        <x-wallet.financial-centre-nav active="refunds" />

        <x-wallet.refunds-pending-refresh />

        <x-wallet.refunds-filters
            :filter="$filter"
            :search="$search"
            :has-active="$presented['filters']['has_active'] ?? false"
        />

        <section
            class="storefront-card storefront-card--pad-md"
            data-test="refunds-list"
            aria-labelledby="refunds-heading"
            wire:loading.class="opacity-70"
        >
            <h2 id="refunds-heading" class="sr-only">{{ __('messages.refunds_region_label') }}</h2>

            @if ($presented['is_empty'])
                <x-wallet.refund-empty :orders-href="$presented['orders_href']" />
            @elseif ($presented['is_filtered_empty'])
                <x-wallet.refund-empty-filtered :search="$search" />
            @else
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800" role="list">
                    @foreach ($presented['items'] as $item)
                        <x-wallet.refund-row :item="$item" wire:key="{{ $item['stable_key'] }}" />
                    @endforeach
                </ul>

                @if ($presented['pagination']['has_pages'])
                    <div class="mt-4" data-test="refunds-pagination">
                        {{ $this->paginator->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-storefront.page>
