@props([
    'actions' => [],
])

@php
    $actions = is_array($actions) ? $actions : [];
    $canAddFunds = (bool) ($actions['can_add_funds'] ?? true);
    $resumeUrl = $actions['purchase_resume_url'] ?? null;
@endphp

<section class="space-y-3" data-test="financial-primary-actions" aria-label="{{ __('messages.financial_primary_actions_a11y') }}">
    @if ($canAddFunds)
        <flux:button
            as="a"
            href="{{ $actions['add_funds_href'] ?? route('wallet.topup') }}"
            wire:navigate
            variant="primary"
            icon="plus"
            class="w-full !bg-accent !text-accent-foreground hover:!bg-accent-hover sm:w-auto"
            data-test="wallet-add-funds"
        >
            {{ __('messages.wallet_add_funds') }}
        </flux:button>
    @else
        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400" data-test="wallet-add-funds-blocked">
            {{ __('messages.wallet_topup_pending_banner') }}
        </flux:text>
    @endif

    @if (is_string($resumeUrl) && $resumeUrl !== '')
        <flux:callout variant="subtle" icon="arrow-path" data-test="purchase-resume-banner">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.purchase_resume_banner_title') }}
                    </flux:text>
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('messages.purchase_resume_banner_body') }}
                    </flux:text>
                </div>
                <flux:button
                    as="a"
                    href="{{ $resumeUrl }}"
                    wire:navigate
                    variant="ghost"
                    size="sm"
                    class="shrink-0"
                    data-test="purchase-resume-continue"
                >
                    {{ __('messages.purchase_resume_continue') }}
                </flux:button>
            </div>
        </flux:callout>
    @endif

    <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
        <a href="{{ $actions['track_topups_href'] ?? route('wallet.topup') }}" wire:navigate class="text-zinc-600 underline-offset-2 hover:underline dark:text-zinc-400" data-test="financial-track-topups">
            {{ __('messages.financial_track_topups') }}
        </a>
        <a href="{{ $actions['track_refunds_href'] ?? route('orders.index') }}" wire:navigate class="text-zinc-600 underline-offset-2 hover:underline dark:text-zinc-400" data-test="financial-track-refunds">
            {{ __('messages.financial_track_refunds') }}
        </a>
    </div>
</section>
