<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Fulfillments\CancelFulfillmentAutomationRun;
use App\Actions\Fulfillments\ResolveFulfillmentAutomationReview;
use App\Actions\Fulfillments\RetryFulfillmentAutomation;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Models\FulfillmentAutomationRun;
use App\Models\WebsiteSetting;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;
use Throwable;

#[Layout('layouts.app')]
final class AutomationMonitor extends Component
{
    use Toastable;
    use WithPagination;

    #[Url]
    public string $statusFilter = 'all';

    #[Url]
    public string $search = '';

    public ?string $selectedRunUuid = null;

    public bool $automationEnabled = true;

    public string $wasimUsername = '';

    public string $wasimPassword = '';

    public bool $wasimPasswordConfigured = false;

    public bool $wasimCredentialsFromEnv = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $settings = WebsiteSetting::instance();

        $this->automationEnabled = WebsiteSetting::getAutomationEnabled();
        $this->wasimUsername = (string) ($settings->wasim_automation_username ?? '');
        $this->wasimPasswordConfigured = $settings->hasWasimAutomationPassword();
        $this->wasimCredentialsFromEnv = $this->envWasimCredentialsConfigured();
    }

    public function saveWasimCredentials(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $this->validate([
            'wasimUsername' => ['required', 'string', 'max:255'],
            'wasimPassword' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'wasim_automation_username' => trim($this->wasimUsername),
        ];

        if ($this->wasimPassword !== '') {
            $payload['wasim_automation_password'] = $this->wasimPassword;
        }

        WebsiteSetting::instance()->update($payload);

        $this->wasimPassword = '';
        $settings = WebsiteSetting::instance()->refresh();
        $this->wasimPasswordConfigured = $settings->hasWasimAutomationPassword();
        $this->wasimCredentialsFromEnv = $this->envWasimCredentialsConfigured();

        $this->success(__('messages.automation_wasim_credentials_saved'));
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[On('automation-run-updated')]
    public function refreshFromBroadcast(array $payload = []): void
    {
        unset($this->stats, $this->runs, $this->runGroups, $this->selectedRun, $this->selectedRunIsGlobalLatest);
    }

    public function selectRun(string $uuid, bool $focusScreenshots = false): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $this->selectedRunUuid = $uuid;
        $this->dispatch('open-panel', focusScreenshots: $focusScreenshots);
    }

    public function closePanel(): void
    {
        $this->selectedRunUuid = null;
        $this->dispatch('close-panel');
    }

    public function toggleAutomation(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $next = ! $this->automationEnabled;

        try {
            WebsiteSetting::instance()->update(['automation_enabled' => $next]);
            $this->automationEnabled = $next;
            $this->success(__('messages.automation_settings_saved'));
        } catch (Throwable) {
            $this->automationEnabled = WebsiteSetting::getAutomationEnabled();
            $this->error(__('messages.automation_toggle_failed'));
        }
    }

    public function retryRun(string $uuid): void
    {
        $run = $this->findAuthorizedRun($uuid);
        $fulfillment = $run->fulfillment;

        if ($fulfillment === null) {
            return;
        }

        $this->authorize('update', $fulfillment);

        app(RetryFulfillmentAutomation::class)->handle($fulfillment, auth()->id());

        $this->success(__('messages.fulfillment_automation_retry_queued'));
        $this->closePanel();
    }

    public function cancelRun(string $uuid): void
    {
        $run = $this->findAuthorizedRun($uuid);
        $fulfillment = $run->fulfillment;

        if ($fulfillment === null || ! $run->isActive()) {
            return;
        }

        $this->authorize('update', $fulfillment);

        app(CancelFulfillmentAutomationRun::class)->handle($fulfillment, 'admin_cancel');

        $this->success(__('messages.automation_run_cancelled'));
        $this->closePanel();
    }

    public function markReviewSucceeded(string $uuid): void
    {
        $run = $this->findAuthorizedRun($uuid);
        $fulfillment = $run->fulfillment;

        if ($fulfillment !== null) {
            $this->authorize('update', $fulfillment);
        }

        app(ResolveFulfillmentAutomationReview::class)->handle($run, 'succeeded', auth()->id());

        $this->success(__('messages.automation_review_succeeded'));
        $this->closePanel();
    }

    public function markReviewFailed(string $uuid): void
    {
        $run = $this->findAuthorizedRun($uuid);
        $fulfillment = $run->fulfillment;

        if ($fulfillment !== null) {
            $this->authorize('update', $fulfillment);
        }

        app(ResolveFulfillmentAutomationReview::class)->handle($run, 'failed', auth()->id());

        $this->success(__('messages.automation_review_failed'));
        $this->closePanel();
    }

    /**
     * @return array{
     *     running_count: int,
     *     needs_review_count: int,
     *     failed_today_count: int,
     *     worker_health: array{state: string, label: string}
     * }
     */
    #[Computed]
    public function stats(): array
    {
        $today = Carbon::today();

        $runningCount = FulfillmentAutomationRun::query()->active()->count();

        $needsReviewCount = FulfillmentAutomationRun::query()
            ->where('status', FulfillmentAutomationRunStatus::NeedsReview)
            ->count();

        $failedTodayCount = FulfillmentAutomationRun::query()
            ->where('status', FulfillmentAutomationRunStatus::Failed)
            ->where('updated_at', '>=', $today)
            ->count();

        return [
            'running_count' => $runningCount,
            'needs_review_count' => $needsReviewCount,
            'failed_today_count' => $failedTodayCount,
            'worker_health' => $this->resolveWorkerHealth(),
        ];
    }

    #[Computed]
    public function runs(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return FulfillmentAutomationRun::query()
            ->with([
                'fulfillment.order:id,order_number',
                'fulfillment.orderItem.package:id,name',
            ])
            ->when($this->statusFilter === 'running', fn (Builder $query): Builder => $query->active())
            ->when(
                $this->statusFilter !== 'all' && $this->statusFilter !== 'running',
                fn (Builder $query): Builder => $query->where('status', $this->statusFilter),
            )
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';

                $query->where(function (Builder $sub) use ($like): void {
                    $sub->where('uuid', 'like', $like)
                        ->orWhere('supplier_key', 'like', $like)
                        ->orWhere('error_code', 'like', $like)
                        ->orWhereHas('fulfillment', function (Builder $fulfillmentQuery) use ($like): void {
                            $fulfillmentQuery
                                ->where('id', 'like', $like)
                                ->orWhereHas('order', fn (Builder $orderQuery): Builder => $orderQuery->where('order_number', 'like', $like));
                        });
                });
            })
            ->latest('created_at')
            ->paginate(25);
    }

    /**
     * Runs on the current page grouped by fulfillment (newest attempt shown first).
     *
     * @return Collection<int, object{
     *     fulfillment_id: int,
     *     primary: FulfillmentAutomationRun,
     *     others: Collection<int, FulfillmentAutomationRun>,
     *     count: int,
     *     global_latest_uuid: string|null
     * }>
     */
    #[Computed]
    public function runGroups(): Collection
    {
        $runs = $this->runs->getCollection();

        if ($runs->isEmpty()) {
            return collect();
        }

        /** @var list<int> $fulfillmentIds */
        $fulfillmentIds = $runs->pluck('fulfillment_id')
            ->unique()
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $latestUuidByFulfillment = $this->latestRunUuidByFulfillmentIds($fulfillmentIds);

        return $runs->groupBy('fulfillment_id')
            ->map(function (Collection $groupRuns, int|string $fulfillmentId) use ($latestUuidByFulfillment): object {
                $sorted = $groupRuns
                    ->sortByDesc(fn (FulfillmentAutomationRun $run): CarbonInterface => $run->created_at)
                    ->values();

                $fulfillmentId = (int) $fulfillmentId;

                return (object) [
                    'fulfillment_id' => $fulfillmentId,
                    'primary' => $sorted->first(),
                    'others' => $sorted->slice(1)->values(),
                    'count' => $sorted->count(),
                    'global_latest_uuid' => $latestUuidByFulfillment[$fulfillmentId] ?? null,
                ];
            })
            ->sortByDesc(fn (object $group): CarbonInterface => $group->primary->created_at)
            ->values();
    }

    #[Computed]
    public function selectedRunIsGlobalLatest(): bool
    {
        $run = $this->selectedRun;

        if ($run === null) {
            return false;
        }

        return $this->isGlobalLatestRun($run, $this->latestRunUuidForFulfillment($run->fulfillment_id));
    }

    public function isGlobalLatestRun(FulfillmentAutomationRun $run, ?string $globalLatestUuid): bool
    {
        return $globalLatestUuid !== null && $run->uuid === $globalLatestUuid;
    }

    public function latestRunUuidForFulfillment(int $fulfillmentId): ?string
    {
        return $this->latestRunUuidByFulfillmentIds([$fulfillmentId])[$fulfillmentId] ?? null;
    }

    #[Computed]
    public function selectedRun(): ?FulfillmentAutomationRun
    {
        if ($this->selectedRunUuid === null || $this->selectedRunUuid === '') {
            return null;
        }

        return FulfillmentAutomationRun::query()
            ->with([
                'fulfillment.order:id,order_number',
                'fulfillment.orderItem.package:id,name',
            ])
            ->where('uuid', $this->selectedRunUuid)
            ->first();
    }

    public function runDurationLabel(FulfillmentAutomationRun $run): string
    {
        if ($run->finished_at === null) {
            return '—';
        }

        $start = $run->started_at ?? $run->dispatched_at ?? $run->created_at;

        if ($start === null) {
            return '—';
        }

        $seconds = max(0, (int) $start->diffInSeconds($run->finished_at));

        if ($seconds < 60) {
            return $seconds.'s';
        }

        return intdiv($seconds, 60).'m '.($seconds % 60).'s';
    }

    public function runStartedAt(FulfillmentAutomationRun $run): ?CarbonInterface
    {
        return $run->started_at ?? $run->dispatched_at ?? $run->created_at;
    }

    public function supplierLabel(FulfillmentAutomationRun $run): string
    {
        if ($run->supplier_key !== '') {
            return $run->supplier_key;
        }

        $provider = $run->fulfillment?->provider ?? '';

        return str_starts_with($provider, 'browser:')
            ? substr($provider, strlen('browser:'))
            : ($provider !== '' ? $provider : '—');
    }

    public function statusBadgeClass(FulfillmentAutomationRunStatus $status): string
    {
        return match ($status) {
            FulfillmentAutomationRunStatus::Running,
            FulfillmentAutomationRunStatus::Dispatched => 'border-blue-300 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950/50 dark:text-blue-200',
            FulfillmentAutomationRunStatus::Reserved => 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200',
            FulfillmentAutomationRunStatus::Succeeded => 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200',
            FulfillmentAutomationRunStatus::Failed => 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/50 dark:text-red-200',
            FulfillmentAutomationRunStatus::NeedsReview => 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200',
            FulfillmentAutomationRunStatus::Cancelled => 'border-zinc-300 bg-zinc-50 text-zinc-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',
        };
    }

    public function healthCardClass(string $state): string
    {
        return match ($state) {
            'healthy' => 'border-emerald-200 ring-emerald-100 bg-emerald-50/80 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:ring-emerald-900/40',
            'slow' => 'border-amber-200 ring-amber-100 bg-amber-50/80 dark:border-amber-900/60 dark:bg-amber-950/40 dark:ring-amber-900/40',
            default => 'border-red-200 ring-red-100 bg-red-50/80 dark:border-red-900/60 dark:bg-red-950/40 dark:ring-red-900/40',
        };
    }

    /**
     * Normalize automation log lines for the detail panel: sequential ids first, sorted by step order.
     *
     * @return list<array{id: int, step: string, level: string, message: string, at: string|null, ms: int|null}>
     */
    public function formattedLogExcerpt(FulfillmentAutomationRun $run): array
    {
        $raw = $run->log_excerpt;

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $lines = [];

        foreach (array_values($raw) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $lines[] = [
                'line' => $line,
                'fallback_order' => $index,
            ];
        }

        usort($lines, function (array $a, array $b): int {
            $lineA = $a['line'];
            $lineB = $b['line'];

            $idA = isset($lineA['id']) && is_numeric($lineA['id']) ? (int) $lineA['id'] : null;
            $idB = isset($lineB['id']) && is_numeric($lineB['id']) ? (int) $lineB['id'] : null;

            if ($idA !== null && $idB !== null && $idA !== $idB) {
                return $idA <=> $idB;
            }

            if ($idA !== null && $idB === null) {
                return -1;
            }

            if ($idA === null && $idB !== null) {
                return 1;
            }

            $atA = is_string($lineA['at'] ?? null) ? strtotime($lineA['at']) : false;
            $atB = is_string($lineB['at'] ?? null) ? strtotime($lineB['at']) : false;

            if ($atA !== false && $atB !== false && $atA !== $atB) {
                return $atA <=> $atB;
            }

            return $a['fallback_order'] <=> $b['fallback_order'];
        });

        $formatted = [];

        foreach ($lines as $position => $entry) {
            $line = $entry['line'];

            $formatted[] = [
                'id' => $position + 1,
                'step' => (string) ($line['step'] ?? 'unknown'),
                'level' => (string) ($line['level'] ?? 'info'),
                'message' => (string) ($line['message'] ?? ''),
                'at' => is_string($line['at'] ?? null) ? $line['at'] : null,
                'ms' => isset($line['ms']) && is_numeric($line['ms']) ? (int) $line['ms'] : null,
            ];
        }

        return $formatted;
    }

    /**
     * @return list<array{src: string, alt: string, label: string}>
     */
    public function artifactItemsForRun(FulfillmentAutomationRun $run): array
    {
        $items = [];

        foreach ($run->artifactPaths() as $index => $path) {
            $items[] = [
                'src' => $run->artifactShowUrl($index, absolute: false),
                'alt' => basename($path),
                'label' => Str::of(basename($path))->beforeLast('.')->replace(['-', '_'], ' ')->title()->toString(),
            ];
        }

        return $items;
    }

    public function render(): View
    {
        return view('livewire.admin.automation-monitor');
    }

    /**
     * @param  list<int>  $fulfillmentIds
     * @return array<int, string>
     */
    private function latestRunUuidByFulfillmentIds(array $fulfillmentIds): array
    {
        if ($fulfillmentIds === []) {
            return [];
        }

        return FulfillmentAutomationRun::query()
            ->whereIn('fulfillment_id', $fulfillmentIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['fulfillment_id', 'uuid'])
            ->unique('fulfillment_id')
            ->mapWithKeys(fn (FulfillmentAutomationRun $run): array => [(int) $run->fulfillment_id => $run->uuid])
            ->all();
    }

    private function findAuthorizedRun(string $uuid): FulfillmentAutomationRun
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $run = FulfillmentAutomationRun::query()
            ->with('fulfillment')
            ->where('uuid', $uuid)
            ->firstOrFail();

        $this->authorize('view', $run);

        return $run;
    }

    /**
     * @return array{state: string, label: string}
     */
    private function envWasimCredentialsConfigured(): bool
    {
        return filled(config('fulfillment_automation.suppliers.wasim.credentials.username'))
            && filled(config('fulfillment_automation.suppliers.wasim.credentials.password'));
    }

    private function resolveWorkerHealth(): array
    {
        $hasRunsToday = FulfillmentAutomationRun::query()
            ->where(function (Builder $query): void {
                $query->whereDate('dispatched_at', today())
                    ->orWhereDate('created_at', today());
            })
            ->exists();

        if (! $hasRunsToday) {
            return [
                'state' => 'no_signal',
                'label' => __('messages.automation_worker_no_signal'),
            ];
        }

        $lastDispatched = FulfillmentAutomationRun::query()
            ->whereNotNull('dispatched_at')
            ->latest('dispatched_at')
            ->value('dispatched_at');

        if ($lastDispatched === null) {
            return [
                'state' => 'no_signal',
                'label' => __('messages.automation_worker_no_signal'),
            ];
        }

        $minutes = Carbon::parse($lastDispatched)->diffInMinutes(now());

        if ($minutes < 3) {
            return [
                'state' => 'healthy',
                'label' => __('messages.automation_worker_healthy'),
            ];
        }

        if ($minutes <= 10) {
            return [
                'state' => 'slow',
                'label' => __('messages.automation_worker_slow'),
            ];
        }

        return [
            'state' => 'no_signal',
            'label' => __('messages.automation_worker_no_signal'),
        ];
    }
}
