<?php

declare(strict_types=1);

namespace App\Livewire\Sidebar;

use App\Livewire\Sidebar\Concerns\RefreshesSidebarMetric;
use Livewire\Component;

class PayoutIndicator extends Component
{
    use RefreshesSidebarMetric;

    public function mount(): void
    {
        $this->mountRefreshesSidebarMetric();
    }

    protected function sidebarMetricKey(): string
    {
        return 'pending_payouts';
    }

    protected function canViewSidebarMetric(): bool
    {
        return auth()->user()?->can('manage_settlements') ?? false;
    }

    public function render()
    {
        return view('livewire.sidebar.payout-indicator');
    }
}
