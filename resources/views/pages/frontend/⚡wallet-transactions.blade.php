<?php

use App\Actions\Financial\GetCustomerWalletTransactions;
use App\DTOs\Financial\WalletTransactionFilters;
use App\DTOs\Financial\WalletTransactionPageDTO;
use App\Support\CustomerWalletTransactionPresenter;
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

    public int $perPage = WalletTransactionFilters::PER_PAGE;

    #[Url(except: 'all')]
    public string $direction = 'all';

    #[Url(except: 'all')]
    public string $type = 'all';

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    public bool $hasPendingRefresh = false;

    public bool $isBusy = false;

    private ?WalletTransactionPageDTO $pageResult = null;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        $this->normalizeFilters();
        $this->loadPage();
    }

    public function updatedDirection(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
        $this->hasPendingRefresh = false;
        $this->forgetPage();
    }

    public function updatedType(): void
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

    public function updatedDateFrom(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
        $this->hasPendingRefresh = false;
        $this->forgetPage();
    }

    public function updatedDateTo(): void
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

    public function setDirection(string $direction): void
    {
        $this->direction = $direction;
        $this->updatedDirection();
    }

    public function setType(string $type): void
    {
        $this->type = $type;
        $this->updatedType();
    }

    public function clearFilters(): void
    {
        $this->direction = 'all';
        $this->type = 'all';
        $this->search = '';
        $this->dateFrom = '';
        $this->dateTo = '';
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
            CustomerFinancialRealtimeScope::SURFACE_LEDGER,
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

        return app(CustomerWalletTransactionPresenter::class)->presentPage($page, auth()->user());
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
        return $this->view()->title(__('messages.financial_ledger_title'));
    }

    private function normalizeFilters(): void
    {
        $normalized = WalletTransactionFilters::fromInput([
            'direction' => $this->direction,
            'type' => $this->type,
            'search' => $this->search,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'page' => $this->getPage(),
        ]);

        $this->direction = $normalized->direction;
        $this->type = $normalized->type;
        $this->search = $normalized->search;
        $this->dateFrom = $normalized->dateFrom ?? '';
        $this->dateTo = $normalized->dateTo ?? '';
    }

    private function loadPage(): WalletTransactionPageDTO
    {
        if ($this->pageResult !== null) {
            return $this->pageResult;
        }

        $user = auth()->user();
        abort_unless($user !== null, 403);

        $filters = WalletTransactionFilters::fromInput([
            'direction' => $this->direction,
            'type' => $this->type,
            'search' => $this->search,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'page' => $this->getPage(),
        ]);

        $result = app(GetCustomerWalletTransactions::class)->handle($user, $filters);

        if ($result->lastPage > 0 && $this->getPage() > $result->lastPage) {
            $this->setPage($result->lastPage);
            $filters = WalletTransactionFilters::fromInput([
                'direction' => $this->direction,
                'type' => $this->type,
                'search' => $this->search,
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
                'page' => $this->getPage(),
            ]);
            $result = app(GetCustomerWalletTransactions::class)->handle($user, $filters);
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
    data-test="wallet-transactions-page"
    data-financial-surface="ledger"
    aria-busy="{{ $this->isBusy ? 'true' : 'false' }}"
>
    <div class="storefront-section-stack" data-test="financial-ledger">
        <x-storefront.page-header
            :title="__('messages.financial_ledger_title')"
            :description="__('messages.financial_ledger_subtitle')"
            :show-back="true"
            :back-fallback="route('wallet')"
        />

        <x-wallet.financial-centre-nav active="transactions" />

        <x-wallet.ledger-pending-refresh />

        <x-wallet.ledger-filters
            :direction="$direction"
            :type="$type"
            :search="$search"
            :date-from="$dateFrom"
            :date-to="$dateTo"
            :show-commission="$presented['filters']['show_commission'] ?? false"
            :has-active="$presented['filters']['has_active'] ?? false"
        />

        <section
            class="storefront-card storefront-card--pad-md"
            aria-labelledby="financial-ledger-heading"
            data-test="financial-ledger-results"
            wire:loading.class="opacity-70"
        >
            <h2 id="financial-ledger-heading" class="sr-only">
                {{ __('messages.financial_ledger_region') }}
            </h2>

            @if ($presented['is_empty'])
                <x-wallet.ledger-empty />
            @elseif ($presented['is_filtered_empty'])
                <x-wallet.ledger-empty-filtered :search="$search" />
            @else
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800" role="list">
                    @foreach ($presented['items'] as $item)
                        <x-wallet.ledger-row :item="$item" wire:key="{{ $item['stable_key'] }}" />
                    @endforeach
                </ul>

                @if ($presented['pagination']['has_pages'])
                    <div class="mt-4" data-test="financial-ledger-pagination">
                        {{ $this->paginator->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-storefront.page>
