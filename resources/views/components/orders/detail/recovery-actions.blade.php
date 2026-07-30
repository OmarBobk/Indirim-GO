@props([
    /** @var array<string, mixed> $unit */
    'unit',
])

@php
    $canRecover = (bool) ($unit['showRefundAction'] ?? false);
@endphp

<div
    class="space-y-3 rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900 sm:p-4"
    data-section="order-detail-recovery"
    data-test="order-detail-recovery"
>
    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
        {{ __('messages.order_detail_recovery') }}
    </div>

    @if ($canRecover)
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            <flux:button
                variant="primary"
                size="sm"
                class="w-full sm:w-auto"
                wire:click="retryFulfillment({{ $unit['id'] }})"
                wire:loading.attr="disabled"
                wire:target="retryFulfillment({{ $unit['id'] }})"
                data-test="fulfillment-retry"
            >
                {{ __('messages.order_detail_retry_delivery') }}
            </flux:button>

            <flux:button
                variant="filled"
                size="sm"
                class="w-full sm:w-auto"
                wire:click="requestRefund({{ $unit['id'] }})"
                wire:loading.attr="disabled"
                wire:target="requestRefund({{ $unit['id'] }})"
                data-test="fulfillment-request-refund"
            >
                {{ __('messages.refund') }}
            </flux:button>
        </div>
    @else
        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('messages.order_detail_recovery_no_action') }}
        </flux:text>

        @if ($unit['isRefundPending'])
            <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">
                {{ __('messages.refund_waiting_approval') }}
            </flux:text>
            @if (! empty($unit['refundHref']))
                <a href="{{ $unit['refundHref'] }}" wire:navigate class="inline-block text-sm font-medium text-(--color-accent) hover:underline" data-test="order-view-refund">
                    {{ __('messages.refund_view_request') }}
                </a>
            @endif
        @elseif ($unit['isRefundPosted'])
            <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">
                {{ __('messages.refund_completed') }}
            </flux:text>
            @if (! empty($unit['refundHref']))
                <a href="{{ $unit['refundHref'] }}" wire:navigate class="inline-block text-sm font-medium text-(--color-accent) hover:underline" data-test="order-view-refund">
                    {{ __('messages.refund_view_request') }}
                </a>
            @endif
        @elseif ($unit['isRefundRejected'])
            <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">
                {{ __('messages.refund_rejected') }}
            </flux:text>
            @if (! empty($unit['refundHref']))
                <a href="{{ $unit['refundHref'] }}" wire:navigate class="inline-block text-sm font-medium text-(--color-accent) hover:underline" data-test="order-view-refund">
                    {{ __('messages.refund_view_request') }}
                </a>
            @endif
        @endif
    @endif
</div>
