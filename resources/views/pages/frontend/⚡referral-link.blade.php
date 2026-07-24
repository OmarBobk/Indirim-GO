<?php

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::frontend')] class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        abort_unless(auth()->user()?->can('view_referrals'), 403);
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.referral_link'));
    }
};
?>

@php
    $referralUrl = route('home').'?ref='.urlencode((string) auth()->user()->referral_code);
@endphp

<x-storefront.page width="work">
    <x-storefront.page-header
        :title="__('messages.referral_link')"
        :description="__('messages.referral_link_hint')"
        :show-back="true"
        :back-fallback="route('account')"
    />

    <x-storefront.card>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center" x-data="{ copied: false }">
            <flux:input readonly :value="$referralUrl" class="flex-1" />
            <flux:button
                type="button"
                x-on:click="
                    navigator.clipboard.writeText(@js($referralUrl));
                    copied = true;
                    setTimeout(() => copied = false, 2000);
                "
                variant="ghost"
            >
                <span x-show="!copied">{{ __('messages.copy_to_clipboard') }}</span>
                <span x-show="copied" x-cloak>{{ __('messages.copied') }}</span>
            </flux:button>
        </div>
    </x-storefront.card>
</x-storefront.page>
