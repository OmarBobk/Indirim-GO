<?php

use App\Actions\Fulfillments\RetryFulfillment;
use App\Actions\Orders\GetCustomerOrderDetail;
use App\Actions\Orders\RefundOrderItem;
use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Support\CustomerOrderDetailPresenter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::frontend')] class extends Component
{
    public Order $order;

    public ?string $actionMessage = null;

    public function mount(Order $order): void
    {
        $this->order = $this->loadDetail($order);
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.order_details'));
    }

    public function retryFulfillment(int $fulfillmentId): void
    {
        $this->reset('actionMessage');

        $fulfillment = $this->order->items
            ->flatMap(fn ($item) => $item->fulfillments)
            ->firstWhere('id', $fulfillmentId);

        if ($fulfillment === null) {
            $this->actionMessage = __('messages.retry_not_allowed');

            return;
        }

        app(RetryFulfillment::class)->handle($fulfillment, 'customer', auth()->id());

        $fulfillment->refresh();
        $this->order = $this->loadDetail($this->order);
        $this->actionMessage = $fulfillment->status === FulfillmentStatus::Queued
            ? __('messages.fulfillment_marked_queued')
            : __('messages.retry_not_allowed');
    }

    public function requestRefund(int $fulfillmentId): void
    {
        $this->reset('actionMessage');

        $fulfillment = $this->order->items
            ->flatMap(fn ($item) => $item->fulfillments)
            ->firstWhere('id', $fulfillmentId);

        if ($fulfillment === null) {
            $this->actionMessage = __('messages.refund_not_allowed');

            return;
        }

        try {
            app(RefundOrderItem::class)->handle($fulfillment, auth()->id());
        } catch (ValidationException $exception) {
            $this->actionMessage = collect($exception->errors())->flatten()->first()
                ?? __('messages.refund_not_allowed');

            return;
        }

        $this->order = $this->loadDetail($this->order);
        $this->actionMessage = __('messages.refund_waiting_approval');
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewModelProperty(): array
    {
        return CustomerOrderDetailPresenter::for(auth()->user())->present($this->order);
    }

    private function loadDetail(Order $order): Order
    {
        return app(GetCustomerOrderDetail::class)->handle($order, (int) auth()->id());
    }
};
?>

@php($view = $this->viewModel)

<x-orders.detail.workspace :view="$view" />
