<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\DTOs\CustomerActivityDestination;
use App\DTOs\CustomerActivityDTO;
use App\Enums\CustomerActivityCategory;
use App\Enums\CustomerActivityDestinationType;
use App\Enums\CustomerActivityImportance;
use App\Enums\CustomerActivityStatusToken;
use App\Notifications\BugRecordedNotification;
use App\Notifications\CommissionClawbackDisputeOpenedNotification;
use App\Notifications\CommissionClawbackDisputeResolvedNotification;
use App\Notifications\CommissionClawbackNeedsReviewNotification;
use App\Notifications\CommissionClawbackWaiverApprovedNotification;
use App\Notifications\CommissionCreditedNotification;
use App\Notifications\CommissionReversalCorrectionPostedNotification;
use App\Notifications\CommissionReversalPostedNotification;
use App\Notifications\FulfillmentCompletedNotification;
use App\Notifications\FulfillmentCreatedNotification;
use App\Notifications\FulfillmentFailedNotification;
use App\Notifications\FulfillmentProcessFailedNotification;
use App\Notifications\LoyaltyTierChangedNotification;
use App\Notifications\OrderPriceFlooredNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\RefundApprovedNotification;
use App\Notifications\RefundRejectedNotification;
use App\Notifications\RefundRequestedNotification;
use App\Notifications\SalespersonPayoutRequestedNotification;
use App\Notifications\SettlementCreatedNotification;
use App\Notifications\TopupApprovedNotification;
use App\Notifications\TopupRejectedNotification;
use App\Notifications\TopupRequestedNotification;
use App\Notifications\UserBlockedNotification;
use App\Notifications\UserUnblockedNotification;
use App\Notifications\WalletReconciledNotification;
use App\Notifications\WasimPriceDriftReviewNotification;
use App\Notifications\WasimPriceReactiveFlagNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Maps customer database notifications into Activity DTOs.
 * Whitelist-only for known customer types; unknown rows degrade safely.
 */
final class CustomerActivityNotificationMapper
{
    /**
     * @return list<class-string>
     */
    public function supportedTypes(): array
    {
        return [
            TopupApprovedNotification::class,
            TopupRejectedNotification::class,
            RefundApprovedNotification::class,
            RefundRejectedNotification::class,
            FulfillmentCompletedNotification::class,
            FulfillmentFailedNotification::class,
            PaymentFailedNotification::class,
            LoyaltyTierChangedNotification::class,
            UserBlockedNotification::class,
            UserUnblockedNotification::class,
            CommissionCreditedNotification::class,
            CommissionReversalPostedNotification::class,
            CommissionClawbackWaiverApprovedNotification::class,
            CommissionClawbackDisputeOpenedNotification::class,
            CommissionClawbackDisputeResolvedNotification::class,
            CommissionReversalCorrectionPostedNotification::class,
        ];
    }

