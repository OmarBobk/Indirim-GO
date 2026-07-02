<?php

declare(strict_types=1);

namespace App\Livewire\Sidebar;

use App\Actions\Dashboard\GetAdminSidebarCounts;
use Livewire\Attributes\On;
use Livewire\Component;

class SidebarToggleBadge extends Component
{
    public bool $hasBadge = false;

    public function mount(): void
    {
        $this->refreshBadge();
    }

    #[On('admin-ops-inbox-updated')]
    #[On('fulfillment-list-updated')]
    #[On('topup-list-updated')]
    #[On('bug-inbox-updated')]
    #[On('notification-received')]
    public function refreshBadge(): void
    {
        if (! auth()->check()) {
            $this->hasBadge = false;

            return;
        }

        $user = auth()->user();
        abort_unless($user !== null, 403);

        $opsAttention = $user->can('view_dashboard')
            && app(GetAdminSidebarCounts::class)->handle($user)['total_exceptions'] > 0;

        $notificationsBadge = $user->unreadNotifications()->exists();

        $this->hasBadge = $opsAttention || $notificationsBadge;
    }

    public function render()
    {
        return view('livewire.sidebar.sidebar-toggle-badge');
    }
}
