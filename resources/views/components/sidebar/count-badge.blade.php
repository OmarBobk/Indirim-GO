@props([
    'count' => 0,
    'tone' => 'amber',
])

@if ($count > 0)
    <span
        @class([
            'inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full px-1 text-[11px] font-semibold text-white',
            'bg-amber-500' => $tone === 'amber',
            'bg-red-500' => $tone === 'red',
        ])
        data-test="sidebar-count-badge"
    >
        {{ $count > 99 ? '99+' : $count }}
    </span>
@endif
