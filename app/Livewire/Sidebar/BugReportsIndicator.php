<?php

declare(strict_types=1);

namespace App\Livewire\Sidebar;

use App\Livewire\Sidebar\Concerns\RefreshesSidebarMetric;
use Livewire\Attributes\On;
use Livewire\Component;

class BugReportsIndicator extends Component
{
    use RefreshesSidebarMetric;

    public function mount(): void
    {
        $this->mountRefreshesSidebarMetric();
    }

    #[On('bug-inbox-updated')]
    public function onBugInboxUpdated(): void
    {
        $this->refreshCount();
    }

    protected function sidebarMetricKey(): string
    {
        return 'open_bugs';
    }

    protected function canViewSidebarMetric(): bool
    {
        return auth()->user()?->can('manage_bugs') ?? false;
    }

    public function render()
    {
        return view('livewire.sidebar.bug-reports-indicator');
    }
}
