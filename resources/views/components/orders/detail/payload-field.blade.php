@props([
    'unitId',
    /** @var array{key: string, label: string, value: string, image_urls: list<string>} $entry */
    'entry',
])

<div class="flex min-w-0 flex-wrap items-start justify-between gap-2" wire:key="fulfillment-payload-{{ $unitId }}-{{ $entry['key'] }}">
    <div class="flex min-w-0 flex-1 flex-col gap-2">
        <span class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            {{ $entry['label'] }}
        </span>
        @if ($entry['value'] !== '')
            <div
                class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-stretch"
                x-data="{ copied: false }"
                data-test="delivery-payload-copy-field"
            >
                <span
                    class="block min-w-0 flex-1 break-all rounded-lg bg-zinc-50 px-3 py-2.5 font-mono text-sm font-semibold tracking-wide text-zinc-900 dark:bg-zinc-800/80 dark:text-zinc-100"
                    dir="ltr"
                    data-test="delivery-payload-value"
                >
                    {{ $entry['value'] }}
                </span>
                <button
                    type="button"
                    class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg border border-zinc-300 bg-white px-4 text-sm font-semibold text-zinc-900 transition hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800 dark:focus-visible:ring-zinc-500 sm:min-w-24"
                    x-on:click="
                        navigator.clipboard.writeText(@js($entry['value']));
                        copied = true;
                        setTimeout(() => copied = false, 2000);
                    "
                    data-test="delivery-payload-copy"
                >
                    <span x-show="! copied">{{ __('messages.copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('messages.copied') }} ✓</span>
                </button>
            </div>
        @endif
        @foreach ($entry['image_urls'] as $imageUrl)
            <a
                href="{{ $imageUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                class="block overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800"
                wire:key="fulfillment-payload-image-{{ $unitId }}-{{ $entry['key'] }}-{{ $loop->index }}"
                data-test="delivery-payload-image"
            >
                <img
                    src="{{ $imageUrl }}"
                    alt="{{ $entry['label'] }}"
                    class="mx-auto max-h-80 w-full object-contain"
                    loading="lazy"
                    decoding="async"
                    referrerpolicy="no-referrer"
                />
            </a>
        @endforeach
    </div>
</div>
