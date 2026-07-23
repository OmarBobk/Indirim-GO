<?php

use App\Actions\Orders\GetCustomerOrders;
use App\Actions\Orders\RefundOrderItem;
use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WalletTransaction;
use App\Support\CustomerOrderCardPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;

new #[Layout('layouts::frontend')] class extends Component
{
    use Toastable;
    use WithPagination;

    public int $perPage = 10;

    public string $filter = CustomerOrderCardPresenter::FILTER_ALL;

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function requestRefundForOrder(int $orderId): void
    {
        $userId = auth()->id();
        if ($userId === null) {
            return;
        }

        $order = Order::query()
            ->where('user_id', $userId)
            ->with(['items.fulfillments'])
            ->find($orderId);

        if ($order === null) {
            $this->error(__('messages.refund_not_allowed'));

            return;
        }

        $eligible = $order->items
            ->flatMap(fn (OrderItem $item) => $item->fulfillments)
            ->filter(function ($fulfillment) {
                if ($fulfillment->status !== FulfillmentStatus::Failed) {
                    return false;
                }

                $refundStatus = data_get($fulfillment->meta, 'refund.status');

                return ! in_array($refundStatus, [WalletTransaction::STATUS_PENDING, WalletTransaction::STATUS_POSTED], true);
            })
            ->sortBy('id')
            ->values();

        if ($eligible->isEmpty()) {
            $this->error(__('messages.refund_not_allowed'));

            return;
        }

        $firstError = null;

        foreach ($eligible as $fulfillment) {
            try {
                app(RefundOrderItem::class)->handle($fulfillment, (int) $userId);
            } catch (ValidationException $exception) {
                $firstError ??= collect($exception->errors())->flatten()->first()
                    ?? __('messages.refund_not_allowed');

                break;
            }
        }

        if ($firstError !== null) {
            $this->error($firstError);

            return;
        }

        $this->success(__('messages.refund_waiting_approval'));
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $this->presenter->normalizeFilter($filter);
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function getOrdersProperty(): LengthAwarePaginator
    {
        return app(GetCustomerOrders::class)->handle(
            (int) auth()->id(),
            $this->activeFilter,
            $this->perPage,
            $this->search,
        );
    }

    public function getPresenterProperty(): CustomerOrderCardPresenter
    {
        return CustomerOrderCardPresenter::for(auth()->user());
    }

    public function getActiveFilterProperty(): string
    {
        return $this->presenter->normalizeFilter($this->filter);
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.orders'));
    }
};
?>

<div class="mx-auto w-full max-w-4xl px-3 py-6 sm:px-0 sm:py-10" data-test="orders-page" data-section="orders-page">
    <div class="mb-6 flex items-center">
        <x-back-button />
    </div>

    <section class="space-y-6 sm:space-y-8">
        <x-orders.header />

        <x-orders.summary-strip-placeholder />

        <x-orders.search />

        <x-orders.filter-bar
            :filters="$this->presenter->filterOptions()"
            :active-filter="$this->activeFilter"
        />

        <x-orders.feed
            :orders="$this->orders"
            :presenter="$this->presenter"
            :filter="$this->activeFilter"
            :empty-state="$this->presenter->emptyState($this->activeFilter, $this->search)"
        />
    </section>
</div>
