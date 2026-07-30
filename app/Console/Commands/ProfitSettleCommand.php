<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\WalletPosting;
use App\Enums\FulfillmentStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Enums\WalletType;
use App\Models\Fulfillment;
use App\Models\Settlement;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\SettlementCreatedNotification;
use App\Services\NotificationRecipientService;
use App\Services\SettlementProfitCalculator;
use App\Services\SystemEventService;
use App\Services\WalletLedger;
use App\Support\LedgerMoney;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProfitSettleCommand extends Command
{
    protected $signature = 'profit:settle
                            {--dry-run : Log what would be settled without writing}
                            {--until= : Only fulfillments completed before this date (YYYY-MM-DD)}';

    protected $description = 'Settle realized profit from completed fulfillments to platform wallet';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $until = $this->option('until');

        $untilDate = null;
        if ($until !== null && $until !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $until);
            if ($parsed === false) {
                $this->error('Invalid --until format. Use YYYY-MM-DD.');

                return self::FAILURE;
            }
            $untilDate = $parsed->setTime(23, 59, 59);
        }

        $calculator = app(SettlementProfitCalculator::class);

        if ($dryRun) {
            $eligible = $this->eligibleFulfillments($untilDate, false);

            if ($eligible->isEmpty()) {
                $this->info('No eligible fulfillments to settle.');

                return self::SUCCESS;
            }

            $totalAmount = $eligible->sum(fn (Fulfillment $f) => $calculator->forOrderItem($f->orderItem));

            if ($totalAmount <= 0) {
                $this->info('Total profit would be zero or negative. Skipping.');

                return self::SUCCESS;
            }

            $this->info(sprintf(
                'Dry run: would settle %d fulfillment(s), total amount %.2f',
                $eligible->count(),
                $totalAmount
            ));
            foreach ($eligible as $f) {
                $profit = $calculator->forOrderItem($f->orderItem);
                $this->line(sprintf('  Fulfillment #%d (order_item #%d): %.2f', $f->id, $f->order_item_id, $profit));
            }

            return self::SUCCESS;
        }

        return DB::transaction(function () use ($untilDate, $calculator): int {
            $eligible = $this->eligibleFulfillments($untilDate, true);

            if ($eligible->isEmpty()) {
                $this->info('No eligible fulfillments to settle.');

                return self::SUCCESS;
            }

            $totalAmount = $eligible->sum(fn (Fulfillment $f) => $calculator->forOrderItem($f->orderItem));

            if ($totalAmount <= 0) {
                $this->info('Total profit would be zero or negative. Skipping.');

                return self::SUCCESS;
            }

            $settlement = Settlement::create(['total_amount' => $totalAmount]);
            $settlement->fulfillments()->attach($eligible->pluck('id')->all());

            $platformWallet = Wallet::query()
                ->where('type', WalletType::Platform->value)
                ->lockForUpdate()
                ->firstOrFail();

            $idempotencyKey = 'settlement:'.$settlement->id;
            $amount = LedgerMoney::normalizePositive(number_format((float) $totalAmount, 2, '.', ''));

            app(WalletLedger::class)->post(new WalletPosting(
                wallet: $platformWallet,
                type: WalletTransactionType::Settlement,
                direction: WalletTransactionDirection::Credit,
                amount: $amount,
                idempotencyKey: $idempotencyKey,
                meta: [
                    'fulfillment_count' => $eligible->count(),
                ],
                referenceType: Settlement::class,
                referenceId: (int) $settlement->id,
                minimumAllowedBalance: LedgerMoney::ZERO,
            ));

            app(SystemEventService::class)->record(
                'platform.profit.recorded',
                $settlement,
                null,
                [
                    'settlement_id' => $settlement->id,
                    'total_amount' => $amount,
                    'fulfillment_count' => $eligible->count(),
                ],
                'info',
                true,
            );

            $settlementId = $settlement->id;
            $totalAmountForEvent = $amount;
            $fulfillmentCountForEvent = $eligible->count();
            DB::afterCommit(function () use ($settlementId, $totalAmountForEvent, $fulfillmentCountForEvent): void {
                $settlement = Settlement::query()->find($settlementId);
                if ($settlement !== null) {
                    app(SystemEventService::class)->record(
                        'profit.settlement.executed',
                        $settlement,
                        null,
                        [
                            'settlement_id' => $settlement->id,
                            'total_amount' => $totalAmountForEvent,
                            'fulfillment_count' => $fulfillmentCountForEvent,
                        ],
                        'info',
                        false,
                    );
                }
            });

            $notifySettlement = config('notifications.settlement_created_enabled', false);
            DB::afterCommit(function () use ($settlementId, $notifySettlement): void {
                if (! $notifySettlement) {
                    return;
                }
                $settlement = Settlement::query()->find($settlementId);
                if ($settlement !== null) {
                    $notification = SettlementCreatedNotification::fromSettlement($settlement);
                    app(NotificationRecipientService::class)->adminUsers()->each(fn ($admin) => $admin->notify($notification));
                }
            });

            $this->info(sprintf(
                'Settled %d fulfillment(s), total %.2f, settlement #%d',
                $eligible->count(),
                $totalAmount,
                $settlement->id
            ));

            return self::SUCCESS;
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, Fulfillment>
     */
    private function eligibleFulfillments(?\DateTimeImmutable $until, bool $lockForUpdate): \Illuminate\Support\Collection
    {
        $query = Fulfillment::query()
            ->where('status', FulfillmentStatus::Completed)
            ->whereDoesntHave('settlements')
            ->whereHas('orderItem', fn ($q) => $q->whereNotNull('entry_price'))
            ->with(['orderItem']);

        if ($until !== null) {
            $query->where('completed_at', '<=', $until->format('Y-m-d H:i:s'));
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $fulfillments = $query->get();

        return $fulfillments->filter(fn (Fulfillment $f) => ! $this->hasPostedRefund($f));
    }

    private function hasPostedRefund(Fulfillment $fulfillment): bool
    {
        $directRefund = WalletTransaction::query()
            ->where('type', WalletTransactionType::Refund)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->where('reference_type', Fulfillment::class)
            ->where('reference_id', $fulfillment->id)
            ->exists();

        if ($directRefund) {
            return true;
        }

        $orderRefund = WalletTransaction::query()
            ->where('type', WalletTransactionType::Refund)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->where('reference_type', \App\Models\Order::class)
            ->where('reference_id', $fulfillment->order_id)
            ->exists();

        if ($orderRefund) {
            return true;
        }

        $itemRefunds = WalletTransaction::query()
            ->where('type', WalletTransactionType::Refund)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->where('reference_type', \App\Models\OrderItem::class)
            ->where('reference_id', $fulfillment->order_item_id)
            ->get();

        foreach ($itemRefunds as $tx) {
            $metaFulfillmentId = (int) (is_array($tx->meta) ? ($tx->meta['fulfillment_id'] ?? 0) : 0);
            if ($metaFulfillmentId === $fulfillment->id) {
                return true;
            }
        }

        return false;
    }
}
