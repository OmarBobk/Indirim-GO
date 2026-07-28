<div
    class="relative"
    x-data="{
        newIds: [],
        markDelay: false,
        highlightNotificationId(id) {
            if (! id || this.newIds.includes(id)) return;
            this.newIds.push(id);
            setTimeout(() => {
                const index = this.newIds.indexOf(id);
                if (index !== -1) this.newIds.splice(index, 1);
            }, 8000);
        }
    }"
    x-on:customer-activity-invalidate.window="
        const id = $event.detail?.notificationId;
        if (id && ! ($event.detail?.isReconcile)) highlightNotificationId(id);
    "
    x-on:scroll.window="const p = $el.querySelector('[popover]'); if (p) { try { p.hidePopover(); } catch (_) {} }"
>
    <flux:dropdown position="bottom" align="end" class="min-w-[320px] max-w-[90vw]">
        <div class="relative">
            <flux:button
                variant="ghost"
                icon="bell"
                class="storefront-shell-icon-btn !h-10 !w-10 !p-0 [&>div>svg]:size-5 !text-zinc-700 dark:!text-zinc-300 hover:!bg-zinc-200 dark:hover:!bg-zinc-800 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-zinc-900"
                aria-label="{{ $this->unreadCount > 0 ? __('main.notifications_unread_aria', ['count' => $this->unreadCount > 9 ? '9+' : $this->unreadCount]) : __('messages.notifications') }}"
                x-on:click="
                    if (! markDelay) {
                        markDelay = true;
                        setTimeout(() => markDelay = false, 300);
                    }
                "
            />
            @if ($this->unreadCount > 0)
                <span class="storefront-unread-badge absolute -end-0.5 -top-0.5" aria-hidden="true">
                    {{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}
                </span>
            @endif
        </div>
        <flux:menu keep-open x-on:click.stop class="!p-0 rounded-xl border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
            <div class="max-h-[70vh] overflow-auto p-2">
                <div class="mb-2 flex items-center justify-between gap-2 px-2">
                    <flux:heading size="sm">{{ __('messages.notifications') }}</flux:heading>
                    @if ($this->unreadCount > 0)
                        <div class="flex items-center gap-1">
                            <flux:button variant="ghost" size="sm" wire:click="markAsReadOnOpen">
                                {{ __('messages.mark_all_read') }}
                            </flux:button>
                            <flux:button variant="ghost" size="sm" :href="route('activity.index')" wire:navigate>
                                {{ __('messages.activity_view_all') }}
                            </flux:button>
                        </div>
                    @endif
                </div>
                @forelse ($this->latestNotifications as $notification)
                    @php
                        $data = $notification->data;
                        $title = $data['title'] ?? '';
                        $message = $data['message'] ?? '';
                        $url = $data['url'] ?? null;
                        $isUnread = $notification->read_at === null;
                    @endphp
                    <div
                        wire:key="bell-notif-{{ $notification->id }}"
                        class="rounded-lg border p-3 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/60 {{ $isUnread ? 'border-sky-200 bg-sky-50/50 dark:border-sky-800 dark:bg-sky-950/20' : 'border-zinc-100 dark:border-zinc-800' }}"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $title }}</flux:text>
                                    <span
                                        x-show="{{ $isUnread ? 'true' : 'false' }} || (typeof newIds !== 'undefined' && newIds.includes('{{ $notification->id }}'))"
                                        x-transition:enter="transition ease-out duration-200"
                                        x-transition:enter-start="opacity-0 scale-90"
                                        x-transition:enter-end="opacity-100 scale-100"
                                        x-transition:leave="transition ease-in duration-150"
                                        x-transition:leave-start="opacity-100"
                                        x-transition:leave-end="opacity-0"
                                        class="shrink-0 rounded-full bg-emerald-500 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white dark:bg-emerald-600"
                                    >
                                        {{ __('messages.new') }}
                                    </span>
                                </div>
                                <flux:text class="mt-0.5 line-clamp-2 text-xs text-zinc-600 dark:text-zinc-400">{{ $message }}</flux:text>
                            </div>
                            @if ($url)
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="arrow-top-right-on-square"
                                    :href="$url"
                                    wire:navigate
                                    wire:click="markAsRead('{{ $notification->id }}')"
                                />
                            @else
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    wire:click="markAsRead('{{ $notification->id }}')"
                                >
                                    {{ __('messages.mark_read') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="px-3 py-6 text-center">
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('messages.no_notifications') }}</flux:text>
                    </div>
                @endforelse
            </div>
            <div class="border-t border-zinc-100 p-2 dark:border-zinc-800">
                <flux:button variant="ghost" size="sm" class="w-full" :href="route('activity.index')" wire:navigate>
                    {{ __('messages.activity_view_all') }}
                </flux:button>
            </div>
        </flux:menu>
    </flux:dropdown>
</div>
