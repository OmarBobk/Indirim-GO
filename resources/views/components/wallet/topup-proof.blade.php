@props(['detail' => []])

@php $detail = is_array($detail) ? $detail : []; @endphp

<section class="storefront-card storefront-card--pad-md" data-test="topup-proof">
    <flux:heading size="sm">{{ __('messages.proof_of_payment') }}</flux:heading>
    @if (! empty($detail['has_proof']) && ! empty($detail['proof_href']))
        <flux:button
            as="a"
            href="{{ $detail['proof_href'] }}"
            target="_blank"
            rel="noopener noreferrer"
            variant="ghost"
            size="sm"
            class="mt-3"
            data-test="topup-view-proof"
        >
            {{ __('messages.view_proof') }}
        </flux:button>
    @else
        <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('messages.topup_proof_none') }}</flux:text>
    @endif
</section>
