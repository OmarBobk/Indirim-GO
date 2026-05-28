<?php

use Livewire\Component;

new class extends Component
{
};
?>

@php
    $currentLocale = app()->getLocale();
@endphp

<div>
    <flux:dropdown>
        <flux:button variant="ghost" size="sm" icon="language" class="w-auto justify-center sm:w-full sm:justify-start" icon:trailing="chevron-down">
            <span class="hidden sm:inline">{{ __('messages.language') }}</span>
        </flux:button>

        <flux:menu>
            <div class="px-2 py-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('messages.language') }}: <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ strtoupper($currentLocale) }}</span>
            </div>
            <flux:menu.separator />
            <flux:menu.radio.group>
                <flux:menu.item
                    :href="route('language.switch', ['locale' => 'en'])"
                    :active="$currentLocale === 'en'"
                    icon="check"
                    :icon-active="$currentLocale === 'en'"
                    wire:navigate
                    class="{{ $currentLocale === 'en' ? 'hidden' : '' }}"
                >
                    {{ __('messages.english') }}
                </flux:menu.item>
                <flux:menu.item
                    :href="route('language.switch', ['locale' => 'ar'])"
                    :active="$currentLocale === 'ar'"
                    icon="check"
                    :icon-active="$currentLocale === 'ar'"
                    wire:navigate
                    class="{{ $currentLocale === 'ar' ? 'hidden' : '' }}"
                >
                    {{ __('messages.arabic') }}
                </flux:menu.item>
            </flux:menu.radio.group>
            <flux:menu.separator />
            <div class="px-2 py-1.5 text-[11px] text-zinc-500 dark:text-zinc-400">
                {{ __('messages.notifications') }} · {{ __('messages.language') }}
            </div>
        </flux:menu>
    </flux:dropdown>
</div>
