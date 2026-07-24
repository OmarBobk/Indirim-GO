<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>{{ $title ?? config('app.name') }}</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@php
    $loyaltyTierLabels = [
        'bronze' => __('messages.loyalty_tier_bronze'),
        'silver' => __('messages.loyalty_tier_silver'),
        'gold' => __('messages.loyalty_tier_gold'),
    ];
    $localeTag = str_replace('_', '-', app()->getLocale());
    $amountMaskEnglishStyle = str_starts_with($localeTag, 'en');
    $amountIntegerMask = [
        'decimal' => $amountMaskEnglishStyle ? '.' : ',',
        'thousands' => $amountMaskEnglishStyle ? ',' : '.',
        'precision' => 0,
        'intlLocale' => $localeTag,
    ];
    $preferredCurrency = auth()->check() && in_array((string) auth()->user()->preferred_currency, ['USD', 'TRY'], true)
        ? (string) auth()->user()->preferred_currency
        : 'USD';
    $usdTryRate = $preferredCurrency === 'TRY'
        ? \App\Models\WebsiteSetting::getUsdTryRate()
        : null;
@endphp
<script>
    window.Laravel = window.Laravel || {};
    @auth
    window.Laravel.userId = {{ auth()->id() }};
    window.Laravel.isAdmin = @json(auth()->user()?->hasRole('admin'));
    window.Laravel.canViewFulfillments = @json(auth()->user()?->can('view_fulfillments'));
    window.Laravel.canManageBugs = @json(auth()->user()?->can('manage_bugs'));
    @endauth
    window.Laravel.loyaltyTierLabels = @json($loyaltyTierLabels);
    window.Laravel.amountIntegerMask = @json($amountIntegerMask);
    window.Laravel.preferredCurrency = @json($preferredCurrency);
    window.Laravel.usdTryRate = @json($usdTryRate);
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
@PwaHead
