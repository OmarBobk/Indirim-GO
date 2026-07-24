<?php

declare(strict_types=1);

use App\Actions\Dashboard\GetAdminDailyStats;
use App\Actions\Dashboard\GetAdminOpsInbox;
use App\Enums\AdminDashboardVariant;
use App\Fulfillments\CachedFulfillmentAnalyticsProvider;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

new class extends Component
{
    use Toastable;

    /** @var array<string, mixed> */
    public array $inbox = [];

    /** @var array<string, mixed> */
    public array $dailyStats = [];

    public string $statsRange = '7d';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('view_dashboard'), 403);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $this->reloadInbox($user);
    }

    #[On('admin-ops-inbox-updated')]
    public function onOpsInboxUpdated(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $this->reloadInbox($user);
    }

    public function setStatsRange(string $range): void
    {
        if (! in_array($range, ['today', '7d', 'this_month'], true)) {
            return;
        }

        $this->statsRange = $range;
        $this->loadDailyStats();
    }

    public function refreshDashboard(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        app(GetAdminDailyStats::class)->forgetCache();
        app(CachedFulfillmentAnalyticsProvider::class)->forget();

        $this->reloadInbox($user);
        $this->success(__('messages.admin_ops_refreshed'));
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.dashboard'));
    }

    private function reloadInbox(User $user): void
    {
        $this->inbox = app(GetAdminOpsInbox::class)->handle($user);
        $this->loadDailyStats();
    }

    private function loadDailyStats(): void
    {
        if (($this->inbox['variant'] ?? '') !== AdminDashboardVariant::Full->value) {
            $this->dailyStats = [];

            return;
        }

        $this->dailyStats = app(GetAdminDailyStats::class)->handle($this->statsRange);
    }
};
?>

<div class="admin-fulfillments flex min-h-full min-w-0 max-w-full flex-1 flex-col gap-6 overflow-x-hidden">
    <div class="cf-ops-toolbar cf-reveal flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
            <span class="cf-live-dot" aria-hidden="true"></span>
            <flux:text class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                {{ __('messages.admin_ops_live_sync') }}
            </flux:text>
        </div>
        <flux:button
            size="sm"
            variant="ghost"
            icon="arrow-path"
            wire:click="refreshDashboard"
            wire:loading.attr="disabled"
            wire:target="refreshDashboard"
            data-test="refresh-dashboard"
            class="transition-opacity"
        >
            <span wire:loading.remove wire:target="refreshDashboard">{{ __('messages.admin_ops_refresh') }}</span>
            <span wire:loading wire:target="refreshDashboard">{{ __('messages.admin_ops_refreshing') }}</span>
        </flux:button>
    </div>

    <x-admin.ops-dashboard :inbox="$inbox" />

    @if (($inbox['variant'] ?? '') === 'full' && $dailyStats !== [])
        <x-admin.daily-stats
            :stats="$dailyStats"
            :active-range="$statsRange"
        />
    @endif
</div>
