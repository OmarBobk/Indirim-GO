<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\CustomerActivityDestination;
use App\DTOs\CustomerActivityDTO;
use App\DTOs\CustomerActivityMoney;
use App\Enums\CustomerActivityCategory;
use App\Enums\CustomerActivityDestinationType;
use App\Enums\CustomerActivityImportance;
use App\Models\User;
use App\Models\WebsiteSetting;
use Illuminate\Support\Facades\Route;

/**
 * Maps CustomerActivityDTO into passive Blade-ready arrays.
 */
final class CustomerActivityPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(CustomerActivityDTO $item, ?User $viewer = null): array
    {
        $money = FrontendMoney::for($viewer);

        return [
            'id' => $item->id,
            'notification_id' => $this->notificationId($item->id, $item->secondaryMeta),
            'stable_key' => $item->stableKey,
            'category' => $item->category->value,
            'category_label' => $this->categoryLabel($item->category),
            'importance' => $item->importance->value,
            'importance_label' => $this->importanceLabel($item->importance),
            'status_token' => $item->statusToken->value,
            'badge_color' => CustomerStatusPresentation::activityBadgeColor($item->statusToken->value),
            'show_status_badge' => $item->statusToken->value !== 'neutral',
            'title' => $item->title,
            'description' => $item->description,
            'occurred_at' => $item->occurredAt->toIso8601String(),
            'occurred_at_display' => $item->occurredAt->diffForHumans(),
            'is_unread' => $item->isUnread,
            'requires_action' => $item->requiresAction,
            'action_label' => $item->actionLabel,
            'href' => $this->resolveHref($item->destination),
            'icon' => $item->iconKey,
            'money' => $this->presentMoney($item->money, $money),
            'secondary_meta' => $item->secondaryMeta,
        ];
    }

    /**
     * @param  list<CustomerActivityDTO>  $items
     * @return list<array<string, mixed>>
     */
    public function presentMany(array $items, ?User $viewer = null): array
    {
        return array_map(
            fn (CustomerActivityDTO $item): array => $this->present($item, $viewer),
            $items
        );
    }

    public function resolveHref(CustomerActivityDestination $destination): string
    {
        return match ($destination->type) {
            CustomerActivityDestinationType::OrderDetail => route(
                'orders.show',
                ['order' => (string) ($destination->params['order_number'] ?? '')]
            ),
            CustomerActivityDestinationType::Orders => route('orders.index'),
            CustomerActivityDestinationType::Wallet => route('wallet'),
            CustomerActivityDestinationType::WalletTopup => route('wallet.topup'),
            CustomerActivityDestinationType::Cart => route('cart'),
            CustomerActivityDestinationType::Loyalty => route('loyalty'),
            CustomerActivityDestinationType::Referral => route('referral-link'),
            CustomerActivityDestinationType::Account => route('account'),
            CustomerActivityDestinationType::Profile => route('profile'),
            CustomerActivityDestinationType::Activity => Route::has('activity.index')
                ? route('activity.index')
                : route('notifications.index'),
        };
    }

    private function notificationId(string $activityId, array $secondaryMeta = []): ?string
    {
        $twin = $secondaryMeta['twin_notification_id'] ?? null;
        if (is_string($twin) && $twin !== '') {
            return $twin;
        }

        if (! str_starts_with($activityId, 'notification:')) {
            return null;
        }

        $id = substr($activityId, strlen('notification:'));

        return $id !== '' ? $id : null;
    }

    private function categoryLabel(CustomerActivityCategory $category): string
    {
        return match ($category) {
            CustomerActivityCategory::Orders => __('messages.activity_category_orders'),
            CustomerActivityCategory::Money => __('messages.activity_category_money'),
            CustomerActivityCategory::Rewards => __('messages.activity_category_rewards'),
            CustomerActivityCategory::Account => __('messages.activity_category_account'),
        };
    }

    private function importanceLabel(CustomerActivityImportance $importance): string
    {
        return match ($importance) {
            CustomerActivityImportance::Urgent => __('messages.activity_importance_urgent'),
            CustomerActivityImportance::Attention => __('messages.activity_importance_attention'),
            CustomerActivityImportance::Success => __('messages.activity_importance_success'),
            CustomerActivityImportance::Informational => __('messages.activity_importance_informational'),
        };
    }

    /**
     * @return array{formatted: string, amount: string, currency: string, direction: string, visible: bool, dir: string}|null
     */
    private function presentMoney(?CustomerActivityMoney $money, FrontendMoney $formatter): ?array
    {
        if ($money === null) {
            return null;
        }

        $visible = $money->visible && WebsiteSetting::getPricesVisible();

        return [
            'formatted' => $visible
                ? $formatter->format($money->amount, $money->currency, 2)
                : '—',
            'amount' => $money->amount,
            'currency' => $money->currency,
            'direction' => $money->direction,
            'visible' => $visible,
            'dir' => 'ltr',
        ];
    }
}
