<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\DTOs\CustomerActivityDestination;
use App\DTOs\CustomerActivityDTO;
use App\DTOs\CustomerActivityMoney;
use App\Enums\CustomerActivityCategory;
use App\Enums\CustomerActivityDestinationType;
use App\Enums\CustomerActivityImportance;
use App\Enums\CustomerActivityStatusToken;
use App\Enums\TopupRequestStatus;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Unresolved customer top-up states for Activity (domain truth).
 */
final class TopupActionRequiredReader
{
    public const MAX_ITEMS = 10;

    /**
     * @return list<CustomerActivityDTO>
     */
    public function forUser(User $user, ?CustomerActivityCategory $category = null): array
    {
        if ($category !== null && $category !== CustomerActivityCategory::Money) {
            return [];
        }

        $requests = TopupRequest::query()
            ->where('user_id', $user->id)
            ->where('status', TopupRequestStatus::Rejected)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ITEMS)
            ->get(['id', 'amount', 'currency', 'status', 'note', 'created_at', 'updated_at']);

        return $requests
            ->map(fn (TopupRequest $request): CustomerActivityDTO => $this->map($request))
            ->all();
    }

    private function map(TopupRequest $request): CustomerActivityDTO
    {
        $id = (string) $request->id;
        $stableKey = 'action:topup:'.$id.':rejected';
        $reason = is_string($request->note) && trim($request->note) !== ''
            ? trim($request->note)
            : null;

        return new CustomerActivityDTO(
            id: $stableKey,
            stableKey: $stableKey,
            sourceType: 'TopupRequest',
            sourceId: $id,
            dedupeKey: 'topup:'.$id,
            groupKey: null,
            category: CustomerActivityCategory::Money,
            importance: CustomerActivityImportance::Attention,
            statusToken: CustomerActivityStatusToken::Warning,
            title: __('messages.activity_action_topup_rejected_title'),
            description: $reason !== null
                ? __('messages.activity_action_topup_rejected_description_with_reason', ['reason' => $reason])
                : __('messages.activity_action_topup_rejected_description'),
            occurredAt: $request->updated_at instanceof Carbon
                ? $request->updated_at
                : Carbon::parse((string) ($request->updated_at ?? $request->created_at)),
            readAt: null,
            isUnread: false,
            requiresAction: true,
            actionLabel: __('messages.activity_action_add_funds'),
            destination: new CustomerActivityDestination(CustomerActivityDestinationType::WalletTopup),
            secondaryMeta: [],
            money: new CustomerActivityMoney(
                amount: (string) $request->amount,
                currency: strtoupper((string) ($request->currency ?: 'USD')),
                direction: 'credit',
                visible: true,
            ),
            iconKey: 'banknotes',
        );
    }
}
