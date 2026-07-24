<?php

use Livewire\Component;

new class extends Component
{
    public bool $open = false;

    public bool $canReport = false;

    public function mount(): void
    {
        $this->canReport = auth()->check() && auth()->user()->can('manage_bugs');
    }

    public function show(): void
    {
        $this->open = true;
    }

    public function hide(): void
    {
        $this->open = false;
    }
};
?>

@if ($canReport)
    <div
        class="storefront-fab fixed end-4 z-[70]"
        x-on:bug-report-saved.window="$wire.hide()"
        data-test="storefront-bug-fab"
    >
        <flux:button variant="primary" icon="bug-ant" wire:click="show">
            {{ __('Report Bug') }}
        </flux:button>

        <flux:modal wire:model="open" class="w-full max-w-2xl">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Bug Report') }}</flux:heading>
                    <flux:button variant="ghost" icon="x-mark" wire:click="hide" />
                </div>

                <livewire:bugs.bug-report-form />
            </div>
        </flux:modal>
    </div>
@endif

