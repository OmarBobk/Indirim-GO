<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBellDropdown extends Component
{
    public function getLatestNotificationsProperty(): Collection
    {
        $user = auth()->user();
        if ($user === null) {
            return collect();
        }

        return $user->notifications()->latest()->limit(5)->get();
    }

    public function getUnreadCountProperty(): int
    {
        $user = auth()->user();
        if ($user === null) {
            return 0;
        }

        return $user->unreadNotifications()->count();
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
    }

    #[On('customer-notifications-changed')]
    public function refreshAfterExternalChange(): void
    {
        // Recompute unread count / latest rows after Activity page mutations.
    }

    public function render(): View
    {
        return view('livewire.notification-bell-dropdown');
    }
}
