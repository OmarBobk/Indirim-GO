<?php

declare(strict_types=1);

namespace App\Livewire\Sidebar;

use App\Livewire\Sidebar\Concerns\RefreshesSidebarMetric;
use Livewire\Attributes\On;
use Livewire\Component;

class TopupIndicator extends Component
{
    use RefreshesSidebarMetric;

    public function mount(): void
    {
        $this->mountRefreshesSidebarMetric();
    }

    #[On('topup-list-updated')]
    public function onTopupListUpdated(): void
    {
        $this->refreshCount();
    }

    protected function sidebarMetricKey(): string
    {
        return 'pending_topups';
    }

    protected function canViewSidebarMetric(): bool
    {
        return auth()->user()?->can('manage_topups') ?? false;
    }

    public function render()
    {
        return view('livewire.sidebar.topup-indicator');
    }
}
