<div class="space-y-6" data-test="admin-clawbacks-inbox">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="lg">{{ __('messages.commission_clawbacks') }}</flux:heading>
            <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('messages.commission_clawbacks_intro') }}
            </flux:text>
        </div>
    </div>

    <nav class="flex flex-wrap gap-2 text-sm" aria-label="{{ __('messages.commission_clawbacks') }}">
        <span class="rounded-lg bg-zinc-100 px-3 py-1.5 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100" aria-current="page">
            {{ __('messages.commission_clawbacks') }}
        </span>
        @can('view_historical_commission_exposure')
            <a
                href="{{ route('admin.commission-clawbacks.historical-exposure') }}"
                wire:navigate
                class="rounded-lg px-3 py-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                data-test="historical-exposure-nav"
            >
                {{ __('messages.historical_exposure_title') }}
            </a>
        @endcan
    </nav>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="sm:w-56">
            <flux:select wire:model.live="filter" :label="__('messages.clawback_filter_label')">
                <flux:select.option value="all">{{ __('messages.clawback_filter_all') }}</flux:select.option>
                <flux:select.option value="needs_review">{{ __('messages.clawback_filter_needs_review') }}</flux:select.option>
                <flux:select.option value="retryable">{{ __('messages.clawback_filter_retryable') }}</flux:select.option>
                <flux:select.option value="stale_processing">{{ __('messages.clawback_filter_stale') }}</flux:select.option>
                <flux:select.option value="pending">{{ __('messages.clawback_status_pending') }}</flux:select.option>
                <flux:select.option value="processing">{{ __('messages.clawback_status_processing') }}</flux:select.option>
                <flux:select.option value="posted">{{ __('messages.clawback_status_posted') }}</flux:select.option>
                <flux:select.option value="waived">{{ __('messages.clawback_status_waived') }}</flux:select.option>
                <flux:select.option value="partially_waived">{{ __('messages.clawback_filter_partially_waived') }}</flux:select.option>
                <flux:select.option value="disputed">{{ __('messages.clawback_filter_disputed') }}</flux:select.option>
                <flux:select.option value="correction_available">{{ __('messages.clawback_filter_correction_available') }}</flux:select.option>
                <flux:select.option value="partially_corrected">{{ __('messages.clawback_filter_partially_corrected') }}</flux:select.option>
                <flux:select.option value="fully_corrected">{{ __('messages.clawback_filter_fully_corrected') }}</flux:select.option>
                <flux:select.option value="net_collected_zero">{{ __('messages.clawback_filter_net_collected_zero') }}</flux:select.option>
                <flux:select.option value="failed">{{ __('messages.clawback_status_failed') }}</flux:select.option>
                <flux:select.option value="debt_outstanding">{{ __('messages.clawback_filter_debt_outstanding') }}</flux:select.option>
                <flux:select.option value="debt_recovered">{{ __('messages.clawback_filter_debt_recovered') }}</flux:select.option>
            </flux:select>
        </div>
        <div class="grow">
            <flux:input
                wire:model.live.debounce.400ms="search"
                :label="__('messages.clawback_search_label')"
                :placeholder="__('messages.clawback_search_placeholder')"
            />
        </div>
    </div>

    @if ($rows === [])
        <flux:callout>
            {{ __('messages.clawback_inbox_empty') }}
        </flux:callout>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-900/40">
                    <tr>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.clawback_col_ref') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.clawback_col_salesperson') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('messages.clawback_col_amount') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.clawback_col_status') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.clawback_col_flags') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.clawback_col_order') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.clawback_col_created') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('messages.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($rows as $row)
                        <tr wire:key="clawback-{{ $row['public_ref'] }}" data-test="admin-clawback-row" class="{{ ! empty($row['is_action_required']) ? 'bg-amber-50/40 dark:bg-amber-950/20' : '' }}">
                            <td class="px-3 py-2 font-mono" dir="ltr">
                                <a href="{{ $row['href'] }}" wire:navigate class="text-(--color-accent) hover:underline">{{ $row['public_ref'] }}</a>
                            </td>
                            <td class="px-3 py-2">
                                <div>{{ $row['salesperson_name'] ?? '—' }}</div>
                                <div class="text-xs text-zinc-500" dir="ltr">{{ $row['salesperson_email'] ?? '' }}</div>
                            </td>
                            <td class="px-3 py-2 text-end tabular-nums" dir="ltr">{{ $row['amount'] }} {{ $row['currency'] }}</td>
                            <td class="px-3 py-2">
                                <span>{{ $row['status_label'] }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1">
                                    @if ($row['is_action_required'])
                                        <flux:badge color="amber" size="sm">{{ __('messages.clawback_badge_action_required') }}</flux:badge>
                                    @endif
                                    @if ($row['is_retryable'])
                                        <flux:badge color="blue" size="sm">{{ __('messages.clawback_badge_retryable') }}</flux:badge>
                                    @endif
                                    @if ($row['is_stale'])
                                        <flux:badge color="rose" size="sm">{{ __('messages.clawback_badge_stale') }}</flux:badge>
                                    @endif
                                    @if ($row['has_outstanding_debt'])
                                        <flux:badge color="red" size="sm">{{ __('messages.clawback_badge_debt') }}</flux:badge>
                                    @endif
                                    @if ($row['status'] === 'waived')
                                        <flux:badge color="zinc" size="sm">{{ __('messages.clawback_status_waived') }}</flux:badge>
                                    @elseif ($row['is_partially_waived'])
                                        <flux:badge color="zinc" size="sm">{{ __('messages.clawback_filter_partially_waived') }}</flux:badge>
                                    @endif
                                    @if ($row['is_disputed'])
                                        <flux:badge color="purple" size="sm">{{ __('messages.clawback_badge_disputed') }}</flux:badge>
                                    @endif
                                    @if ($row['is_partially_corrected'])
                                        <flux:badge color="indigo" size="sm">{{ __('messages.clawback_badge_partially_corrected') }}</flux:badge>
                                    @elseif ($row['is_fully_corrected'])
                                        <flux:badge color="indigo" size="sm">{{ __('messages.clawback_badge_fully_corrected') }}</flux:badge>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-2 font-mono" dir="ltr">{{ $row['order_number'] ?? '—' }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $row['created_at_display'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-end">
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" :href="$row['href']" wire:navigate>
                                        {{ __('messages.details') }}
                                    </flux:button>
                                    @if ($canProcess && $row['is_retryable'])
                                        <flux:button
                                            size="sm"
                                            variant="primary"
                                            wire:click="retry('{{ $row['public_ref'] }}')"
                                            wire:confirm="{{ __('messages.clawback_retry_confirm') }}"
                                            data-test="admin-clawback-retry"
                                        >
                                            {{ __('messages.clawback_retry_action') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($lastPage > 1)
            <div class="flex items-center justify-between text-sm">
                <span>{{ __('messages.showing_page', ['current' => $currentPage, 'last' => $lastPage, 'total' => $total]) }}</span>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="previousPage" :disabled="$currentPage <= 1">{{ __('messages.previous') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="nextPage" :disabled="$currentPage >= $lastPage">{{ __('messages.next') }}</flux:button>
                </div>
            </div>
        @endif
    @endif
</div>
