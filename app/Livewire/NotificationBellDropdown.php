<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBellDropdown extends Component
{
    public int $unreadCount = 0;

    /**
     * When false, latest-five is not queried (closed dropdown).
     */
    public bool $listLoaded = false;

    public function mount(): void
    {
        $this->syncUnreadCountFromDatabase();
    }

    public function ensureListLoaded(): void
    {
        $this->listLoaded = true;
    }

    public function getLatestNotificationsProperty(): Collection
    {
        if (! $this->listLoaded) {
            return collect();
        }

        $user = auth()->user();
        if ($user === null) {
            return collect();
        }

        return $user->notifications()->latest()->limit(5)->get();
    }

    public function markAsRead(string $id): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }
        $notification = $user->notifications()->whereKey($id)->first();
        if ($notification !== null) {
            $wasUnread = $notification->read_at === null;
            $notification->markAsRead();
            if ($wasUnread) {
                $this->unreadCount = max(0, $this->unreadCount - 1);
            }
        }

        $this->listLoaded = true;
        $this->dispatch('customer-notifications-changed');
    }

    /**
     * Explicit mark-all only — never invoked merely by opening the bell.
     */
    public function markAsReadOnOpen(): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }
        $user->unreadNotifications()->update(['read_at' => now()]);
        $this->unreadCount = 0;
        $this->listLoaded = true;
        $this->dispatch('customer-notifications-changed');
    }

    #[On('customer-notifications-changed')]
    public function refreshAfterExternalChange(): void
    {
        // Unread count comes from the coordinator. Refresh list only if already open.
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[On('customer-activity-invalidate')]
    public function refreshAfterInvalidation(array $payload = []): void
    {
        $source = is_string($payload['source'] ?? null) ? $payload['source'] : null;
        $isReconcile = (bool) ($payload['isReconcile'] ?? false);

        if (! $this->listLoaded) {
            $this->skipRender();

            return;
        }

        if ($source !== 'notification' || $isReconcile) {
            $this->skipRender();
        }
    }

    #[On('customer-unread-count-updated')]
    public function syncUnreadCountFromCoordinator(int $count): void
    {
        $this->unreadCount = $count;

        if (! $this->listLoaded) {
            // Badge is Alpine-driven; avoid fetching latest-five while closed.
            $this->skipRender();
        }
    }

    public function render(): View
    {
        return view('livewire.notification-bell-dropdown');
    }

    private function syncUnreadCountFromDatabase(): void
    {
        $user = auth()->user();
        if ($user === null) {
            $this->unreadCount = 0;

            return;
        }

        $this->unreadCount = $user->unreadNotifications()->count();
    }
}
