@props([
    'display',
])

@php
    /** @var \App\Support\CustomerWalletDisplay $display */
    $rows = $display->chromeDetailRows();
@endphp

<div {{ $attributes->class(['space-y-3']) }} data-test="wallet-chrome-summary">
    <div class="space-y-2">
        @foreach ($rows as $row)
            <div class="flex items-baseline justify-between gap-3">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    {{ $row['label'] }}
                </p>
                <p
                    @class([
                        'text-sm font-semibold tabular-nums',
                        'text-red-700 dark:text-red-400' => ($row['tone'] ?? null) === 'debt',
                        'text-emerald-700 dark:text-emerald-400' => ($row['tone'] ?? null) === 'positive',
                        'text-zinc-800 dark:text-zinc-100' => ! in_array($row['tone'] ?? null, ['debt', 'positive'], true),
                    ])
                    dir="ltr"
                >
                    {{ $row['value'] }}
                </p>
            </div>
        @endforeach
    </div>
</div>
