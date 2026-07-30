<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\Actions\Commissions\RequestSalespersonPayout;
use App\Enums\CommissionStatus;
use App\Enums\FulfillmentStatus;
use App\Models\Commission;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\LedgerMoney;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Shared salesperson commission eligibility (M6.6).
 * Mirrors CreatePayoutBatch rules with decimal-safe totals.
 */
final class SalespersonCommissionEligibility
{
    /**
     * Pending commissions past wait window with completed fulfillment(s).
     * Not wallet money until WalletLedger posts a commission_credit.
     */
    public function eligiblePendingTotal(User $salesperson): string
    {
        $waitDays = WebsiteSetting::getCommissionPayoutWaitDays();
        $cutoff = now()->subDays($waitDays);

        $pending = Commission::query()
            ->where('salesperson_id', $salesperson->id)
            ->where('status', CommissionStatus::Pending)
            ->whereNull('payout_batch_id')
            ->whereNull('wallet_transaction_id')
            ->whereHas('order', function ($query) use ($cutoff): void {
                $query->whereNotNull('paid_at')->where('paid_at', '<=', $cutoff);
            })
            ->with([
                'fulfillment:id,status',
                'order:id,paid_at',
                'order.items:id,order_id',
                'order.items.fulfillments:id,order_item_id,status',
            ])
            ->get(['id', 'status', 'commission_amount', 'fulfillment_id', 'order_id', 'payout_batch_id', 'wallet_transaction_id']);

        return $this->sumEligible($pending, $cutoff);
    }

    /**
     * Minimum eligible amount required to request payout (USD decimal string).
     * Request floor is RequestSalespersonPayout::MIN_ELIGIBLE_EXCLUSIVE — not the admin batch min.
     */
    public function minimumRequestThreshold(): string
    {
        return LedgerMoney::normalize(
            number_format(RequestSalespersonPayout::MIN_ELIGIBLE_EXCLUSIVE, 2, '.', '')
        );
    }

    /**
     * Whether eligible total exceeds the request floor (strictly greater, matching legacy button).
     */
    public function canRequestPayout(string $eligibleTotal): bool
    {
        $threshold = $this->minimumRequestThreshold();

        return LedgerMoney::compare($eligibleTotal, $threshold) === 1;
    }

    public function waitDays(): int
    {
        return WebsiteSetting::getCommissionPayoutWaitDays();
    }

    public function isEligible(Commission $commission, ?CarbonInterface $cutoff = null): bool
    {
        $cutoff ??= now()->subDays($this->waitDays());

        if ($commission->status !== CommissionStatus::Pending) {
            return false;
        }

        if ($commission->payout_batch_id !== null || $commission->wallet_transaction_id !== null) {
            return false;
        }

        $paidAt = $commission->order?->paid_at;
        if ($paidAt === null || $paidAt->greaterThan($cutoff)) {
            return false;
        }

        return $this->fulfillmentCompleted($commission);
    }

    /**
     * @param  Collection<int, Commission>  $commissions
     */
    private function sumEligible(Collection $commissions, CarbonInterface $cutoff): string
    {
        $total = '0.00';

        foreach ($commissions as $commission) {
            if (! $this->isEligible($commission, $cutoff)) {
                continue;
            }

            $total = LedgerMoney::add($total, LedgerMoney::normalizePositive((string) $commission->commission_amount));
        }

        return $total;
    }

    private function fulfillmentCompleted(Commission $commission): bool
    {
        if ($commission->fulfillment !== null) {
            return $commission->fulfillment->status === FulfillmentStatus::Completed;
        }

        $order = $commission->order;
        if ($order === null || $order->items->isEmpty()) {
            return false;
        }

        foreach ($order->items as $item) {
            if ($item->fulfillments->isEmpty()) {
                return false;
            }

            $allCompleted = $item->fulfillments->every(
                fn ($fulfillment): bool => $fulfillment->status === FulfillmentStatus::Completed
            );

            if (! $allCompleted) {
                return false;
            }
        }

        return true;
    }
}
