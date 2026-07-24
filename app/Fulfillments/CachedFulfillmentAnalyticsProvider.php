<?php

declare(strict_types=1);

namespace App\Fulfillments;

use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Read-heavy fulfillment analytics for admin dashboards (cached, bounded queries).
 */
class CachedFulfillmentAnalyticsProvider
{
    public const CACHE_KEY = 'fulfillment_analytics_dto_v1';

    public const TTL_SECONDS = 20;

    public const DISTRIBUTION_TOP_CLAIMERS = 24;

    public const DISTRIBUTION_ITEMS_PER_BUCKET = 8;

    public const DISTRIBUTION_MAX_FULFILLMENT_ROWS = 200;

    /**
     * @return array<string, mixed>
     */
    public function getAnalyticsDto(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addSeconds(self::TTL_SECONDS), fn (): array => $this->buildDto());
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDto(): array
    {
        $overview = $this->buildSystemOverview();
        $supervisorHealth = $this->buildSupervisorHealth();
        $distribution = $this->buildDistributionStats();

        return [
            'system_overview' => $overview,
            'supervisor_health' => $supervisorHealth,
            'distribution' => $distribution,
            'admin_alerts' => $this->buildAdminAlerts($overview, $supervisorHealth),
            'cached_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{queued: int, processing: int, completed: int, active_supervisors: int, load: string}
     */
    private function buildSystemOverview(): array
    {
        $queued = Fulfillment::query()
            ->where('status', FulfillmentStatus::Queued)
            ->count();
        $processing = Fulfillment::query()
            ->where('status', FulfillmentStatus::Processing)
            ->count();
        $completed = Fulfillment::query()
            ->where('status', FulfillmentStatus::Completed)
            ->count();
        $activeSupervisors = Fulfillment::query()
            ->where('status', FulfillmentStatus::Processing)
            ->whereNotNull('claimed_by')
            ->distinct('claimed_by')
            ->count('claimed_by');

        $load = $processing >= 20 ? 'high' : ($processing >= 8 ? 'medium' : 'normal');

        $browserQueued = Fulfillment::query()
            ->where('provider', 'like', 'browser:%')
            ->where('status', FulfillmentStatus::Queued)
            ->count();

        $browserRunning = \App\Models\FulfillmentAutomationRun::query()
            ->where('status', \App\Enums\FulfillmentAutomationRunStatus::Running)
            ->count();

        $browserNeedsReview = \App\Models\FulfillmentAutomationRun::query()
            ->where('status', \App\Enums\FulfillmentAutomationRunStatus::NeedsReview)
            ->count();

        return [
            'queued' => $queued,
            'processing' => $processing,
            'completed' => $completed,
            'active_supervisors' => $activeSupervisors,
            'load' => $load,
            'browser_queued' => $browserQueued,
            'browser_running' => $browserRunning,
            'browser_needs_review' => $browserNeedsReview,
        ];
    }

    /**
     * @return list<array{id: int, name: string, active_tasks: int, completed_tasks: int, failed_tasks: int, status: string, last_activity_at: ?string}>
     */
    private function buildSupervisorHealth(): array
    {
        $supervisors = User::query()
            ->where(function ($query): void {
                $query
                    ->permission('manage_fulfillments')
                    ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'admin'));
            })
            ->select(['id', 'name', 'username'])
            ->get();

        if ($supervisors->isEmpty()) {
            return [];
        }

        $ids = $supervisors->pluck('id')->all();

        $activeCounts = Fulfillment::query()
            ->selectRaw('claimed_by, COUNT(*) as active_tasks')
            ->where('status', FulfillmentStatus::Processing)
            ->whereIn('claimed_by', $ids)
            ->groupBy('claimed_by')
            ->pluck('active_tasks', 'claimed_by');

        $completedCounts = Fulfillment::query()
            ->selectRaw('claimed_by, COUNT(*) as completed_tasks')
            ->where('status', FulfillmentStatus::Completed)
            ->whereIn('claimed_by', $ids)
            ->groupBy('claimed_by')
            ->pluck('completed_tasks', 'claimed_by');

        $failedCounts = Fulfillment::query()
            ->selectRaw('claimed_by, COUNT(*) as failed_tasks')
            ->where('status', FulfillmentStatus::Failed)
            ->whereIn('claimed_by', $ids)
            ->groupBy('claimed_by')
            ->pluck('failed_tasks', 'claimed_by');

        $lastActivity = Fulfillment::query()
            ->selectRaw('claimed_by, MAX(updated_at) as last_activity_at')
            ->whereIn('claimed_by', $ids)
            ->groupBy('claimed_by')
            ->pluck('last_activity_at', 'claimed_by');

        return $supervisors
            ->map(function (User $supervisor) use ($activeCounts, $completedCounts, $failedCounts, $lastActivity): array {
                $activeTasks = (int) ($activeCounts[$supervisor->id] ?? 0);
                $status = $activeTasks >= 5 ? 'overloaded' : ($activeTasks >= 4 ? 'busy' : ($activeTasks > 0 ? 'active' : 'idle'));
                $last = $lastActivity[$supervisor->id] ?? null;

                return [
                    'id' => $supervisor->id,
                    'name' => $supervisor->name ?: $supervisor->username ?: ('#'.$supervisor->id),
                    'active_tasks' => $activeTasks,
                    'completed_tasks' => (int) ($completedCounts[$supervisor->id] ?? 0),
                    'failed_tasks' => (int) ($failedCounts[$supervisor->id] ?? 0),
                    'status' => $status,
                    'last_activity_at' => $last ? (string) $last : null,
                ];
            })
            ->sortByDesc('active_tasks')
            ->values()
            ->all();
    }

