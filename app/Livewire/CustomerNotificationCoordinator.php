<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Support\StorefrontShell;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Single storefront unread-count owner: one recount per invalidation, shared to shell surfaces.
 */
class CustomerNotificationCoordinator extends Component
{
    public int $unreadCount = 0;

    public function mount(): void
    {
        $this->syncUnreadCount();
    }

    #[On('customer-activity-invalidate')]
    public function refreshFromInvalidation(): void
    {
        $this->syncUnreadCount();
    }

    #[On('customer-notifications-changed')]
    public function refreshFromLocalMutation(): void
    {
        $this->syncUnreadCount();
    }

    public function render(): View
    {
        return view('livewire.customer-notification-coordinator');
    }

    private function syncUnreadCount(): void
    {
        $this->unreadCount = StorefrontShell::unreadNotificationCount();
        $this->dispatch('customer-unread-count-updated', count: $this->unreadCount);
    }
}
