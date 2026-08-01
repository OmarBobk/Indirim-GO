<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Enums\AdminDashboardVariant;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\PayoutRequestStatus;
use App\Enums\TopupRequestStatus;
use App\Enums\WalletTransactionType;
use App\Fulfillments\CachedFulfillmentAnalyticsProvider;
use App\Models\Bug;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class GetAdminOpsInbox
{
    public function __construct(
        private CachedFulfillmentAnalyticsProvider $analytics,
        private ResolveAdminDashboardVariant $resolveVariant,
        private FormatOpsQueueAge $formatQueueAge,
        private GetAdminExceptionCounts $exceptionCounts,
        private GetAdminSidebarCounts $sidebarCounts,
    ) {}

    /**
     * @return array{
     *     variant: string,
     *     intro: string,
     *     exception_cards: list<array{key: string, label: string, count: int, href: string, severity: string, icon: string, age_label?: string, age_severity?: string}>,
     *     queue_health: ?array{queued: int, processing: int, completed: int, active_supervisors: int, load: string, browser_needs_review: int},
     *     admin_alerts: list<array{level: string, title: string, message: string}>,
     *     recent_pending_refunds: list<array{id: int, amount: float, order_number: ?string, user_name: string, created_at: ?string, href: string}>,
     *     recent_pending_topups: list<array{id: int, amount: float, user_name: string, created_at: ?string, href: string}>,
     *     recent_failed_fulfillments: list<array{id: int, order_number: ?string, product_name: string, last_error: ?string, href: string}>,
     *     recent_attention_orders: list<array{id: int, order_number: string, user_name: string, status: string, failed_count: int, created_at: ?string, href: string}>,
     *     recent_orders: list<array{id: int, order_number: string, user_name: string, status: string, total: float, created_at: ?string, href: string}>,
     *     actionable_exception_total: int,
     *     all_clear: bool
     * }
     */
    public function handle(User $user): array
    {
        $variant = $this->resolveVariant->handle($user);
        $exceptionCards = $this->filterCardsForVariant($this->exceptionCards($user), $variant);
        $actionableTotal = $this->sidebarCounts->totalExceptionsForVariant(
            $this->exceptionCounts->handle($user),
            $variant,
        );

        $showQueueHealth = in_array($variant, [AdminDashboardVariant::Full, AdminDashboardVariant::Fulfillment], true)
            && $user->can('view_fulfillments');

        $analyticsDto = $showQueueHealth ? $this->analytics->getAnalyticsDto() : null;

        return [
            'variant' => $variant->value,
            'intro' => $this->introForVariant($variant),
            'exception_cards' => $exceptionCards,
            'queue_health' => $analyticsDto !== null ? $this->queueHealthFromOverview($analyticsDto['system_overview']) : null,
            'admin_alerts' => $analyticsDto['admin_alerts'] ?? [],
            'recent_pending_refunds' => $this->shouldLoadPendingRefunds($variant, $user) ? $this->recentPendingRefunds() : [],
            'recent_pending_topups' => $this->shouldLoadPendingTopups($variant, $user) ? $this->recentPendingTopups() : [],
            'recent_failed_fulfillments' => $this->shouldLoadFailedFulfillments($variant, $user) ? $this->recentFailedFulfillments() : [],
            'recent_attention_orders' => $this->shouldLoadAttentionOrders($variant, $user) ? $this->recentAttentionOrders() : [],
            'recent_orders' => $this->shouldLoadRecentOrders($variant, $user) ? $this->recentOrders() : [],
            'actionable_exception_total' => $actionableTotal,
            'all_clear' => $actionableTotal === 0,
        ];
    }

    private function introForVariant(AdminDashboardVariant $variant): string
    {
        return match ($variant) {
            AdminDashboardVariant::Finance => __('messages.admin_ops_intro_finance'),
            AdminDashboardVariant::Fulfillment => __('messages.admin_ops_intro_fulfillment'),
            AdminDashboardVariant::Orders => __('messages.admin_ops_intro_orders'),
            AdminDashboardVariant::Full => __('messages.admin_ops_intro'),
        };
    }

    private function shouldLoadPendingRefunds(AdminDashboardVariant $variant, User $user): bool
    {
        return $user->can('view_refunds')
            && in_array($variant, [AdminDashboardVariant::Full, AdminDashboardVariant::Finance], true);
    }

    private function shouldLoadPendingTopups(AdminDashboardVariant $variant, User $user): bool
    {
        return $user->can('manage_topups') && $variant === AdminDashboardVariant::Finance;
    }

    private function shouldLoadFailedFulfillments(AdminDashboardVariant $variant, User $user): bool
    {
        return $user->can('view_fulfillments') && $variant === AdminDashboardVariant::Fulfillment;
    }

    private function shouldLoadAttentionOrders(AdminDashboardVariant $variant, User $user): bool
    {
        return $user->can('view_orders')
            && in_array($variant, [AdminDashboardVariant::Full, AdminDashboardVariant::Fulfillment, AdminDashboardVariant::Orders], true);
    }

    private function shouldLoadRecentOrders(AdminDashboardVariant $variant, User $user): bool
    {
        return $user->can('view_orders') && $variant === AdminDashboardVariant::Orders;
    }

    /**
     * @param  list<array{key: string, label: string, count: int, href: string, severity: string, icon: string}>  $cards
     * @return list<array{key: string, label: string, count: int, href: string, severity: string, icon: string}>
     */
    private function filterCardsForVariant(array $cards, AdminDashboardVariant $variant): array
    {
        $allowed = $variant->visibleExceptionKeys();

        if ($allowed === null) {
            return array_values(array_filter(
                $cards,
                fn (array $card): bool => $card['key'] !== 'orders_with_failures',
            ));
        }

        return array_values(array_filter(
            $cards,
            fn (array $card): bool => in_array($card['key'], $allowed, true),
        ));
    }

    /**
     * @return list<array{key: string, label: string, count: int, href: string, severity: string, icon: string, age_label?: string, age_severity?: string}>
     */
    private function exceptionCards(User $user): array
    {
        $counts = $this->exceptionCounts->handle($user);
        $cards = [];

        if ($user->can('view_orders')) {
            $failedOrderCount = $counts['orders_with_failures'];

            $cards[] = $this->card(
                'orders_with_failures',
                __('messages.admin_ops_orders_with_failures'),
                $failedOrderCount,
                route('admin.orders.index', ['fulfillmentFilter' => FulfillmentStatus::Failed->value]),
                'shopping-bag',
                $failedOrderCount > 0 ? 'amber' : 'zinc',
                $failedOrderCount > 0
                    ? Fulfillment::query()
                        ->where('status', FulfillmentStatus::Failed)
                        ->oldest('updated_at')
                        ->value('updated_at')
                    : null,
            );
        }

        if ($user->can('view_refunds')) {
            $count = $counts['pending_refunds'];

            $cards[] = $this->card(
                'pending_refunds',
                __('messages.admin_ops_pending_refunds'),
                $count,
                route('refunds'),
                'receipt-refund',
                $count > 0 ? 'red' : 'zinc',
                $count > 0
                    ? WalletTransaction::query()
                        ->where('type', WalletTransactionType::Refund)
                        ->where('status', WalletTransaction::STATUS_PENDING)
                        ->oldest('created_at')
                        ->value('created_at')
                    : null,
            );
        }

        if ($user->can('manage_topups')) {
            $count = $counts['pending_topups'];

            $cards[] = $this->card(
                'pending_topups',
                __('messages.admin_ops_pending_topups'),
                $count,
                route('topups', ['statusFilter' => TopupRequestStatus::Pending->value]),
                'wallet',
                $count > 0 ? 'amber' : 'zinc',
                $count > 0
                    ? TopupRequest::query()
                        ->where('status', TopupRequestStatus::Pending)
                        ->oldest('created_at')
                        ->value('created_at')
                    : null,
            );
        }

        if ($user->can('view_fulfillments')) {
            $queueCount = $counts['fulfillment_queue'];

            $cards[] = $this->card(
                'fulfillment_queue',
                __('messages.admin_ops_fulfillment_queue'),
                $queueCount,
                route('fulfillments', ['scope' => 'unclaimed']),
                'list-bullet',
                $queueCount >= 10 ? 'amber' : 'zinc',
                $queueCount > 0
                    ? Fulfillment::query()
                        ->whereIn('status', [FulfillmentStatus::Queued, FulfillmentStatus::Processing])
                        ->oldest('created_at')
                        ->value('created_at')
                    : null,
            );

            $failedCount = $counts['failed_fulfillments'];

            $cards[] = $this->card(
                'failed_fulfillments',
                __('messages.admin_ops_failed_fulfillments'),
                $failedCount,
                route('fulfillments', ['statusFilter' => FulfillmentStatus::Failed->value]),
                'exclamation-triangle',
                $failedCount > 0 ? 'red' : 'zinc',
                $failedCount > 0
                    ? Fulfillment::query()
                        ->where('status', FulfillmentStatus::Failed)
                        ->oldest('updated_at')
                        ->value('updated_at')
                    : null,
            );
        }

        if ($user->hasRole('admin')) {
            $needsReviewCount = $counts['automation_needs_review'];

            $cards[] = $this->card(
                'automation_needs_review',
                __('messages.admin_ops_automation_review'),
                $needsReviewCount,
                route('admin.automation.index'),
                'cpu-chip',
                $needsReviewCount > 0 ? 'amber' : 'zinc',
                $needsReviewCount > 0
                    ? FulfillmentAutomationRun::query()
                        ->where('status', FulfillmentAutomationRunStatus::NeedsReview)
                        ->oldest('created_at')
                        ->value('created_at')
                    : null,
            );
        }

        if ($user->can('manage_settlements')) {
            $count = $counts['pending_payouts'];

            $cards[] = $this->card(
                'pending_payouts',
                __('messages.admin_ops_pending_payouts'),
                $count,
                route('admin.payout-requests', ['statusFilter' => PayoutRequestStatus::Pending->value]),
                'inbox-arrow-down',
                $count > 0 ? 'amber' : 'zinc',
                $count > 0
                    ? PayoutRequest::query()
                        ->where('status', PayoutRequestStatus::Pending)
                        ->oldest('created_at')
                        ->value('created_at')
                    : null,
            );
        }

        if ($user->can('view_commission_clawbacks')) {
            $count = $counts['clawback_action_required_total'];

            $cards[] = $this->card(
                'clawback_action_required_total',
                __('messages.admin_ops_clawbacks_action_required'),
                $count,
                route('admin.commission-clawbacks.index', ['filter' => 'needs_review']),
                'arrow-uturn-left',
                $count > 0 ? 'amber' : 'zinc',
                $count > 0
                    ? \App\Support\Commissions\CommissionClawbackActionRequiredQuery::actionRequired()
                        ->oldest('created_at')
                        ->value('created_at')
                    : null,
            );
        }

        if ($user->can('manage_bugs')) {
            $count = $counts['open_bugs'];

            $cards[] = $this->card(
                'open_bugs',
                __('messages.admin_ops_open_bugs'),
                $count,
                route('admin.bugs.index'),
                'bug-ant',
                $count > 0 ? 'amber' : 'zinc',
                $count > 0
                    ? Bug::query()->openOrInProgress()->oldest('created_at')->value('created_at')
                    : null,
            );
        }

        return $cards;
    }

    /**
     * @return array{key: string, label: string, count: int, href: string, severity: string, icon: string, age_label?: string, age_severity?: string}
     */
    private function card(
        string $key,
        string $label,
        int $count,
        string $href,
        string $icon,
        string $severity,
        mixed $oldestAt = null,
    ): array {
        $card = [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'href' => $href,
            'severity' => $severity,
            'icon' => $icon,
        ];

        if ($count > 0 && $oldestAt !== null) {
            $age = $this->formatQueueAge->handle(
                $oldestAt instanceof CarbonInterface
                    ? $oldestAt
                    : Carbon::parse($oldestAt)
            );

            if ($age !== null) {
                $card['age_label'] = $age['label'];
                $card['age_severity'] = $age['severity'];
            }
        }

        return $card;
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return array{queued: int, processing: int, completed: int, active_supervisors: int, load: string, browser_needs_review: int}
     */
    private function queueHealthFromOverview(array $overview): array
    {
        return [
            'queued' => (int) ($overview['queued'] ?? 0),
            'processing' => (int) ($overview['processing'] ?? 0),
            'completed' => (int) ($overview['completed'] ?? 0),
            'active_supervisors' => (int) ($overview['active_supervisors'] ?? 0),
            'load' => (string) ($overview['load'] ?? 'normal'),
            'browser_needs_review' => (int) ($overview['browser_needs_review'] ?? 0),
        ];
    }

    /**
     * @return list<array{id: int, amount: float, order_number: ?string, user_name: string, created_at: ?string, href: string}>
     */
    private function recentPendingRefunds(): array
    {
        return WalletTransaction::query()
            ->where('type', WalletTransactionType::Refund)
            ->where('status', WalletTransaction::STATUS_PENDING)
            ->with('wallet.user:id,name')
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'wallet_id', 'amount', 'meta', 'created_at'])
            ->map(fn (WalletTransaction $transaction): array => [
                'id' => $transaction->id,
                'amount' => (float) $transaction->amount,
                'order_number' => data_get($transaction->meta, 'order_number'),
                'user_name' => $transaction->wallet?->user?->name ?? __('messages.unknown_user'),
                'created_at' => $transaction->created_at?->toIso8601String(),
                'href' => route('refunds'),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, amount: float, user_name: string, created_at: ?string, href: string}>
     */
    private function recentPendingTopups(): array
    {
        return TopupRequest::query()
            ->where('status', TopupRequestStatus::Pending)
            ->with('user:id,name')
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'user_id', 'amount', 'created_at'])
            ->map(fn (TopupRequest $topup): array => [
                'id' => $topup->id,
                'amount' => (float) $topup->amount,
                'user_name' => $topup->user?->name ?? __('messages.unknown_user'),
                'created_at' => $topup->created_at?->toIso8601String(),
                'href' => route('topups'),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, order_number: ?string, product_name: string, last_error: ?string, href: string}>
     */
    private function recentFailedFulfillments(): array
    {
        return Fulfillment::query()
            ->where('status', FulfillmentStatus::Failed)
            ->with(['order:id,order_number', 'orderItem:id,name'])
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'order_id', 'order_item_id', 'last_error', 'updated_at'])
            ->map(fn (Fulfillment $fulfillment): array => [
                'id' => $fulfillment->id,
                'order_number' => $fulfillment->order?->order_number,
                'product_name' => $fulfillment->orderItem?->name ?? __('messages.unknown_item'),
                'last_error' => $fulfillment->last_error,
                'href' => route('fulfillments', ['fulfillment' => $fulfillment->id]),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, order_number: string, user_name: string, status: string, failed_count: int, created_at: ?string, href: string}>
     */
    private function recentAttentionOrders(): array
    {
        return Order::query()
            ->whereHas('fulfillments', fn ($query) => $query->where('status', FulfillmentStatus::Failed))
            ->with(['user:id,name', 'fulfillments:id,order_id,status'])
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'order_number', 'user_id', 'status', 'created_at'])
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'user_name' => $order->user?->name ?? __('messages.unknown_user'),
                'status' => $order->status->value,
                'failed_count' => $order->fulfillments->where('status', FulfillmentStatus::Failed)->count(),
                'created_at' => $order->created_at?->toIso8601String(),
                'href' => route('admin.orders.show', $order),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, order_number: string, user_name: string, status: string, total: float, created_at: ?string, href: string}>
     */
    private function recentOrders(): array
    {
        return Order::query()
            ->with('user:id,name')
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'order_number', 'user_id', 'status', 'total', 'created_at'])
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'user_name' => $order->user?->name ?? __('messages.unknown_user'),
                'status' => $order->status->value,
                'total' => (float) $order->total,
                'created_at' => $order->created_at?->toIso8601String(),
                'href' => route('admin.orders.show', $order),
            ])
            ->all();
    }
}
