<?php

declare(strict_types=1);

namespace App\Livewire\Sidebar;

use App\Livewire\Sidebar\Concerns\RefreshesSidebarMetric;
use Livewire\Attributes\On;
use Livewire\Component;

class AutomationReviewIndicator extends Component
{
    use RefreshesSidebarMetric;

    public function mount(): void
    {
        $this->mountRefreshesSidebarMetric();
    }

    #[On('automation-run-updated')]
    public function onAutomationRunUpdated(): void
    {
        $this->refreshCount();
    }

    protected function sidebarMetricKey(): string
    {
        return 'automation_needs_review';
    }

    protected function canViewSidebarMetric(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function render()
    {
        return view('livewire.sidebar.automation-review-indicator');
    }
}
