<?php

declare(strict_types=1);

namespace App\Livewire\Sidebar;

use App\Actions\Dashboard\GetAdminSidebarCounts;
use Livewire\Attributes\On;
use Livewire\Component;

class DashboardOpsIndicator extends Component
{
    public int $count = 0;

    public function mount(): void
    {
        $this->refreshCount();
    }

    #[On('admin-ops-inbox-updated')]
    public function refreshCount(): void
    {
        if (! auth()->check() || ! auth()->user()?->can('view_dashboard')) {
            $this->count = 0;

            return;
        }

        $user = auth()->user();
        abort_unless($user !== null, 403);

        $this->count = (int) app(GetAdminSidebarCounts::class)->handle($user)['total_exceptions'];
    }

    public function render()
    {
        return view('livewire.sidebar.dashboard-ops-indicator');
    }
}
