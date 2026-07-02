@props([
    'count' => 0,
    'tone' => 'amber',
])

@if ($count > 0)
    <span
        @class([
            'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[10px] font-bold tabular-nums text-white shadow-sm ring-1 ring-black/10',
            'bg-amber-500 ring-amber-300/30' => $tone === 'amber',
            'bg-red-500 ring-red-300/30' => $tone === 'red',
        ])
        data-test="sidebar-count-badge"
    >
        {{ $count > 99 ? '99+' : $count }}
    </span>
@endif