    /**
     * Admin-only notification classes — never project into the customer Activity feed.
     *
     * @return list<class-string>
     */
    public function adminTypes(): array
    {
        return [
            TopupRequestedNotification::class,
            RefundRequestedNotification::class,
            FulfillmentCreatedNotification::class,
            FulfillmentProcessFailedNotification::class,
            BugRecordedNotification::class,
            SalespersonPayoutRequestedNotification::class,
            SettlementCreatedNotification::class,
            WalletReconciledNotification::class,
            WasimPriceReactiveFlagNotification::class,
            WasimPriceDriftReviewNotification::class,
            OrderPriceFlooredNotification::class,
            CommissionClawbackNeedsReviewNotification::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    public function typesForCategory(CustomerActivityCategory $category): array
    {
        return array_values(array_filter(
            $this->supportedTypes(),
            fn (string $type): bool => ($this->definitionFor($type)['category'] ?? null) === $category
        ));
    }

    public function isAdminType(string $type): bool
    {
        return in_array($type, $this->adminTypes(), true);
    }

    public function map(DatabaseNotification $notification): CustomerActivityDTO
    {
        $type = (string) $notification->type;
        $data = is_array($notification->data) ? $notification->data : [];
        $definition = $this->definitionFor($type) ?? $this->fallbackDefinition();

        $title = $this->safeString($data['title'] ?? null) ?? __('messages.activity_fallback_title');
        $description = $this->safeString($data['message'] ?? null) ?? __('messages.activity_fallback_description');
        $sourceType = $this->safeSourceType($data['source_type'] ?? null) ?? 'notification';
        $sourceId = (string) ($data['source_id'] ?? $notification->id);
        $stableKey = 'notification:'.$notification->id;
        $occurredAt = $notification->created_at instanceof Carbon
            ? $notification->created_at
            : Carbon::parse((string) $notification->created_at);
        $readAt = $notification->read_at !== null
            ? ($notification->read_at instanceof Carbon
                ? $notification->read_at
                : Carbon::parse((string) $notification->read_at))
            : null;

        $destination = $this->resolveDestination(
            $definition,
            $this->safeString($data['url'] ?? null),
        );

        $actionLabel = $definition['requiresAction']
            ? __($definition['actionLabelKey'])
            : ($definition['actionLabelKey'] !== null ? __($definition['actionLabelKey']) : null);

        $isSupported = in_array($type, $this->supportedTypes(), true);

        return new CustomerActivityDTO(
            id: $stableKey,
            stableKey: $stableKey,
            sourceType: $sourceType,
            sourceId: $sourceId,
            dedupeKey: $definition['dedupePrefix'].':'.$sourceId,
            groupKey: null,
            category: $definition['category'],
            importance: $definition['importance'],
            statusToken: $definition['statusToken'],
            title: $title,
            description: $description,
            occurredAt: $occurredAt,
            readAt: $readAt,
            isUnread: $readAt === null,
            requiresAction: $definition['requiresAction'],
            actionLabel: $actionLabel,
            destination: $destination,
            secondaryMeta: $isSupported
                ? array_filter([
                    'notification_type' => class_basename($type),
                ], fn (mixed $value): bool => $value !== null && $value !== '')
                : [],
            money: null,
            iconKey: $definition['iconKey'],
        );
    }

    /**
     * @return array{
     *     category: CustomerActivityCategory,
     *     importance: CustomerActivityImportance,
     *     statusToken: CustomerActivityStatusToken,
     *     requiresAction: bool,
     *     actionLabelKey: ?string,
     *     destinationType: CustomerActivityDestinationType,
     *     iconKey: string,
     *     dedupePrefix: string
     * }|null
     */
    private function definitionFor(string $type): ?array
    {
        return match ($type) {
            TopupApprovedNotification::class => [
                'category' => CustomerActivityCategory::Money,
                'importance' => CustomerActivityImportance::Success,
                'statusToken' => CustomerActivityStatusToken::Success,
                'requiresAction' => false,
                'actionLabelKey' => 'messages.activity_action_view_wallet',
                'destinationType' => CustomerActivityDestinationType::Wallet,
                'iconKey' => 'banknotes',
                'dedupePrefix' => 'topup',
            ],
            TopupRejectedNotification::class => [
                'category' => CustomerActivityCategory::Money,
                'importance' => CustomerActivityImportance::Attention,
                'statusToken' => CustomerActivityStatusToken::Warning,
                'requiresAction' => true,
                'actionLabelKey' => 'messages.activity_action_add_funds',
                'destinationType' => CustomerActivityDestinationType::WalletTopup,
                'iconKey' => 'banknotes',
                'dedupePrefix' => 'topup',
            ],
            RefundApprovedNotification::class => [
                'category' => CustomerActivityCategory::Money,
                'importance' => CustomerActivityImportance::Success,
                'statusToken' => CustomerActivityStatusToken::Success,
                'requiresAction' => false,
                'actionLabelKey' => 'messages.activity_action_view_wallet',
                'destinationType' => CustomerActivityDestinationType::Wallet,
                'iconKey' => 'arrow-uturn-left',
                'dedupePrefix' => 'refund',
            ],
            RefundRejectedNotification::class => [
                'category' => CustomerActivityCategory::Orders,
                'importance' => CustomerActivityImportance::Attention,
                'statusToken' => CustomerActivityStatusToken::Warning,
                'requiresAction' => true,
                'actionLabelKey' => 'messages.activity_action_view_orders',
                'destinationType' => CustomerActivityDestinationType::Orders,
                'iconKey' => 'shopping-bag',
                'dedupePrefix' => 'refund',
            ],
            FulfillmentCompletedNotification::class => [
                'category' => CustomerActivityCategory::Orders,
                'importance' => CustomerActivityImportance::Success,
                'statusToken' => CustomerActivityStatusToken::Success,
                'requiresAction' => false,
                'actionLabelKey' => 'messages.activity_action_view_order',
                'destinationType' => CustomerActivityDestinationType::OrderDetail,
                'iconKey' => 'check-circle',
                'dedupePrefix' => 'fulfillment',
            ],
            FulfillmentFailedNotification::class => [
                'category' => CustomerActivityCategory::Orders,
                'importance' => CustomerActivityImportance::Urgent,
                'statusToken' => CustomerActivityStatusToken::Danger,
                'requiresAction' => true,
                'actionLabelKey' => 'messages.activity_action_view_order',
                'destinationType' => CustomerActivityDestinationType::OrderDetail,
                'iconKey' => 'exclamation-triangle',
                'dedupePrefix' => 'fulfillment',
            ],
            PaymentFailedNotification::class => [
                'category' => CustomerActivityCategory::Money,
                'importance' => CustomerActivityImportance::Urgent,
                'statusToken' => CustomerActivityStatusToken::Danger,
                'requiresAction' => true,
                'actionLabelKey' => 'messages.activity_action_return_to_cart',
                'destinationType' => CustomerActivityDestinationType::Cart,
                'iconKey' => 'shopping-cart',
                'dedupePrefix' => 'payment',
            ],
            LoyaltyTierChangedNotification::class => [
                'category' => CustomerActivityCategory::Rewards,
                'importance' => CustomerActivityImportance::Success,
                'statusToken' => CustomerActivityStatusToken::Success,
                'requiresAction' => false,
                'actionLabelKey' => 'messages.activity_action_view_loyalty',
                'destinationType' => CustomerActivityDestinationType::Loyalty,
                'iconKey' => 'sparkles',
                'dedupePrefix' => 'loyalty',
            ],
            CommissionCreditedNotification::class => [
                'category' => CustomerActivityCategory::Rewards,
                'importance' => CustomerActivityImportance::Success,
                'statusToken' => CustomerActivityStatusToken::Success,
                'requiresAction' => false,
                'actionLabelKey' => 'messages.activity_action_view_wallet',
                'destinationType' => CustomerActivityDestinationType::Wallet,
                'iconKey' => 'gift',
                'dedupePrefix' => 'commission',
            ],
            CommissionReversalPostedNotification::class => [
                'category' => CustomerActivityCategory::Rewards,
                'importance' => CustomerActivityImportance::Informational,
                'statusToken' => CustomerActivityStatusToken::Progress,
                'requiresAction' => false,
                'actionLabelKey' => 'messages.activity_action_view_wallet',
                'destinationType' => CustomerActivityDestinationType::Wallet,
                'iconKey' => 'arrow-uturn-left',
                'dedupePrefix' => 'commission_reversal',
            ],
            CommissionClawbackWaiverApprovedNotification::class => [
                'category' => CustomerActivityCategory::Rewards,
                'importance' => CustomerActivityImportance::Informational,
                'statusToken' => CustomerActivityStatusToken::Progress,
                'requiresAction' => false,
                'actionLabelKey' => 'messages.activity_action_view_wallet',
                'destinationType' => CustomerActivityDestinationType::Wallet,
                'iconKey' => 'gift',
                'dedupePrefix' => 'commission_clawback_waiver',
            ],
            CommissionClawbackDisputeOpenedNotification::class => [
                'category' => CustomerActivityCategory::Rewards,
                'importance' => CustomerActivityImportance::Informational,
                'statusToken' => CustomerActivityStatusToken::Progress,
                'requiresAction' => false,
                'actionLabelKey' => 'messages.activity_action_view_wallet',
                'destinationType' => CustomerActivityDestinationType::Wallet,
                'iconKey' => 'exclamation-circle',
                'dedupePrefix' => 'commission_clawback_dispute',
            ],
            CommissionClawbackDisputeResolvedNotification::class => [
                'category' => CustomerActivityCategory::Rewards,
                'importance' => CustomerActivityImportance::Informational,
                'statusToken' => CustomerActivityStatusToken::Progress,
                'requiresAction' => false,
                'actionLabelKey' => 'messages.activity_action_view_wallet',
                'destinationType' => CustomerActivityDestinationType::Wallet,
                'iconKey' => 'check-circle',
                'dedupePrefix' => 'commission_clawback_dispute_resolved',
            ],
            CommissionReversalCorrectionPostedNotification::class => [
                'category' => CustomerActivityCategory::Rewards,
                'importance' => CustomerActivityImportance::Informational,
                'statusToken' => CustomerActivityStatusToken::Progress,
                'requiresAction' => false,
                'actionLabelKey' => 'messages.activity_action_view_wallet',
                'destinationType' => CustomerActivityDestinationType::Wallet,
                'iconKey' => 'gift',
                'dedupePrefix' => 'commission_reversal_correction',
            ],
            UserBlockedNotification::class => [
                'category' => CustomerActivityCategory::Account,
                'importance' => CustomerActivityImportance::Urgent,
                'statusToken' => CustomerActivityStatusToken::Danger,
                'requiresAction' => true,
                'actionLabelKey' => 'messages.activity_action_view_account',
                'destinationType' => CustomerActivityDestinationType::Account,
                'iconKey' => 'no-symbol',
                'dedupePrefix' => 'account',
            ],
            UserUnblockedNotification::class => [
                'category' => CustomerActivityCategory::Account,
                'importance' => CustomerActivityImportance::Informational,
                'statusToken' => CustomerActivityStatusToken::Progress,
                'requiresAction' => false,
                'actionLabelKey' => 'messages.activity_action_view_account',
                'destinationType' => CustomerActivityDestinationType::Account,
                'iconKey' => 'user',
                'dedupePrefix' => 'account',
            ],
            default => null,
        };
    }

    /**
     * @return array{
     *     category: CustomerActivityCategory,
     *     importance: CustomerActivityImportance,
     *     statusToken: CustomerActivityStatusToken,
     *     requiresAction: bool,
     *     actionLabelKey: ?string,
     *     destinationType: CustomerActivityDestinationType,
     *     iconKey: string,
     *     dedupePrefix: string
     * }
     */
    private function fallbackDefinition(): array
    {
        return [
            'category' => CustomerActivityCategory::Account,
            'importance' => CustomerActivityImportance::Informational,
            'statusToken' => CustomerActivityStatusToken::Neutral,
            'requiresAction' => false,
            'actionLabelKey' => 'messages.activity_action_view_activity',
            'destinationType' => CustomerActivityDestinationType::Activity,
            'iconKey' => 'bell',
            'dedupePrefix' => 'notification',
        ];
    }

    /**
     * @param  array{
     *     destinationType: CustomerActivityDestinationType,
     *     ...
     * }  $definition
     */
    private function resolveDestination(array $definition, ?string $storedUrl): CustomerActivityDestination
    {
        $type = $definition['destinationType'];

        if ($type === CustomerActivityDestinationType::OrderDetail) {
            $orderNumber = $this->extractOrderNumberFromUrl($storedUrl);

            if ($orderNumber !== null) {
                return new CustomerActivityDestination($type, ['order_number' => $orderNumber]);
            }

            return new CustomerActivityDestination(CustomerActivityDestinationType::Orders);
        }

        return new CustomerActivityDestination($type);
    }

    private function extractOrderNumberFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (preg_match('#/orders/([A-Za-z0-9\-_]+)$#', $path, $matches) !== 1) {
            return null;
        }

        $orderNumber = $matches[1];

        if (in_array(strtolower($orderNumber), ['index', 'create'], true)) {
            return null;
        }

        return $orderNumber;
    }

    private function safeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function safeSourceType(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_contains($value, '://') || str_contains(strtolower($value), 'supplier') || str_contains(strtolower($value), 'automation')) {
            return 'notification';
        }

        return class_basename($value);
    }
}
