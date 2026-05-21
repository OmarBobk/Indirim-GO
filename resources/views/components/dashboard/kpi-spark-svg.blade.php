@props([
    'spark' => [],
    'gradientId' => 'kpi-spark',
    'accentVar' => '--accent-earnings',
    'width' => 88,
    'height' => 30,
])

@php
    $data = array_map('floatval', $spark ?? []);
    if ($data === []) {
        $data = [0.0];
    }
    $w = (int) $width;
    $h = (int) $height;
    $min = min($data);
    $max = max($data);
    $range = max(0.0001, $max - $min);
    $n = count($data);
    $step = $n > 1 ? $w / ($n - 1) : 0;
    $pts = collect($data)
        ->map(fn (float $v, int $i): string => round($i * $step, 2).','.round($h - (($v - $min) / $range) * $h, 2))
        ->implode(' ');
@endphp

<svg
    xmlns="http://www.w3.org/2000/svg"
    width="{{ $w }}"
    height="{{ $h }}"
    viewBox="0 0 {{ $w }} {{ $h }}"
    class="shrink-0 overflow-visible"
    style="color: hsl(var({{ $accentVar }}));"
    aria-hidden="true"
    dir="ltr"
>
    <defs>
        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="currentColor" stop-opacity="0.42" />
            <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
        </linearGradient>
    </defs>
    <polygon points="0,{{ $h }} {{ $pts }} {{ $w }},{{ $h }}" fill="url(#{{ $gradientId }})" />
    <polyline
        points="{{ $pts }}"
        fill="none"
        stroke="currentColor"
        stroke-width="1.75"
        stroke-linejoin="round"
        stroke-linecap="round"
    />
</svg>
