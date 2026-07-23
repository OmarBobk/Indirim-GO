@props([
    /** @var array<string, mixed> $unit */
    'unit',
])

<div class="flex flex-wrap items-center gap-2">
    <flux:badge color="{{ $unit['status']['color'] }}">
        {{ $unit['status']['label'] }}
    </flux:badge>
    @if ($unit['isRefundPending'])
        <flux:badge color="amber">{{ __('messages.refund_requested') }}</flux:badge>
    @elseif ($unit['isRefundPosted'])
        <flux:badge color="green">{{ __('messages.refunded') }}</flux:badge>
    @elseif ($unit['isRefundRejected'])
        <flux:badge color="red">{{ __('messages.refund_rejected') }}</flux:badge>
    @endif
    @if ($unit['showRetryRequestedBadge'])
        <flux:badge color="blue">{{ __('messages.retry_requested') }}</flux:badge>
    @endif
</div>
