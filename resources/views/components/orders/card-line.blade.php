@props([
    'line',
    'showPrices' => true,
    'borderTop' => false,
])

<div @class(['order-card-line', 'border-t border-zinc-100 pt-4 dark:border-zinc-800' => $borderTop])>
    <div class="flex gap-3 sm:gap-4">
        <div class="relative flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800">
            @if (! empty($line['image']))
                <img
                    src="{{ asset($line['image']) }}"
                    alt=""
                    class="h-full w-full object-contain"
                    loading="lazy"
                    onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden')"
                />
                <div class="absolute inset-0 hidden items-center justify-center bg-zinc-100 dark:bg-zinc-800">
                    <flux:icon icon="cube" class="size-5 text-zinc-400 dark:text-zinc-500" />
                </div>
            @else
                <flux:icon icon="cube" class="size-5 text-zinc-400 dark:text-zinc-500" />
            @endif
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                <div class="min-w-0 flex-1">
                    <p class="text-base font-semibold leading-snug text-zinc-900 dark:text-zinc-100">
                        {{ $line['title'] }}
                    </p>
                    @if (! empty($line['subtitle']))
                        <p class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $line['subtitle'] }}
                        </p>
                    @endif
                    @if (! empty($line['custom_amount']))
                        <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">
                            {{ $line['custom_amount'] }}
                        </p>
                    @endif
                    <p class="mt-1 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                        {{ $line['meta'] }}
                    </p>
                </div>
                @if ($showPrices && ! empty($line['line_total']))
                    <p class="shrink-0 text-end text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-100 sm:pt-0.5" dir="ltr">
                        {{ $line['line_total'] }}
                    </p>
                @endif
            </div>

            @if (! empty($line['expandable_units']) && ! empty($line['units']))
                <div class="mt-3" x-data="{ open: false }">
                    <button
                        type="button"
                        class="text-xs font-medium text-accent hover:underline"
                        @click.stop="open = !open"
                        data-test="order-card-units-toggle"
                    >
                        <span x-show="!open">{{ __('messages.orders_card_show_unit_details') }}</span>
                        <span x-show="open" x-cloak>{{ __('messages.orders_card_hide_unit_details') }}</span>
                    </button>
                    <ul x-show="open" x-transition class="mt-2 space-y-1 border-s-2 border-zinc-200 ps-3 dark:border-zinc-600" x-cloak>
                        @foreach ($line['units'] as $unit)
                            <li class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $unit['meta'] }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>
