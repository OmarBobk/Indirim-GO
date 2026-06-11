@props([
    'run',
    'globalLatestUuid' => null,
    'isChild' => false,
    'showAttemptLabel' => false,
    'expandGroupId' => null,
])

@php
    $isLatest = $this->isGlobalLatestRun($run, $globalLatestUuid);
@endphp

<tr
    wire:key="automation-run-{{ $run->id }}"
    wire:click="selectRun('{{ $run->uuid }}')"
    @if ($expandGroupId !== null)
        x-show="isGroupExpanded({{ $expandGroupId }})"
        x-cloak
    @endif
    @class([
        'cursor-pointer transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60',
        'border-s-2 border-s-blue-500 bg-blue-50/30 dark:bg-blue-950/20' => $selectedRunUuid === $run->uuid,
        'bg-zinc-50/50 text-zinc-600 dark:bg-zinc-800/30 dark:text-zinc-400' => $isChild,
    ])
>
    <td class="px-4 py-3 font-mono text-xs tabular-nums text-zinc-600 dark:text-zinc-400">
        {{ $run->id }}
    </td>
    <td class="px-4 py-3 @if ($isChild) ps-8 @endif">
        <div class="flex flex-wrap items-center gap-2">
            <span @class(['inline-flex items-center justify-center gap-1 rounded-full border px-2 py-0.5 text-center text-xs font-semibold', $this->statusBadgeClass($run->status)])>
                {{ __('messages.automation_status_'.$run->status->value) }}
            </span>
            @if ($showAttemptLabel)
                <span class="text-[11px] text-zinc-500 dark:text-zinc-400">
                    {{ __('messages.automation_attempt_label', ['n' => $run->attempt]) }}
                </span>
            @endif
        </div>
    </td>
    <td class="px-4 py-3" wire:click.stop>
        @if ($run->fulfillment?->order)
            <a
                href="{{ route('admin.orders.show', $run->fulfillment->order) }}"
                target="_blank"
                rel="noopener noreferrer"
                class="font-medium text-cyan-700 hover:underline dark:text-cyan-300"
            >
                {{ $run->fulfillment->order->order_number }}
            </a>
        @else
            <span class="text-zinc-400">—</span>
        @endif
    </td>
    <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">
        {{ $run->fulfillment?->order?->user?->username ?? '—' }}
    </td>
    <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400 whitespace-nowrap">
        {{ $this->orderDateLabel($run) }}
    </td>
    <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-400">
        {{ $this->runDurationLabel($run) }}
    </td>
    <td class="px-4 py-3 align-top" wire:click.stop>
        @php($logSummary = $this->runLogSummary($run))
        @php($logLines = $this->formattedLogExcerpt($run))
        @php($hasExpandable = $this->runHasExpandableDetails($run))

        @if ($logSummary === null && ! $hasExpandable)
            <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ __('messages.automation_no_run_details') }}</span>
        @else
            <div x-data="{ detailsOpen: false }" class="max-w-xs space-y-1.5 sm:max-w-md lg:max-w-lg">
                @if ($logSummary !== null)
                    <p class="font-mono text-[11px] leading-snug text-zinc-700 dark:text-zinc-300" title="{{ $logSummary }}">
                        {{ $logSummary }}
                    </p>
                @endif

                @if ($hasExpandable)
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-[11px] font-semibold text-cyan-700 hover:text-cyan-900 dark:text-cyan-300 dark:hover:text-cyan-200"
                        x-on:click.stop="detailsOpen = ! detailsOpen"
                        x-bind:aria-expanded="detailsOpen"
                    >
                        <flux:icon
                            icon="chevron-down"
                            class="size-3.5 shrink-0 transition-transform duration-150"
                            x-bind:class="detailsOpen ? 'rotate-180' : ''"
                        />
                        <span x-text="detailsOpen ? @js(__('messages.automation_toggle_details_hide')) : @js(__('messages.automation_toggle_details'))"></span>
                    </button>

                    <div
                        x-show="detailsOpen"
                        x-cloak
                        class="rounded-lg border border-zinc-200 bg-zinc-50/90 p-2 dark:border-zinc-700 dark:bg-zinc-800/80"
                    >
                        @php($purchaseDetails = $this->runPurchaseDetails($run))
                        @if ($purchaseDetails !== null)
                            <dl class="grid grid-cols-1 gap-1.5 font-mono text-[10px] text-zinc-700 dark:text-zinc-300">
                                <div class="flex flex-wrap gap-x-2">
                                    <dt class="font-semibold text-zinc-500 dark:text-zinc-400">{{ __('messages.automation_purchase_order_id') }}:</dt>
                                    <dd>{{ $purchaseDetails['order'] }}</dd>
                                </div>
                                <div class="flex flex-wrap gap-x-2">
                                    <dt class="font-semibold text-zinc-500 dark:text-zinc-400">{{ __('messages.automation_purchase_status') }}:</dt>
                                    <dd>{{ $purchaseDetails['status'] }}</dd>
                                </div>
                                <div class="flex flex-wrap gap-x-2">
                                    <dt class="font-semibold text-zinc-500 dark:text-zinc-400">{{ __('messages.automation_purchase_price') }}:</dt>
                                    <dd>{{ $purchaseDetails['price'] }}</dd>
                                </div>
                            </dl>
                        @endif

                        @if (filled($run->error_code) || filled($run->error_message))
                            <p @class([
                                'font-mono text-[10px] leading-snug text-red-700 dark:text-red-300',
                                'mt-2 border-t border-zinc-200 pt-2 dark:border-zinc-600' => $purchaseDetails !== null,
                            ])>
                                <span class="font-semibold">{{ $run->error_code }}</span>
                                @if (filled($run->error_message))
                                    <span class="text-zinc-500 dark:text-zinc-400"> · </span>
                                    <span>{{ $run->error_message }}</span>
                                @endif
                            </p>
                        @endif

                        @if ($logLines !== [])
                            <ul @class([
                                'space-y-1.5',
                                'mt-2 border-t border-zinc-200 pt-2 dark:border-zinc-600' => $purchaseDetails !== null || filled($run->error_code),
                            ])>
                                @foreach ($logLines as $line)
                                    <li @class([
                                        'font-mono text-[10px] leading-snug',
                                        'font-semibold text-emerald-800 dark:text-emerald-300' => $line['step'] === 'purchase_parsed',
                                        'text-zinc-600 dark:text-zinc-400' => $line['step'] !== 'purchase_parsed',
                                    ])>
                                        <span class="text-zinc-500 dark:text-zinc-500">{{ $line['step'] }}</span>
                                        <span class="text-zinc-400"> · </span>
                                        <span>{{ $line['message'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @php($resultPayload = is_array($run->result_payload) ? $run->result_payload : [])
                        @if ($resultPayload !== [])
                            <details class="mt-2 border-t border-zinc-200 pt-2 dark:border-zinc-600">
                                <summary class="cursor-pointer text-[10px] font-medium text-zinc-500 dark:text-zinc-400">
                                    {{ __('messages.automation_raw_log') }} (payload)
                                </summary>
                                <pre class="mt-1 max-h-32 overflow-auto whitespace-pre-wrap break-all font-mono text-[10px] text-zinc-600 dark:text-zinc-400">{{ json_encode($resultPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </td>
    <td class="px-4 py-3 text-end" wire:click.stop>
        @if ($run->status === \App\Enums\FulfillmentAutomationRunStatus::NeedsReview && $isLatest)
            <flux:button
                size="sm"
                variant="primary"
                class="!bg-amber-500 hover:!bg-amber-600"
                wire:click="selectRun('{{ $run->uuid }}', true)"
            >
                {{ __('messages.review') }}
            </flux:button>
        @elseif ($run->status === \App\Enums\FulfillmentAutomationRunStatus::Failed && $isLatest)
            <flux:button size="sm" variant="ghost" wire:click="retryRun('{{ $run->uuid }}')">
                {{ __('messages.retry_automation') }}
            </flux:button>
        @elseif ($run->isActive() && $isLatest)
            <flux:button size="sm" variant="danger" wire:click="cancelRun('{{ $run->uuid }}')">
                {{ __('messages.cancel') }}
            </flux:button>
        @else
            <flux:button size="sm" variant="ghost" wire:click="selectRun('{{ $run->uuid }}')">
                {{ __('messages.view') }}
            </flux:button>
        @endif
    </td>
</tr>
