@props([
    'active' => 'overview',
])

@php
    $links = [
        'overview' => [
            'href' => route('wallet'),
            'label' => __('messages.financial_nav_overview'),
            'test' => 'financial-nav-overview',
        ],
        'transactions' => [
            'href' => route('wallet.transactions.index'),
            'label' => __('messages.financial_nav_transactions'),
            'test' => 'financial-nav-transactions',
        ],
        'topups' => [
            'href' => route('wallet.topups.index'),
            'label' => __('messages.financial_nav_topups'),
            'test' => 'financial-nav-topups',
        ],
        'refunds' => [
            'href' => route('wallet.refunds.index'),
            'label' => __('messages.financial_nav_refunds'),
            'test' => 'financial-nav-refunds',
        ],
    ];
@endphp

<nav
    {{ $attributes->class(['flex gap-2 overflow-x-auto pb-1']) }}
    aria-label="{{ __('messages.financial_centre_nav_label') }}"
    data-test="financial-centre-nav"
>
    @foreach ($links as $key => $link)
        @php $isActive = $active === $key; @endphp
        <a
            href="{{ $link['href'] }}"
            wire:navigate
            @class([
                'shrink-0 rounded-full border px-4 py-2 text-sm font-semibold transition',
                'border-(--color-accent) bg-(--color-accent) text-(--color-accent-foreground)' => $isActive,
                'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-600 dark:hover:bg-zinc-800' => ! $isActive,
            ])
            @if ($isActive) aria-current="page" @endif
            data-test="{{ $link['test'] }}"
        >
            {{ $link['label'] }}
        </a>
    @endforeach
</nav>
