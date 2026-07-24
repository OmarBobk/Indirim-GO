<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CreditFacilityStatus;
use App\Models\SystemEvent;
use App\Models\User;

/**
 * Maps system_events into customer-facing copy and labeled facts.
 * Keeps technical keys / raw meta out of the storefront wallet timeline.
 */
final class CustomerSystemEventPresenter
{
    /**
     * @return array{
     *     title: string,
     *     description: string|null,
     *     facts: list<array{label: string, value: string, tone: 'debt'|'positive'|'neutral'}>
     * }
     */
    public function present(SystemEvent $event, ?User $viewer = null): array
    {
        $money = FrontendMoney::for($viewer);
        $meta = is_array($event->meta) ? $event->meta : [];
        $currency = $this->currency($meta);

        return match ($event->event_type) {
            'wallet.credit_facility.updated' => $this->presentCreditFacilityUpdated($meta, $money, $currency),
            'wallet.purchase.debited' => $this->presentAmountEvent(
                title: __('messages.wallet_event_purchase_title'),
                description: __('messages.wallet_event_purchase_description'),
                meta: $meta,
                money: $money,
                currency: $currency,
                amountTone: 'debt',
            ),
            'wallet.topup.posted' => $this->presentAmountEvent(
                title: __('messages.wallet_event_topup_title'),
                description: __('messages.wallet_event_topup_description'),
                meta: $meta,
                money: $money,
                currency: $currency,
                amountTone: 'positive',
            ),
            'wallet.adjustment.posted' => $this->presentAmountEvent(
                title: __('messages.wallet_event_adjustment_title'),
                description: __('messages.wallet_event_adjustment_description'),
                meta: $meta,
                money: $money,
                currency: $currency,
                amountTone: 'positive',
            ),
            'wallet.refund.credited' => $this->presentAmountEvent(
                title: __('messages.wallet_event_refund_title'),
                description: __('messages.wallet_event_refund_description'),
                meta: $meta,
                money: $money,
                currency: $currency,
                amountTone: 'positive',
            ),
            'wallet.commission.credited' => $this->presentAmountEvent(
                title: __('messages.wallet_event_commission_title'),
                description: __('messages.wallet_event_commission_description'),
                meta: $meta,
                money: $money,
                currency: $currency,
                amountTone: 'positive',
            ),
            default => [
                'title' => $this->fallbackTitle($event->event_type),
                'description' => null,
                'facts' => $this->genericAmountFacts($meta, $money, $currency),
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{
     *     title: string,
     *     description: string|null,
     *     facts: list<array{label: string, value: string, tone: 'debt'|'positive'|'neutral'}>
     * }
     */
    private function presentCreditFacilityUpdated(array $meta, FrontendMoney $money, string $currency): array
    {
        $previousLimit = $this->numericOrNull($meta['previous_limit'] ?? null);
        $newLimit = $this->numericOrNull($meta['new_limit'] ?? null);
        $previousTerms = $this->intOrNull($meta['previous_terms'] ?? null);
        $newTerms = $this->intOrNull($meta['new_terms'] ?? null);
        $previousEnabled = array_key_exists('previous_enabled', $meta) ? (bool) $meta['previous_enabled'] : null;
        $newEnabled = array_key_exists('new_enabled', $meta) ? (bool) $meta['new_enabled'] : null;
        $previousStatus = $this->stringOrNull($meta['previous_status'] ?? null);
        $newStatus = $this->stringOrNull($meta['new_status'] ?? null);
        $outstandingDebt = $this->numericOrNull($meta['outstanding_debt'] ?? null);
        $availableCredit = $this->numericOrNull($meta['available_credit_after'] ?? null);

        $description = $this->creditFacilityDescription(
            previousLimit: $previousLimit,
            newLimit: $newLimit,
            previousEnabled: $previousEnabled,
            newEnabled: $newEnabled,
            previousStatus: $previousStatus,
            newStatus: $newStatus,
            money: $money,
            currency: $currency,
        );

        $facts = [];

        if ($newLimit !== null) {
            $facts[] = [
                'label' => __('messages.wallet_credit_limit_label'),
                'value' => $money->format($newLimit, $currency, 2),
                'tone' => 'neutral',
            ];
        }

        if ($outstandingDebt !== null) {
            $facts[] = [
                'label' => __('messages.wallet_you_owe'),
                'value' => $money->format($outstandingDebt, $currency, 2),
                'tone' => $outstandingDebt > 0 ? 'debt' : 'neutral',
            ];
        }

        if ($availableCredit !== null) {
            $facts[] = [
                'label' => __('messages.wallet_available_credit_label'),
                'value' => $money->format($availableCredit, $currency, 2),
                'tone' => $availableCredit > 0 ? 'positive' : 'neutral',
            ];
        }

        if ($newEnabled === true && $newTerms !== null) {
            $facts[] = [
                'label' => __('messages.wallet_credit_terms_label'),
                'value' => __('messages.wallet_credit_terms_net', ['days' => $newTerms]),
                'tone' => 'neutral',
            ];
        } elseif ($newEnabled === false && $previousTerms !== null && $newTerms === null) {
            $facts[] = [
                'label' => __('messages.wallet_credit_terms_label'),
                'value' => __('messages.credit_facility_terms_none'),
                'tone' => 'neutral',
            ];
        } elseif ($newTerms !== null) {
            $facts[] = [
                'label' => __('messages.wallet_credit_terms_label'),
                'value' => __('messages.wallet_credit_terms_net', ['days' => $newTerms]),
                'tone' => 'neutral',
            ];
        }

        $facts[] = [
            'label' => __('messages.status'),
            'value' => $this->creditStatusLabel($newEnabled, $newStatus),
            'tone' => 'neutral',
        ];

        return [
            'title' => __('messages.wallet_event_credit_facility_title'),
            'description' => $description,
            'facts' => $facts,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{
     *     title: string,
     *     description: string|null,
     *     facts: list<array{label: string, value: string, tone: 'debt'|'positive'|'neutral'}>
     * }
     */
    private function presentAmountEvent(
        string $title,
        string $description,
        array $meta,
        FrontendMoney $money,
        string $currency,
        string $amountTone,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'facts' => $this->genericAmountFacts($meta, $money, $currency, $amountTone),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<array{label: string, value: string, tone: 'debt'|'positive'|'neutral'}>
     */
    private function genericAmountFacts(
        array $meta,
        FrontendMoney $money,
        string $currency,
        string $amountTone = 'neutral',
    ): array {
        $amount = $this->numericOrNull($meta['amount'] ?? null);

        if ($amount === null) {
            return [];
        }

        return [[
            'label' => __('messages.amount'),
            'value' => $money->format($amount, $currency, 2),
            'tone' => $amountTone,
        ]];
    }

    private function creditFacilityDescription(
        ?float $previousLimit,
        ?float $newLimit,
        ?bool $previousEnabled,
        ?bool $newEnabled,
        ?string $previousStatus,
        ?string $newStatus,
        FrontendMoney $money,
        string $currency,
    ): ?string {
        if ($previousEnabled === false && $newEnabled === true) {
            return __('messages.wallet_event_credit_facility_granted');
        }

        if ($previousEnabled === true && $newEnabled === false) {
            return __('messages.wallet_event_credit_facility_removed');
        }

        if ($previousLimit !== null && $newLimit !== null && abs($previousLimit - $newLimit) > 0.00001) {
            return __('messages.wallet_event_credit_facility_limit_changed', [
                'from' => $money->format($previousLimit, $currency, 2),
                'to' => $money->format($newLimit, $currency, 2),
            ]);
        }

        if ($previousStatus !== $newStatus && $newStatus !== null) {
            return __('messages.wallet_event_credit_facility_status_changed', [
                'status' => $this->creditStatusLabel($newEnabled, $newStatus),
            ]);
        }

        return __('messages.wallet_event_credit_facility_updated_description');
    }

    private function creditStatusLabel(?bool $enabled, ?string $status): string
    {
        if ($enabled === false || ($enabled === null && $status === null)) {
            return __('messages.credit_facility_status_none');
        }

        return match ($status) {
            CreditFacilityStatus::Active->value => __('messages.wallet_credit_status_active'),
            CreditFacilityStatus::Suspended->value => __('messages.wallet_credit_status_suspended'),
            default => __('messages.credit_facility_status_none'),
        };
    }

    private function fallbackTitle(string $eventType): string
    {
        $key = 'messages.wallet_event_'.str_replace('.', '_', $eventType);

        if (trans()->has($key)) {
            return __($key);
        }

        return __('messages.wallet_event_generic_title');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function currency(array $meta): string
    {
        $currency = strtoupper((string) ($meta['currency'] ?? 'USD'));

        return in_array($currency, ['USD', 'TRY'], true) ? $currency : 'USD';
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
