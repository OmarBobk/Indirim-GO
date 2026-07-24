<?php

declare(strict_types=1);

namespace App\Livewire\Sidebar;

use App\Livewire\Sidebar\Concerns\RefreshesSidebarMetric;
use Livewire\Component;

class RefundIndicator extends Component
{
    use RefreshesSidebarMetric;

    public function mount(): void
    {
        $this->mountRefreshesSidebarMetric();
    }

    protected function sidebarMetricKey(): string
    {
        return 'pending_refunds';
    }

    protected function canViewSidebarMetric(): bool
    {
        return auth()->user()?->can('view_refunds') ?? false;
    }

    public function render()
    {
        return view('livewire.sidebar.refund-indicator');
    }
}
