<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public int $perPage = 15;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function getNotificationsProperty(): LengthAwarePaginator
    {
        return Auth::user()
            ->notifications()
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }

    public function markAsRead(string $id): void
    {
        $notification = Auth::user()->notifications()->whereKey($id)->first();
        if ($notification !== null) {
            $notification->markAsRead();
        }
    }

    public function render(): View
    {
        return $this->view()->layout('layouts::frontend')->title(__('messages.notifications'));
    }
};
?>

<x-storefront.page
    width="work"
    x-data="{ newIds: [] }"
    x-on:notification-received.window="const id = $event.detail?.id; if (id) newIds.push(id); $wire.$refresh(); setTimeout(() => { const i = newIds.indexOf(id); if (i !== -1) newIds.splice(i, 1); }, 8000)"
>
    <x-storefront.page-header
        :title="__('messages.notifications')"
        :description="__('messages.notifications_intro')"
        :show-back="true"
        :back-fallback="route('account')"
    />

    <div class="flex flex-col gap-3">
        @forelse ($this->notifications as $notification)
            @php
                $data = $notification->data;
                $title = $data['title'] ?? '';
                $message = $data['message'] ?? '';
                $url = $data['url'] ?? null;
                $isUnread = $notification->read_at === null;
            @endphp
            <x-storefront.card
                wire:key="notif-{{ $notification->id }}"
                padding="sm"
                @class([
                    'border-sky-300 dark:border-sky-700' => $isUnread,
                ])
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="sm" class="storefront-type-section text-zinc-900 dark:text-zinc-100">{{ $title }}</flux:heading>
                            @if ($isUnread)
                                <flux:badge color="sky" size="sm" class="shrink-0">
                                    {{ __('messages.unread') }}
                                </flux:badge>
                            @endif
                            <span
                                x-show="typeof newIds !== 'undefined' && newIds.includes('{{ $notification->id }}')"
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 scale-90"
                                x-transition:enter-end="opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100"
                                x-transition:leave-end="opacity-0"
                                class="shrink-0"
                            >
                                <flux:badge color="green" size="sm">{{ __('messages.new') }}</flux:badge>
                            </span>
                        </div>
                        <flux:text class="storefront-type-body mt-1 text-zinc-600 dark:text-zinc-400">{{ $message }}</flux:text>
                        <flux:text class="storefront-type-meta mt-2">
                            {{ $notification->created_at?->diffForHumans() }}
                        </flux:text>
                    </div>
                    <div class="shrink-0">
                        @if ($url)
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="arrow-top-right-on-square"
                                :href="$url"
                                wire:navigate
                                wire:click="markAsRead('{{ $notification->id }}')"
                            >
                                {{ __('messages.view') }}
                            </flux:button>
                        @else
                            <flux:button
                                size="sm"
                                variant="ghost"
                                wire:click="markAsRead('{{ $notification->id }}')"
                            >
                                {{ __('messages.mark_read') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            </x-storefront.card>
        @empty
            <x-storefront.empty
                icon="bell"
                :title="__('messages.no_notifications')"
                :description="__('messages.no_notifications_hint')"
                data-test="notifications-empty"
            >
                <x-slot:actions>
                    <flux:button
                        variant="primary"
                        href="{{ route('home') }}"
                        wire:navigate
                        class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                        data-test="notifications-empty-home"
                    >
                        {{ __('messages.notifications_continue_shopping') }}
                    </flux:button>
                    <flux:button
                        variant="ghost"
                        href="{{ route('orders.index') }}"
                        wire:navigate
                        data-test="notifications-empty-orders"
                    >
                        {{ __('main.my_orders') }}
                    </flux:button>
                </x-slot:actions>
            </x-storefront.empty>
        @endforelse
    </div>

    @if ($this->notifications->hasPages())
        <div class="mt-6">
            {{ $this->notifications->links() }}
        </div>
    @endif
</x-storefront.page>
