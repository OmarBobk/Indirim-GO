@props([
    'summary' => [],
    'history' => [],
])

<section class="glass-card p-4 sm:p-5">
    <div class="mb-4">
        <h2 class="text-base font-semibold text-white">{{ __('messages.my_earnings_and_commissions') }}</h2>
        <p class="text-xs text-zinc-400">{{ __('messages.my_earnings_intro') }}</p>
    </div>

    <div class="grid gap-4 lg:grid-cols-[1.1fr_1fr]">
        <div class="rounded-xl border border-white/8 bg-[hsl(var(--surface-2)/0.72)] p-3">
            <dl class="space-y-2.5">
                @foreach ($summary as $item)
                    <div class="flex items-center justify-between border-b border-white/6 pb-2 last:border-b-0 last:pb-0">
                        <dt class="text-sm text-zinc-300">{{ $item['label'] }}</dt>
                        <dd class="num text-sm font-semibold text-white">${{ number_format((float) $item['value'], 2) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <div class="rounded-xl border border-white/8 bg-[hsl(var(--surface-2)/0.72)] p-3">
            <div class="mb-2 flex items-center justify-between">
                <p class="label-eyebrow">{{ __('messages.salesperson_payout_history') }}</p>
                <span class="text-xs text-zinc-500">{{ trans_choice('messages.salesperson_payout_history_item_count', count($history)) }}</span>
            </div>
            <div class="max-h-40 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="text-[11px] uppercase tracking-[0.08em] text-zinc-500">
                        <tr>
                            <th class="py-1.5 text-start font-medium">{{ __('messages.date') }}</th>
                            <th class="py-1.5 text-end font-medium">{{ __('messages.amount') }}</th>
                            <th class="py-1.5 text-start font-medium">{{ __('messages.method') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @forelse ($history as $row)
                            <tr>
                                <td class="py-2 text-zinc-300">{{ $row['date'] }}</td>
                                <td class="num py-2 text-end font-semibold text-[hsl(var(--accent-earnings))]">${{ number_format((float) $row['amount'], 2) }}</td>
                                <td class="py-2 text-zinc-400">
                                    @if (($row['method'] ?? '') === 'wallet')
                                        {{ __('messages.wallet') }}
                                    @else
                                        {{ $row['method'] ?? '—' }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-5 text-center text-zinc-500">{{ __('messages.no_commissions_yet') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
