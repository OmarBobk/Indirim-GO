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

    public function mount(): void
    {
        $this->syncUnreadCountFromDatabase();
    }

    public function getLatestNotificationsProperty(): Collection
    {
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
            $notification->markAsRead();
        }

        $this->syncUnreadCountFromDatabase();
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
        $this->dispatch('customer-notifications-changed');
    }

    #[On('customer-notifications-changed')]
    public function refreshAfterExternalChange(): void
    {
        $this->syncUnreadCountFromDatabase();
    }

    #[On('customer-activity-invalidate')]
    public function refreshAfterInvalidation(): void
    {
        // Recompute latest notification rows; unread count comes from coordinator.
    }

    #[On('customer-unread-count-updated')]
    public function syncUnreadCountFromCoordinator(int $count): void
    {
        $this->unreadCount = $count;
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
