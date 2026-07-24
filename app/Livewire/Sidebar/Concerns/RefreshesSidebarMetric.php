<?php

declare(strict_types=1);

namespace App\Livewire\Sidebar\Concerns;

use App\Actions\Dashboard\GetAdminSidebarCounts;
use Livewire\Attributes\On;

trait RefreshesSidebarMetric
{
    public int $count = 0;

    abstract protected function sidebarMetricKey(): string;

    abstract protected function canViewSidebarMetric(): bool;

    public function mountRefreshesSidebarMetric(): void
    {
        $this->refreshCount();
    }

    #[On('admin-ops-inbox-updated')]
    public function refreshCount(): void
    {
        if (! auth()->check() || ! $this->canViewSidebarMetric()) {
            $this->count = 0;

            return;
        }

        $user = auth()->user();
        abort_unless($user !== null, 403);

        $this->count = app(GetAdminSidebarCounts::class)->metric(
            $user,
            $this->sidebarMetricKey(),
        );
    }
}
