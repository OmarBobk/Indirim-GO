<?php

use App\Support\StorefrontShell;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::frontend')] class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function render(): View
    {
        return $this->view()
            ->title(__('main.account'))
            ->with([
                'sections' => StorefrontShell::accountHubSections(),
            ]);
    }
};
?>

@php
    $isRtl = app()->isLocale('ar');
@endphp

<x-storefront.page width="work" data-test="account-hub" data-zone="account">
    <x-storefront.page-header
        :title="__('main.account')"
        :description="__('main.account_hub_intro')"
    />

    <div class="storefront-section-stack" data-test="account-hub-links">
        @foreach ($sections as $section)
            <section
                wire:key="account-hub-section-{{ $section['key'] }}"
                aria-labelledby="account-hub-heading-{{ $section['key'] }}"
                data-test="account-hub-section-{{ $section['key'] }}"
            >
                <x-storefront.section-label :id="'account-hub-heading-'.$section['key']">
                    {{ $section['label'] }}
                </x-storefront.section-label>
                <nav class="space-y-2" aria-label="{{ $section['label'] }}">
                    @foreach ($section['links'] as $link)
                        <a
                            wire:key="account-hub-{{ $link['key'] }}"
                            href="{{ $link['href'] }}"
                            wire:navigate
                            class="storefront-shell-icon-btn storefront-focus-ring flex min-h-11 items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 text-zinc-800 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800"
                            data-test="account-hub-link-{{ $link['key'] }}"
                        >
                            <flux:icon :icon="$link['icon']" class="size-5 shrink-0 text-zinc-500 dark:text-zinc-400" />
                            <span class="min-w-0 flex-1 font-medium">{{ $link['label'] }}</span>
                            @if ($link['badge_count'] > 0)
                                <span
                                    class="storefront-unread-badge"
                                    data-test="account-hub-badge-{{ $link['key'] }}"
                                    aria-hidden="true"
                                >
                                    {{ $link['badge_count'] > 9 ? '9+' : $link['badge_count'] }}
                                </span>
                            @endif
                            <flux:icon icon="chevron-right" class="size-4 shrink-0 text-zinc-400 rtl:rotate-180" />
                        </a>
                    @endforeach
                </nav>
            </section>
        @endforeach

        <section
            aria-labelledby="account-hub-heading-settings"
            data-test="account-hub-section-settings"
        >
            <x-storefront.section-label id="account-hub-heading-settings">
                {{ __('main.account_section_settings') }}
            </x-storefront.section-label>
            <div class="space-y-2" data-test="account-hub-preferences">
                <div class="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="min-w-0 font-medium text-zinc-800 dark:text-zinc-100">{{ __('messages.language') }}</p>
                    <livewire:language-switcher />
                </div>

                <div class="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="min-w-0 font-medium text-zinc-800 dark:text-zinc-100">{{ __('messages.appearance') }}</p>
                    <flux:button
                        x-data
                        x-on:click="$flux.dark = ! $flux.dark"
                        icon="moon"
                        variant="subtle"
                        class="storefront-shell-icon-btn !size-10 shrink-0"
                        aria-label="{{ __('main.toggle_theme') }}"
                        data-test="account-hub-theme"
                    />
                </div>
            </div>
        </section>
    </div>

    <form method="POST" action="{{ route('logout') }}" class="mt-8" data-test="account-hub-logout">
        @csrf
        <flux:button
            type="submit"
            variant="ghost"
            icon="{{ $isRtl ? 'arrow-left-start-on-rectangle' : 'arrow-right-start-on-rectangle' }}"
            class="storefront-shell-icon-btn w-full min-h-11 justify-start !text-zinc-700 focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 dark:!text-zinc-200"
            data-test="logout-button"
        >
            {{ __('main.logout') }}
        </flux:button>
    </form>
</x-storefront.page>
