<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\PayoutRequestStatus;
use App\Enums\TopupRequestStatus;
use App\Enums\WalletTransactionType;
use App\Models\Bug;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\WalletTransaction;

class GetAdminExceptionCounts
{
    /**
     * Queues that need a decision now — drive sidebar badges and "all clear".
     *
     * @var list<string>
     */
    public const ACTIONABLE_KEYS = [
        'pending_refunds',
        'pending_topups',
        'fulfillment_queue',
        'automation_needs_review',
        'pending_payouts',
        'open_bugs',
    ];

    /**
     * @param  list<string>  $visibleKeys
     * @return list<string>
     */
    public static function actionableKeysForVisible(?array $visibleKeys): array
    {
        if ($visibleKeys === null) {
            return self::ACTIONABLE_KEYS;
        }

        return array_values(array_intersect($visibleKeys, self::ACTIONABLE_KEYS));
    }

    /**
     * Permission-scoped exception counts keyed for dashboard cards and sidebar badges.
     *
     * @return array{
     *     orders_with_failures: int,
     *     pending_refunds: int,
     *     pending_topups: int,
     *     fulfillment_queue: int,
     *     failed_fulfillments: int,
     *     automation_needs_review: int,
     *     pending_payouts: int,
     *     open_bugs: int
     * }
     */
    public function handle(User $user): array
    {
        return [
            'orders_with_failures' => $user->can('view_orders')
                ? Order::query()
                    ->whereHas('fulfillments', fn ($query) => $query->where('status', FulfillmentStatus::Failed))
                    ->count()
                : 0,
            'pending_refunds' => $user->can('view_refunds')
                ? WalletTransaction::query()
                    ->where('type', WalletTransactionType::Refund)
                    ->where('status', WalletTransaction::STATUS_PENDING)
                    ->count()
                : 0,
            'pending_topups' => $user->can('manage_topups')
                ? TopupRequest::query()
                    ->where('status', TopupRequestStatus::Pending)
                    ->count()
                : 0,
            'fulfillment_queue' => $user->can('view_fulfillments')
                ? Fulfillment::query()
                    ->whereIn('status', [FulfillmentStatus::Queued, FulfillmentStatus::Processing])
                    ->count()
                : 0,
            'failed_fulfillments' => $user->can('view_fulfillments')
                ? Fulfillment::query()
                    ->where('status', FulfillmentStatus::Failed)
                    ->count()
                : 0,
            'automation_needs_review' => $user->hasRole('admin')
                ? FulfillmentAutomationRun::query()
                    ->where('status', FulfillmentAutomationRunStatus::NeedsReview)
                    ->count()
                : 0,
            'pending_payouts' => $user->can('manage_settlements')
                ? PayoutRequest::query()
                    ->where('status', PayoutRequestStatus::Pending)
                    ->count()
                : 0,
            'open_bugs' => $user->can('manage_bugs')
                ? Bug::query()->openOrInProgress()->count()
                : 0,
        ];
    }
}
