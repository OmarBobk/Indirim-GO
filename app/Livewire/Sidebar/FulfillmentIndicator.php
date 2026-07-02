<?php

declare(strict_types=1);

namespace App\Livewire\Sidebar;

use App\Livewire\Sidebar\Concerns\RefreshesSidebarMetric;
use Livewire\Attributes\On;
use Livewire\Component;

class FulfillmentIndicator extends Component
{
    use RefreshesSidebarMetric;

    public function mount(): void
    {
        $this->mountRefreshesSidebarMetric();
    }

    #[On('fulfillment-list-updated')]
    public function onFulfillmentListUpdated(): void
    {
        $this->refreshCount();
    }

    protected function sidebarMetricKey(): string
    {
        return 'fulfillment_queue';
    }

    protected function canViewSidebarMetric(): bool
    {
        return auth()->user()?->can('view_fulfillments') ?? false;
    }

    public function render()
    {
        return view('livewire.sidebar.fulfillment-indicator');
    }
}