    /**
     * Bounded distribution: top claimers by active count, sample item names per claimer (no unbounded ->get()).
     *
     * @return list<array{claimer_name: string, count: int, items: list<string>}>
     */
    private function buildDistributionStats(): array
    {
        $topClaimers = Fulfillment::query()
            ->selectRaw('claimed_by, COUNT(*) as cnt')
            ->where('status', FulfillmentStatus::Processing)
            ->whereNotNull('claimed_by')
            ->groupBy('claimed_by')
            ->orderByDesc('cnt')
            ->limit(self::DISTRIBUTION_TOP_CLAIMERS)
            ->pluck('cnt', 'claimed_by');

        if ($topClaimers->isEmpty()) {
            return [];
        }

        $claimerIds = $topClaimers->keys()->all();

        $claimers = User::query()
            ->whereIn('id', $claimerIds)
            ->select(['id', 'name', 'username'])
            ->get()
            ->keyBy('id');

        $fulfillments = Fulfillment::query()
            ->where('status', FulfillmentStatus::Processing)
            ->whereIn('claimed_by', $claimerIds)
            ->with(['orderItem:id,order_id,name'])
            ->orderByDesc('updated_at')
            ->limit(self::DISTRIBUTION_MAX_FULFILLMENT_ROWS)
            ->get()
            ->groupBy('claimed_by');

        $buckets = [];

        foreach ($topClaimers as $claimedBy => $count) {
            $claimedBy = (int) $claimedBy;
            $user = $claimers->get($claimedBy);
            $claimerName = $user ? ($user->name ?: $user->username ?: ('#'.$claimedBy)) : __('messages.unknown_user');
            $items = ($fulfillments->get($claimedBy, collect()))
                ->take(self::DISTRIBUTION_ITEMS_PER_BUCKET)
                ->map(fn (Fulfillment $f): string => $f->orderItem?->name ?? __('messages.unknown_item'))
                ->values()
                ->all();

            $buckets[] = [
                'claimer_name' => $claimerName,
                'count' => (int) $count,
                'items' => $items,
            ];
        }

        usort($buckets, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $buckets;
    }

    /**
     * @param  array{queued: int, processing: int, completed: int, active_supervisors: int, load: string}  $overview
     * @param  list<array{id: int, name: string, active_tasks: int, completed_tasks: int, failed_tasks: int, status: string, last_activity_at: ?string}>  $supervisorHealth
     * @return list<array{level: string, title: string, message: string}>
     */
    private function buildAdminAlerts(array $overview, array $supervisorHealth): array
    {
        $alerts = collect();
        $queued = (int) $overview['queued'];
        $processing = (int) $overview['processing'];
        $activeSupervisors = (int) $overview['active_supervisors'];
        $overloadedSupervisors = collect($supervisorHealth)->where('status', 'overloaded')->count();

        if ($queued >= 25 && $processing <= 5) {
            $alerts->push([
                'level' => 'amber',
                'title' => 'Queue backlog rising',
                'message' => 'Queue is growing faster than processing throughput. Check assignments and blockers.',
            ]);
        }

        if ($overloadedSupervisors > 0) {
            $alerts->push([
                'level' => 'red',
                'title' => 'Supervisor overload detected',
                'message' => $overloadedSupervisors.' supervisor(s) are at 5/5 active tasks.',
            ]);
        }

        if ($activeSupervisors === 0 && $queued > 0) {
            $alerts->push([
                'level' => 'zinc',
                'title' => 'No active supervisors',
                'message' => 'Tasks are queued but no supervisor is currently processing.',
            ]);
        }

        return $alerts->take(3)->values()->all();
    }
}
