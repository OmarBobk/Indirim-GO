<?php

use App\Actions\Refunds\ApproveRefundRequest;
use App\Actions\Refunds\DismissStaleRefundRequest;
use App\Actions\Refunds\GetRefundRequests;
use App\Actions\Refunds\RejectRefundRequest;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\Fulfillment;
use App\Models\OrderItem;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;

new class extends Component
{
    use Toastable;
    use WithPagination;

    public int $perPage = 10;

    public ?string $noticeMessage = null;
    public ?string $noticeVariant = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('view_refunds'), 403);
    }

    public function approveRefund(int $transactionId): void
    {
        abort_unless(auth()->user()?->can('process_refunds'), 403);
        $this->reset('noticeMessage', 'noticeVariant');

        app(ApproveRefundRequest::class)->handle(auth()->user(), $transactionId);

        $this->noticeVariant = 'success';
        $this->noticeMessage = __('messages.refund_approved');
        $this->success(__('messages.refund_approved'));
    }

    public function rejectRefund(int $transactionId): void
    {
        abort_unless(auth()->user()?->can('process_refunds'), 403);
        $this->reset('noticeMessage', 'noticeVariant');

        app(RejectRefundRequest::class)->handle($transactionId, auth()->id());

        $this->noticeVariant = 'danger';
        $this->noticeMessage = __('messages.refund_rejected');
        $this->error(__('messages.refund_rejected'));
    }

    public function dismissStaleRefund(int $transactionId): void
    {
        abort_unless(auth()->user()?->can('process_refunds'), 403);
        $this->reset('noticeMessage', 'noticeVariant');

        app(DismissStaleRefundRequest::class)->handle($transactionId, auth()->id());

        $this->noticeVariant = 'success';
        $this->noticeMessage = __('messages.refund_dismissed_stale');
        $this->success(__('messages.refund_dismissed_stale'));
    }

    public function getRefundRequestsProperty(): LengthAwarePaginator
    {
        return app(GetRefundRequests::class)->handle($this->perPage);
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.refund_requests'));
    }
};
?>

