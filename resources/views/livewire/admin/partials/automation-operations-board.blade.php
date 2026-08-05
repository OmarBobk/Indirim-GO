@php
    $ops = $this->operationsDashboard;
@endphp

<section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="automation-ops-health" aria-live="polite">
    <div class="mb-3 flex items-center justify-between gap-2">
        <flux:heading size="sm">{{ __('messages.automation_ops_health_heading') }}</flux:heading>
        <flux:text class="text-xs text-zinc-500">{{ __('messages.automation_ops_live_hint') }}</flux:text>
    </div>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($ops->healthCards as $card)
            <div
                wire:key="health-{{ $card->key }}"
                @class([
                    'rounded-xl border p-3',
                    'border-emerald-200 bg-emerald-50/70 dark:border-emerald-900/50 dark:bg-emerald-950/30' => in_array($card->state, ['enabled', 'ready', 'clear', 'available', 'idle', 'healthy'], true),
                    'border-amber-200 bg-amber-50/70 dark:border-amber-900/50 dark:bg-amber-950/30' => in_array($card->state, ['degraded', 'active', 'unknown', 'attention'], true),
                    'border-red-200 bg-red-50/70 dark:border-red-900/50 dark:bg-red-950/30' => in_array($card->state, ['disabled', 'unavailable'], true),
                    'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/40' => ! in_array($card->state, ['enabled', 'ready', 'clear', 'available', 'idle', 'healthy', 'degraded', 'active', 'unknown', 'attention', 'disabled', 'unavailable'], true),
                ])
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $card->label }}</div>
                    <span class="rounded-full bg-white/70 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-zinc-700 dark:bg-zinc-950/40 dark:text-zinc-300">
                        {{ $card->state }}
                    </span>
                </div>
                @if ($card->reason)
                    <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">{{ $card->reason }}</p>
                @endif
                @if ($card->meta !== [])
                    <dl class="mt-2 space-y-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">
                        @foreach ($card->meta as $metaKey => $metaValue)
                            @if ($metaValue !== null && $metaValue !== '')
                                <div class="flex justify-between gap-2">
                                    <dt class="truncate">{{ $metaKey }}</dt>
                                    <dd class="font-mono tabular-nums">{{ is_bool($metaValue) ? ($metaValue ? 'yes' : 'no') : $metaValue }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                @endif
            </div>
        @endforeach
    </div>
</section>

@php
    $sections = [
        ['key' => 'working_now', 'title' => __('messages.automation_working_now'), 'items' => $ops->workingNow],
        ['key' => 'waiting_supplier', 'title' => __('messages.automation_waiting_for_supplier'), 'items' => $ops->waitingSupplier],
        ['key' => 'scheduled_reconcile', 'title' => __('messages.automation_scheduled_reconciliation'), 'items' => $ops->scheduledReconcile],
        ['key' => 'needs_attention', 'title' => __('messages.automation_needs_attention'), 'items' => $ops->needsAttention],
        ['key' => 'recent_outcomes', 'title' => __('messages.automation_recent_outcomes'), 'items' => $ops->recentOutcomes],
    ];
@endphp

@foreach ($sections as $section)
    <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="ops-{{ $section['key'] }}" aria-live="polite">
        <div class="mb-3 flex items-center gap-2">
            <flux:heading size="sm">{{ $section['title'] }}</flux:heading>
            <flux:badge size="sm" color="zinc">{{ count($section['items']) }}</flux:badge>
        </div>

        @if ($section['items'] === [])
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('messages.automation_ops_section_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-start text-sm">
                    <thead class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th scope="col" class="px-2 py-2 font-medium">{{ __('messages.order') }}</th>
                            <th scope="col" class="px-2 py-2 font-medium">{{ __('messages.fulfillment') }}</th>
                            <th scope="col" class="px-2 py-2 font-medium">{{ __('messages.package') }}</th>
                            <th scope="col" class="px-2 py-2 font-medium">{{ __('messages.automation_supplier') }}</th>
                            <th scope="col" class="px-2 py-2 font-medium">{{ __('messages.automation_phase') }}</th>
                            <th scope="col" class="px-2 py-2 font-medium">{{ __('messages.automation_current_step') }}</th>
                            <th scope="col" class="px-2 py-2 font-medium">{{ __('messages.automation_liveness') }}</th>
                            <th scope="col" class="px-2 py-2 font-medium">{{ __('messages.automation_last_heartbeat') }}</th>
                            <th scope="col" class="px-2 py-2 font-medium">{{ __('messages.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($section['items'] as $item)
                            <tr wire:key="ops-item-{{ $section['key'] }}-{{ $item->runUuid ?? $item->fulfillmentId }}-{{ $item->kind }}" class="align-top">
                                <td class="px-2 py-2 font-mono text-xs tabular-nums">{{ $item->orderNumber ?? '—' }}</td>
                                <td class="px-2 py-2 font-mono text-xs">{{ $item->fulfillmentReference }}</td>
                                <td class="px-2 py-2">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $item->packageName ?? '—' }}</div>
                                    @if ($item->productName)
                                        <div class="text-xs text-zinc-500">{{ $item->productName }}</div>
                                    @endif
                                </td>
                                <td class="px-2 py-2">{{ $item->supplierKey }}</td>
                                <td class="px-2 py-2">
                                    <div>{{ $item->phase }}</div>
                                    <div class="text-xs text-zinc-500">{{ $this->presentationLabel($item->presentation) }}</div>
                                </td>
                                <td class="px-2 py-2">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $item->stepLabel }}</div>
                                    @if ($item->step)
                                        <div class="font-mono text-[10px] text-zinc-400">{{ $item->step }}</div>
                                    @endif
                                    <div
                                        class="mt-1 text-[11px] text-zinc-500"
                                        x-data="{ started: @js($item->stepStartedAtIso), label: '—' }"
                                        x-init="
                                            const tick = () => {
                                                if (!started) { label = '—'; return; }
                                                const secs = Math.max(0, Math.floor((Date.now() - Date.parse(started)) / 1000));
                                                label = secs < 60 ? (secs + 's') : (Math.floor(secs/60) + 'm ' + (secs%60) + 's');
                                            };
                                            tick();
                                            setInterval(tick, 1000);
                                        "
                                        x-text="'{{ __('messages.automation_step_duration') }}: ' + label"
                                    ></div>
                                </td>
                                <td class="px-2 py-2">
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' => $item->liveness === 'healthy',
                                        'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200' => in_array($item->liveness, ['slow', 'waiting_supplier', 'scheduled_reconcile'], true),
                                        'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300' => in_array($item->liveness, ['stale', 'needs_attention'], true),
                                        'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' => $item->liveness === 'unknown',
                                    ])>
                                        <span @class([
                                            'size-1.5 rounded-full',
                                            'bg-emerald-500' => $item->liveness === 'healthy',
                                            'bg-amber-500' => in_array($item->liveness, ['slow', 'waiting_supplier', 'scheduled_reconcile'], true),
                                            'bg-red-500' => in_array($item->liveness, ['stale', 'needs_attention'], true),
                                            'bg-zinc-400' => $item->liveness === 'unknown',
                                        ]) aria-hidden="true"></span>
                                        <span>{{ __('messages.automation_liveness_'.$item->liveness) }}</span>
                                    </span>
                                    @if ($item->actionRequired)
                                        <div class="mt-1 text-[11px] font-medium text-red-600 dark:text-red-300">{{ __('messages.automation_action_required') }}</div>
                                    @endif
                                    @if ($item->supplierOrderId)
                                        <div class="mt-1 font-mono text-[10px] text-zinc-500">{{ $item->supplierOrderId }}</div>
                                    @endif
                                    @if ($item->workerBuild)
                                        <div class="mt-1 text-[10px] text-zinc-400">{{ $item->workerBuild }}</div>
                                    @endif
                                </td>
                                <td class="px-2 py-2 text-xs text-zinc-500"
                                    x-data="{ hb: @js($item->lastHeartbeatAtIso), label: '—' }"
                                    x-init="
                                        const tick = () => {
                                            if (!hb) { label = '{{ __('messages.automation_heartbeat_none') }}'; return; }
                                            const secs = Math.max(0, Math.floor((Date.now() - Date.parse(hb)) / 1000));
                                            label = secs < 60 ? (secs + 's ago') : (Math.floor(secs/60) + 'm ago');
                                        };
                                        tick();
                                        setInterval(tick, 1000);
                                    "
                                    x-text="label"
                                ></td>
                                <td class="px-2 py-2">
                                    @if ($item->detailRunUuid)
                                        <flux:button size="sm" variant="ghost" wire:click="selectRun({{ json_encode($item->detailRunUuid) }})">
                                            {{ __('messages.details') }}
                                        </flux:button>
                                    @else
                                        <span class="text-xs text-zinc-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endforeach
