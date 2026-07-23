@props([
    'visible' => false,
])

@if ($visible)
    <div
        class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40"
        data-test="order-detail-attention-strip"
        data-section="order-detail-attention-strip"
        role="status"
    >
        <div class="space-y-0.5">
            <div class="text-sm font-semibold text-amber-950 dark:text-amber-100">
                {{ __('messages.orders_needs_attention_section') }}
            </div>
            <flux:text class="text-sm text-amber-900/80 dark:text-amber-200/80">
                {{ __('messages.orders_needs_attention_section_hint') }}
            </flux:text>
        </div>
    </div>
@else
    {{-- Slot reserved when the presenter classification is not needs_attention. --}}
    <div
        class="hidden"
        data-test="order-detail-attention-strip-placeholder"
        data-section="order-detail-attention-strip"
        aria-hidden="true"
    ></div>
@endif
