<?php

declare(strict_types=1);

namespace App\Livewire\Sidebar;

use App\Livewire\Sidebar\Concerns\RefreshesSidebarMetric;
use Livewire\Component;

class ClawbackIndicator extends Component
{
    use RefreshesSidebarMetric;

    public function mount(): void
    {
        $this->mountRefreshesSidebarMetric();
    }

    protected function sidebarMetricKey(): string
    {
        return 'clawback_action_required_total';
    }

    protected function canViewSidebarMetric(): bool
    {
        return auth()->user()?->can('view_commission_clawbacks') ?? false;
    }

    public function render()
    {
        return view('livewire.sidebar.clawback-indicator');
    }
}
