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
            <span @class(['inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold', $this->statusBadgeClass($run->status)])>
                {{ __('messages.automation_status_'.$run->status->value) }}
            </span>
            @if ($showAttemptLabel)
                <span class="text-[11px] text-zinc-500 dark:text-zinc-400">
                    {{ __('messages.automation_attempt_label', ['n' => $run->attempt]) }}
                </span>
            @endif
        </div>
    </td>
    <td class="px-4 py-3 font-mono text-xs text-zinc-800 dark:text-zinc-200">
        {{ Str::limit($run->uuid, 13, '…') }}
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
        {{ $this->supplierLabel($run) }}
    </td>
    <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400 whitespace-nowrap">
        {{ $this->runStartedAt($run)?->diffForHumans() ?? '—' }}
    </td>
    <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-400">
        {{ $this->runDurationLabel($run) }}
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
