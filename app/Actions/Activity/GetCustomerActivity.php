<?php

declare(strict_types=1);

namespace App\Actions\Activity;

use App\DTOs\CustomerActivityDTO;
use App\DTOs\CustomerActivityResult;
use App\Enums\CustomerActivityCategory;
use App\Enums\CustomerActivityImportance;
use App\Models\User;
use App\Support\Activity\CustomerActivityMerger;
use App\Support\Activity\NotificationActivityReader;
use App\Support\Activity\OrderActionRequiredReader;
use App\Support\Activity\RefundActionRequiredReader;
use App\Support\Activity\TopupActionRequiredReader;

/**
 * Customer Activity read-model: notifications + capped action-required domain readers.
 *
 * Pagination (v1):
 * - all / unread: notification SQL pagination remains the spine; action items are capped
 *   and surfaced in a summary (All only). Matching notification rows are suppressed from
 *   the chronological page when an action-required twin exists.
 * - action_required: in-memory pagination over the capped merged action-required set.
 * A multi-source cursor would be needed only if unresolved volume exceeds reader caps.
 */
final class GetCustomerActivity
{
    public function __construct(
        private readonly NotificationActivityReader $notificationReader,
        private readonly TopupActionRequiredReader $topupReader,
        private readonly OrderActionRequiredReader $orderReader,
        private readonly RefundActionRequiredReader $refundReader,
        private readonly CustomerActivityMerger $merger,
    ) {}

    /**
     * Home Operational strip: unresolved urgent/attention action items only.
     *
     * Skips notification feed pagination, unread COUNT, and twin attachment —
     * Home CTAs are navigation-only (M5.5).
     */
    public function forHomeOperational(User $user): CustomerActivityResult
    {
        $actionItems = $this->loadActionItems($user, null);

        $actionable = array_values(array_filter(
            $actionItems,
            static function (CustomerActivityDTO $item): bool {
                if (! $item->requiresAction) {
                    return false;
                }

                return $item->importance === CustomerActivityImportance::Urgent
                    || $item->importance === CustomerActivityImportance::Attention;
            }
        ));

        $sorted = $this->merger->sortActionRequired($actionable);
        $total = count($sorted);
        $visible = array_slice($sorted, 0, CustomerActivityMerger::SUMMARY_LIMIT);

        return new CustomerActivityResult(
            items: $visible,
            currentPage: 1,
            perPage: CustomerActivityMerger::SUMMARY_LIMIT,
            total: $total,
            lastPage: 1,
            unreadCount: 0,
            filter: 'action_required',
            category: null,
            actionRequiredSummary: $visible,
            actionRequiredTotal: $total,
            hasMoreActionRequired: $total > CustomerActivityMerger::SUMMARY_LIMIT,
        );
    }

    public function handle(
        User $user,
        string $filter = 'all',
        ?string $category = null,
        int $perPage = 15,
        int $page = 1,
    ): CustomerActivityResult {
        $filter = in_array($filter, ['all', 'unread', 'action_required'], true) ? $filter : 'all';
        $categoryEnum = $this->resolveCategory($category);
        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);

        $actionItems = [];
        $sortedActionable = [];
        $summary = [];

        if ($filter !== 'unread') {
            $actionItems = $this->loadActionItems($user, $categoryEnum);
            $recentNotifications = $this->notificationReader->recent($user);
            $actionItems = $this->merger->withNotificationTwins($actionItems, $recentNotifications);

            $actionable = array_values(array_filter(
                $actionItems,
                static fn (CustomerActivityDTO $item): bool => $item->requiresAction
            ));
            $sortedActionable = $this->merger->sortActionRequired($actionable);
            $summary = $filter === 'all' ? $this->merger->summary($sortedActionable) : [];
        }

        if ($filter === 'action_required') {
            return $this->paginateList(
                items: $sortedActionable,
                unreadCount: $this->notificationReader->unreadCount($user),
                filter: $filter,
                category: $categoryEnum?->value,
                perPage: $perPage,
                page: $page,
                actionRequiredTotal: count($sortedActionable),
            );
        }

        $paginator = $this->notificationReader->paginate(
            user: $user,
            filter: $filter === 'unread' ? 'unread' : 'all',
            category: $categoryEnum,
            perPage: $perPage,
            page: $page,
        );

        $feed = array_values($paginator->items());

        if ($filter === 'all') {
            $feed = $this->merger->suppressNotificationTwins($feed, $sortedActionable);
        }

        return new CustomerActivityResult(
            items: $feed,
            currentPage: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            lastPage: $paginator->lastPage(),
            unreadCount: $this->notificationReader->unreadCount($user),
            filter: $filter,
            category: $categoryEnum?->value,
            actionRequiredSummary: $summary,
            actionRequiredTotal: count($sortedActionable),
            hasMoreActionRequired: count($sortedActionable) > CustomerActivityMerger::SUMMARY_LIMIT,
        );
    }

    /**
     * @return list<CustomerActivityDTO>
     */
    private function loadActionItems(User $user, ?CustomerActivityCategory $category): array
    {
        return [
            ...$this->topupReader->forUser($user, $category),
            ...$this->refundReader->forUser($user, $category),
            ...$this->orderReader->forUser($user, $category),
        ];
    }

    /**
     * @param  list<CustomerActivityDTO>  $items
     */
    private function paginateList(
        array $items,
        int $unreadCount,
        string $filter,
        ?string $category,
        int $perPage,
        int $page,
        int $actionRequiredTotal,
    ): CustomerActivityResult {
        $total = count($items);
        $lastPage = $total === 0 ? 1 : max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new CustomerActivityResult(
            items: array_values($slice),
            currentPage: $page,
            perPage: $perPage,
            total: $total,
            lastPage: $lastPage,
            unreadCount: $unreadCount,
            filter: $filter,
            category: $category,
            actionRequiredSummary: [],
            actionRequiredTotal: $actionRequiredTotal,
            hasMoreActionRequired: false,
        );
    }

    private function resolveCategory(?string $category): ?CustomerActivityCategory
    {
        if ($category === null || trim($category) === '' || $category === 'all') {
            return null;
        }

        return CustomerActivityCategory::tryFrom($category);
    }
}
