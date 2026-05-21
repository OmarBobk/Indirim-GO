<div class="flex h-full w-full flex-1 flex-col gap-6">
    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="space-y-1">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.payout_requests') }}
                </flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('messages.payout_requests_intro') }}
                </flux:text>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <flux:button
                    size="sm"
                    :variant="$statusFilter === 'pending' ? 'primary' : 'ghost'"
                    wire:click="$set('statusFilter', 'pending')"
                >
                    {{ __('messages.payout_requests_filter_pending') }}
                </flux:button>
                <flux:button
                    size="sm"
                    :variant="$statusFilter === 'all' ? 'primary' : 'ghost'"
                    wire:click="$set('statusFilter', 'all')"
                >
                    {{ __('messages.payout_requests_filter_all') }}
                </flux:button>
            </div>
        </div>

        <div class="mt-6 overflow-hidden rounded-2xl border border-zinc-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                @if ($requests->isEmpty())
                    <div class="flex flex-col items-center justify-center gap-2 px-6 py-16 text-center">
                        <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                            {{ __('messages.payout_requests_empty') }}
                        </flux:heading>
                    </div>
                @else
                    <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3 text-start font-semibold">#</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.payout_requests_col_salesperson') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.payout_requests_col_eligible') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.payout_requests_col_status') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.payout_requests_col_requested') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.payout_requests_col_processed') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('messages.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($requests as $row)
                                <tr class="transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60" wire:key="payout-request-{{ $row->id }}">
                                    <td class="num px-5 py-4 tabular-nums text-zinc-600 dark:text-zinc-400">{{ $row->id }}</td>
                                    <td class="px-5 py-4">
                                        <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $row->user?->name ?? '—' }}</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $row->user?->email }}</div>
                                    </td>
                                    <td class="num px-5 py-4 font-medium tabular-nums text-zinc-900 dark:text-zinc-100">
                                        @if (strtoupper((string) $row->currency) === 'USD')
                                            ${{ number_format((float) $row->eligible_amount, 2) }}
                                        @else
                                            {{ number_format((float) $row->eligible_amount, 2) }} {{ $row->currency }}
                                        @endif
                                    </td>
                                    <td class="px-5 py-4">
                                        @if ($row->status === \App\Enums\PayoutRequestStatus::Pending)
                                            <flux:badge color="amber" size="sm">{{ __('messages.payout_requests_status_pending') }}</flux:badge>
                                        @else
                                            <flux:badge color="gray" size="sm">{{ __('messages.payout_requests_status_processed') }}</flux:badge>
                                        @endif
                                    </td>
                                    <td class="num px-5 py-4 text-zinc-600 tabular-nums dark:text-zinc-400">
                                        {{ $row->created_at?->format('Y-m-d H:i') ?? '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-400">
                                        @if ($row->processed_at)
                                            <div class="text-sm">{{ $row->processed_at->format('Y-m-d H:i') }}</div>
                                            <div class="text-xs text-zinc-500">{{ $row->processedByUser?->name }}</div>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-end">
                                        @if ($row->status === \App\Enums\PayoutRequestStatus::Pending)
                                            <flux:button
                                                size="sm"
                                                variant="primary"
                                                wire:click="markProcessed({{ $row->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="markProcessed"
                                            >
                                                {{ __('messages.payout_requests_mark_processed') }}
                                            </flux:button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
            @if ($requests->hasPages())
                <div class="border-t border-zinc-100 px-4 py-3 dark:border-zinc-800">
                    {{ $requests->links() }}
                </div>
            @endif
        </div>
    </section>
</div>
