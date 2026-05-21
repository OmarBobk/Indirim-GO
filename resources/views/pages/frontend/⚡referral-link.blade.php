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

<div class="mx-auto w-full max-w-4xl space-y-8 py-8">
    @php
        $referralUrl = route('home').'?ref='.urlencode((string) auth()->user()->referral_code);
    @endphp

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg" class="mb-2">{{ __('messages.referral_link') }}</flux:heading>
        <flux:text class="mb-3 text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('messages.referral_link_hint') }}
        </flux:text>

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
                <span x-show="copied" x-cloak>Copied!</span>
            </flux:button>
        </div>
    </section>
</div>
