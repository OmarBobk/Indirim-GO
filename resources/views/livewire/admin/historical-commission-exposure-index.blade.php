<div class="space-y-6" data-test="admin-historical-exposure">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="lg">{{ __('messages.historical_exposure_title') }}</flux:heading>
            <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('messages.historical_exposure_intro') }}
            </flux:text>
            <flux:callout class="mt-3" variant="warning">
                {{ __('messages.historical_exposure_no_money_warning') }}
            </flux:callout>
        </div>
        <flux:button variant="ghost" :href="$inboxHref" wire:navigate>
            {{ __('messages.clawback_return_inbox') }}
        </flux:button>
    </div>

    <nav class="flex flex-wrap gap-2 text-sm" aria-label="{{ __('messages.historical_exposure_nav_label') }}">
        <a
            href="{{ route('admin.commission-clawbacks.index') }}"
            wire:navigate
            class="rounded-lg px-3 py-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
        >
            {{ __('messages.commission_clawbacks') }}
        </a>
        <span class="rounded-lg bg-zinc-100 px-3 py-1.5 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100" aria-current="page">
            {{ __('messages.historical_exposure_title') }}
        </span>
    </nav>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
            <p class="text-xs text-zinc-500">{{ __('messages.historical_exposure_summary_confirmed') }}</p>
            <p class="mt-1 text-lg font-semibold tabular-nums" dir="ltr">{{ $summary['confirmed_unreviewed_count'] }}</p>
            <p class="text-xs tabular-nums text-zinc-500" dir="ltr">{{ $summary['confirmed_exposure_total'] }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
            <p class="text-xs text-zinc-500">{{ __('messages.historical_exposure_summary_incomplete') }}</p>
            <p class="mt-1 text-lg font-semibold tabular-nums" dir="ltr">{{ $summary['incomplete_unreviewed_count'] }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
            <p class="text-xs text-zinc-500">{{ __('messages.historical_exposure_summary_reviewed') }}</p>
            <p class="mt-1 text-lg font-semibold tabular-nums" dir="ltr">{{ $summary['reviewed_count'] }}</p>
        </div>
    </div>

    <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
        <div class="sm:w-48">
            <flux:select wire:model.live="filter" :label="__('messages.clawback_filter_label')">
                <flux:select.option value="unreviewed">{{ __('messages.historical_exposure_filter_unreviewed') }}</flux:select.option>
                <flux:select.option value="reviewed">{{ __('messages.historical_exposure_filter_reviewed') }}</flux:select.option>
                <flux:select.option value="confirmed">{{ __('messages.historical_exposure_filter_confirmed') }}</flux:select.option>
                <flux:select.option value="incomplete">{{ __('messages.historical_exposure_filter_incomplete') }}</flux:select.option>
                <flux:select.option value="all">{{ __('messages.clawback_filter_all') }}</flux:select.option>
            </flux:select>
        </div>
        <div class="grow">
            <flux:input
                wire:model.live.debounce.400ms="search"
                :label="__('messages.clawback_search_label')"
                :placeholder="__('messages.historical_exposure_search_placeholder')"
            />
        </div>
        <div class="sm:w-40">
            <flux:input wire:model.live="refundFrom" type="date" :label="__('messages.historical_exposure_refund_from')" dir="ltr" />
        </div>
        <div class="sm:w-40">
            <flux:input wire:model.live="refundTo" type="date" :label="__('messages.historical_exposure_refund_to')" dir="ltr" />
        </div>
    </div>

    @if ($showReviewForm)
        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="historical-exposure-review-form" aria-labelledby="historical-review-heading">
            <h2 id="historical-review-heading" class="text-sm font-semibold">{{ __('messages.historical_exposure_review_action') }}</h2>
            <flux:text class="mt-1 text-sm">{{ __('messages.historical_exposure_review_intro') }}</flux:text>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <flux:select wire:model="reviewOutcome" :label="__('messages.historical_exposure_outcome')">
                    <flux:select.option value="">{{ __('messages.historical_exposure_outcome_placeholder') }}</flux:select.option>
                    @foreach ($outcomeOptions as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="reviewReason" :label="__('messages.historical_exposure_reason')">
                    <flux:select.option value="">{{ __('messages.historical_exposure_reason_placeholder') }}</flux:select.option>
                    @foreach ($reasonOptions as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="sm:col-span-2">
                    <flux:textarea wire:model="reviewNote" :label="__('messages.historical_exposure_admin_note')" rows="3" />
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button
                    variant="primary"
                    wire:click="submitReview"
                    wire:confirm="{{ __('messages.historical_exposure_review_confirm') }}"
                    data-test="historical-exposure-review-submit"
                >
                    {{ __('messages.historical_exposure_review_submit') }}
                </flux:button>
                <flux:button variant="ghost" wire:click="$set('showReviewForm', false)">
                    {{ __('messages.cancel') }}
                </flux:button>
            </div>
        </section>
    @endif

    @if ($rows === [])
        <flux:callout>{{ __('messages.historical_exposure_empty') }}</flux:callout>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-900/40">
                    <tr>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.clawback_col_salesperson') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('messages.historical_exposure_amount') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.clawback_col_order') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.historical_exposure_credit_wtx') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.historical_exposure_refund_wtx') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.historical_exposure_dates') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.historical_exposure_confidence') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('messages.historical_exposure_review_state') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('messages.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($rows as $row)
                        <tr wire:key="hist-{{ $row['commission_id'] }}-{{ $row['refund_wallet_transaction_id'] }}" data-test="historical-exposure-row">
                            <td class="px-3 py-2">{{ $row['salesperson_name'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-end tabular-nums" dir="ltr">{{ $row['exposure_amount'] }} {{ $row['currency'] }}</td>
                            <td class="px-3 py-2 font-mono" dir="ltr">{{ $row['order_number'] ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $row['credit_public_ref'] ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $row['refund_public_ref'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">
                                <div dir="ltr">{{ $row['credited_at_display'] ?? '—' }}</div>
                                <div class="text-zinc-500" dir="ltr">{{ $row['refunded_at_display'] ?? '—' }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <span>{{ $row['confidence_label'] }}</span>
                                <span class="sr-only">{{ $row['confidence'] }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <div>{{ $row['review_state_label'] }}</div>
                                @if ($row['review_outcome_label'])
                                    <div class="text-xs text-zinc-500">{{ $row['review_outcome_label'] }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-end">
                                <flux:button
                                    size="sm"
                                    variant="filled"
                                    wire:click="openReview({{ $row['commission_id'] }}, {{ $row['refund_wallet_transaction_id'] }})"
                                    data-test="historical-exposure-review-open"
                                >
                                    {{ __('messages.historical_exposure_review_action') }}
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($pagination['last_page'] > 1)
            <div class="flex items-center justify-between text-sm">
                <flux:button size="sm" variant="ghost" wire:click="previousPage" :disabled="$pagination['current_page'] <= 1">
                    {{ __('messages.previous') }}
                </flux:button>
                <span class="tabular-nums" dir="ltr">{{ $pagination['current_page'] }} / {{ $pagination['last_page'] }}</span>
                <flux:button size="sm" variant="ghost" wire:click="nextPage" :disabled="$pagination['current_page'] >= $pagination['last_page']">
                    {{ __('messages.next') }}
                </flux:button>
            </div>
        @endif
    @endif
</div>