<div class="admin-fulfillments flex h-full w-full flex-1 flex-col gap-8">
    <section class="cf-reveal rounded-2xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="space-y-2">
                <p class="cf-display text-xs font-semibold tracking-[0.2em] text-[var(--cf-primary)] uppercase">
                    {{ __('messages.nav_financials') }}
                </p>
                <flux:heading size="lg" class="cf-display tracking-tight text-[var(--cf-foreground)]">
                    {{ __('messages.refund_requests') }}
                </flux:heading>
                <flux:text class="text-sm text-[var(--cf-muted-foreground)]">
                    {{ __('messages.refund_requests_intro') }}
                </flux:text>
            </div>
        </div>

        @if ($noticeMessage)
            <div class="mt-4">
                <flux:callout
                    variant="subtle"
                    icon="{{ $noticeVariant === 'success' ? 'check-circle' : 'exclamation-triangle' }}"
                >
                    {{ $noticeMessage }}
                </flux:callout>
            </div>
        @endif

        <div class="cf-table-shell mt-4">
            <div class="overflow-x-auto">
                @if ($this->refundRequests->isEmpty())
                    <div class="flex flex-col items-center justify-center gap-2 px-6 py-16 text-center">
                        <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                            {{ __('messages.no_refund_requests') }}
                        </flux:heading>
                        <flux:text class="text-[var(--cf-muted-foreground)]">
                            {{ __('messages.no_refund_requests_hint') }}
                        </flux:text>
                    </div>
                @else
                    <table class="min-w-full divide-y divide-[var(--cf-border)] text-sm" data-test="refunds-table">
                        <thead class="cf-table-head text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                            <tr>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.created') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.user') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.order_details') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.amount') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.failure_reason') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.note') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.status') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('messages.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--cf-border)]">
                            @foreach ($this->refundRequests as $transaction)
                                @php
                                    $fulfillment = $transaction->reference instanceof Fulfillment ? $transaction->reference : null;
                                    $orderItem = $fulfillment?->orderItem
                                        ?? ($transaction->reference instanceof OrderItem ? $transaction->reference : null);
                                    $order = $fulfillment?->order ?? $orderItem?->order;
                                    $user = $order?->user;
                                    $metaFulfillmentId = (int) data_get($transaction->meta, 'fulfillment_id', 0);
                                    $legacyFulfillment = $metaFulfillmentId > 0
                                        ? $orderItem?->fulfillments?->firstWhere('id', $metaFulfillmentId)
                                        : $orderItem?->fulfillments?->first();
                                    $displayFulfillment = $fulfillment ?? $legacyFulfillment;
                                    $fulfillmentIdForLink = $displayFulfillment?->id
                                        ?? ($metaFulfillmentId > 0 ? $metaFulfillmentId : null);
                                    $note = data_get($transaction->meta, 'note');
                                    $isPendingRefund = $transaction->status === WalletTransaction::STATUS_PENDING;
                                    $isRefunded = $order?->status === OrderStatus::Refunded;
                                    $fulfillmentFailed = $displayFulfillment?->status === FulfillmentStatus::Failed;
                                    $isStaleRefund = $isPendingRefund && $displayFulfillment !== null && ! $fulfillmentFailed;
                                    $canApprove = $isPendingRefund && $fulfillmentFailed && ! $isRefunded;
                                    $statusColor = match ($transaction->status) {
                                        WalletTransaction::STATUS_POSTED => 'green',
                                        WalletTransaction::STATUS_REJECTED => 'red',
                                        default => 'amber',
                                    };
                                @endphp
                                <tr class="transition-colors duration-200 hover:bg-[var(--cf-card-elevated)]" wire:key="refund-{{ $transaction->id }}">
                                    <td class="px-5 py-4 text-[var(--cf-muted-foreground)]">
                                        {{ $transaction->created_at?->format('M d, Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="truncate font-semibold text-[var(--cf-foreground)]">
                                            {{ $user?->name ?? __('messages.unknown_user') }}
                                        </div>
                                        <div class="truncate text-xs text-[var(--cf-muted-foreground)]">
                                            {{ $user?->email ?? '—' }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-[var(--cf-muted-foreground)]">
                                        <div class="font-semibold text-[var(--cf-foreground)]">
                                            {{ $order?->order_number ?? __('messages.no_details') }}
                                        </div>
                                        <div class="text-xs text-[var(--cf-muted-foreground)]">
                                            #{{ $orderItem?->id ?? '—' }} / #{{ $displayFulfillment?->id ?? data_get($transaction->meta, 'fulfillment_id', '—') }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-[var(--cf-foreground)]" dir="ltr">
                                        {{ config('billing.currency_symbol', '$') }}{{ number_format((float) $transaction->amount, 2) }}
                                    </td>
                                    <td class="px-5 py-4 text-[var(--cf-muted-foreground)]">
                                        {{ $displayFulfillment?->last_error ?? '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-[var(--cf-muted-foreground)]">
                                        {{ $note ?: '—' }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <flux:badge color="{{ $statusColor }}">
                                            {{ __('messages.'.$transaction->status) }}
                                        </flux:badge>
                                    </td>
                                    <td class="px-5 py-4 text-end">
                                        <div class="flex flex-wrap items-center justify-end gap-2">
                                            @if ($fulfillmentIdForLink !== null)
                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    class="text-[var(--cf-muted-foreground)] hover:bg-[var(--cf-card-elevated)] hover:text-[var(--cf-foreground)]"
                                                    :href="route('fulfillments', ['fulfillment' => $fulfillmentIdForLink])"
                                                    wire:navigate
                                                    data-test="refund-view-fulfillment"
                                                >
                                                    {{ __('messages.view_fulfillment') }}
                                                </flux:button>
                                            @endif
                                            @can('process_refunds')
                                                @if ($isPendingRefund)
                                                    @if ($isStaleRefund)
                                                        <flux:callout variant="subtle" icon="information-circle" class="text-start">
                                                            {{ __('messages.refund_stale_hint') }}
                                                        </flux:callout>
                                                        <flux:button
                                                            size="sm"
                                                            variant="ghost"
                                                            wire:click="dismissStaleRefund({{ $transaction->id }})"
                                                            data-test="dismiss-stale-refund-{{ $transaction->id }}"
                                                        >
                                                            {{ __('messages.refund_dismiss_stale') }}
                                                        </flux:button>
                                                    @else
                                                        @if ($canApprove)
                                                            <flux:button
                                                                size="sm"
                                                                variant="primary"
                                                                class="!bg-[var(--cf-primary)] !text-[var(--cf-primary-foreground)] transition-colors duration-200 hover:brightness-110"
                                                                wire:click="approveRefund({{ $transaction->id }})"
                                                            >
                                                                {{ __('messages.approve') }}
                                                            </flux:button>
                                                        @endif
                                                        <flux:button
                                                            size="sm"
                                                            variant="danger"
                                                            wire:click="rejectRefund({{ $transaction->id }})"
                                                        >
                                                            {{ __('messages.reject') }}
                                                        </flux:button>
                                                    @endif
                                                @endif
                                            @elseif ($fulfillmentIdForLink === null)
                                                <span class="text-[var(--cf-muted-foreground)]">—</span>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="cf-pagination border-t border-[var(--cf-border)] px-5 py-4">
                {{ $this->refundRequests->links() }}
            </div>
        </div>
    </section>
</div>
