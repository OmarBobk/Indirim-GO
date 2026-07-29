<?php

declare(strict_types=1);

namespace App\Support\Financial;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\DTOs\Financial\PendingFinancialItemDTO;
use App\Enums\FinancialDestinationType;
use App\Enums\FinancialPendingActor;
use App\Enums\FulfillmentStatus;
use App\Enums\TopupRequestStatus;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;

/**
 * Unresolved customer financial workflows for the overview pending summary.
 *
 * @phpstan-type PendingBundle array{
 *     items: list<PendingFinancialItemDTO>,
 *     has_more: bool,
 *     counts: array{pending_topups: int, pending_refunds: int, needs_customer_action: int}
 * }
 */
final class CustomerPendingFinancialReader
{
    public const FETCH_LIMIT = 12;

    public const DISPLAY_LIMIT = 3;

    /**
     * @return array{
     *     items: list<PendingFinancialItemDTO>,
     *     has_more: bool,
     *     counts: array{pending_topups: int, pending_refunds: int, needs_customer_action: int}
     * }
     */
    public function handle(User $user, Wallet $wallet): array
    {
        abort_unless((int) $wallet->user_id === (int) $user->id, 403);

        $items = [
            ...$this->pendingTopups($user),
            ...$this->rejectedTopups($user),
            ...$this->pendingRefunds($wallet),
            ...$this->rejectedRefunds($wallet),
            ...$this->debtRecoveryItem($wallet),
        ];

        usort(
            $items,
            static function (PendingFinancialItemDTO $a, PendingFinancialItemDTO $b): int {
                $actorRank = static fn (FinancialPendingActor $actor): int => match ($actor) {
                    FinancialPendingActor::NeedsCustomer => 0,
                    FinancialPendingActor::WaitingStaff => 1,
                    FinancialPendingActor::Informational => 2,
                };

                $rank = $actorRank($a->actor) <=> $actorRank($b->actor);
                if ($rank !== 0) {
                    return $rank;
                }

                return $b->occurredAt->getTimestamp() <=> $a->occurredAt->getTimestamp();
            }
        );

        $pendingTopups = 0;
        $pendingRefunds = 0;
        $needsAction = 0;

        foreach ($items as $item) {
            if ($item->kind === 'topup_pending') {
                $pendingTopups++;
            }
            if ($item->kind === 'refund_pending') {
                $pendingRefunds++;
            }
            if ($item->actor === FinancialPendingActor::NeedsCustomer) {
                $needsAction++;
            }
        }

        $hasMore = count($items) > self::DISPLAY_LIMIT;

        return [
            'items' => array_slice($items, 0, self::DISPLAY_LIMIT),
            'has_more' => $hasMore,
            'counts' => [
                'pending_topups' => $pendingTopups,
                'pending_refunds' => $pendingRefunds,
                'needs_customer_action' => $needsAction,
            ],
        ];
    }

    /**
     * @return list<PendingFinancialItemDTO>
     */
    private function pendingTopups(User $user): array
    {
        return TopupRequest::query()
            ->where('user_id', $user->id)
            ->where('status', TopupRequestStatus::Pending)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::FETCH_LIMIT)
            ->get(['id', 'public_ref', 'amount', 'currency', 'status', 'created_at', 'updated_at'])
            ->map(function (TopupRequest $request): PendingFinancialItemDTO {
                $publicRef = filled($request->public_ref) ? (string) $request->public_ref : null;

                return new PendingFinancialItemDTO(
                    id: 'topup-pending:'.$request->id,
                    kind: 'topup_pending',
                    actor: FinancialPendingActor::WaitingStaff,
                    titleKey: 'financial_pending_topup_waiting',
                    amount: bcadd((string) $request->amount, '0', 2),
                    currency: strtoupper((string) ($request->currency ?: 'USD')),
                    occurredAt: $request->updated_at instanceof Carbon
                        ? $request->updated_at
                        : Carbon::parse((string) ($request->updated_at ?? $request->created_at)),
                    destination: $publicRef !== null
                        ? new FinancialDestinationDTO(
                            FinancialDestinationType::WalletTopupDetail,
                            ['public_ref' => $publicRef]
                        )
                        : new FinancialDestinationDTO(FinancialDestinationType::WalletTopups),
                );
            })
            ->all();
    }

    /**
     * @return list<PendingFinancialItemDTO>
     */
    private function rejectedTopups(User $user): array
    {
        return TopupRequest::query()
            ->where('user_id', $user->id)
            ->where('status', TopupRequestStatus::Rejected)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::FETCH_LIMIT)
            ->get(['id', 'public_ref', 'amount', 'currency', 'note', 'created_at', 'updated_at'])
            ->map(function (TopupRequest $request): PendingFinancialItemDTO {
                $reason = is_string($request->note) && trim($request->note) !== ''
                    ? trim($request->note)
                    : null;
                $publicRef = filled($request->public_ref) ? (string) $request->public_ref : null;

                return new PendingFinancialItemDTO(
                    id: 'topup-rejected:'.$request->id,
                    kind: 'topup_rejected',
                    actor: FinancialPendingActor::NeedsCustomer,
                    titleKey: 'financial_pending_topup_action',
                    amount: bcadd((string) $request->amount, '0', 2),
                    currency: strtoupper((string) ($request->currency ?: 'USD')),
                    occurredAt: $request->updated_at instanceof Carbon
                        ? $request->updated_at
                        : Carbon::parse((string) ($request->updated_at ?? $request->created_at)),
                    destination: $publicRef !== null
                        ? new FinancialDestinationDTO(
                            FinancialDestinationType::WalletTopupDetail,
                            ['public_ref' => $publicRef]
                        )
                        : new FinancialDestinationDTO(FinancialDestinationType::WalletTopup),
                    customerSafeReason: $reason,
                );
            })
            ->all();
    }

    /**
     * @return list<PendingFinancialItemDTO>
     */
    private function pendingRefunds(Wallet $wallet): array
    {
        return WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransactionType::Refund)
            ->where('status', WalletTransaction::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::FETCH_LIMIT)
            ->get(['id', 'amount', 'meta', 'created_at'])
            ->map(function (WalletTransaction $tx): PendingFinancialItemDTO {
                $orderNumber = $this->safeOrderNumber($tx);
                $destination = $orderNumber !== null
                    ? new FinancialDestinationDTO(
                        FinancialDestinationType::OrderDetail,
                        ['order_number' => $orderNumber]
                    )
                    : new FinancialDestinationDTO(FinancialDestinationType::Orders);

                return new PendingFinancialItemDTO(
                    id: 'refund-pending:'.$tx->id,
                    kind: 'refund_pending',
                    actor: FinancialPendingActor::WaitingStaff,
                    titleKey: 'financial_pending_refund_waiting',
                    amount: bcadd((string) $tx->amount, '0', 2),
                    currency: strtoupper((string) (data_get($tx->meta, 'currency') ?: 'USD')),
                    occurredAt: $tx->created_at instanceof Carbon
                        ? $tx->created_at
                        : Carbon::parse((string) $tx->created_at),
                    destination: $destination,
                    referenceLabel: $orderNumber,
                );
            })
            ->all();
    }

    /**
     * @return list<PendingFinancialItemDTO>
     */
    private function rejectedRefunds(Wallet $wallet): array
    {
        $transactions = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransactionType::Refund)
            ->where('status', WalletTransaction::STATUS_REJECTED)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::FETCH_LIMIT)
            ->get(['id', 'amount', 'meta', 'created_at']);

        $fulfillmentIds = $transactions
            ->map(fn (WalletTransaction $tx): mixed => data_get($tx->meta, 'fulfillment_id'))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $failedLookup = $fulfillmentIds === []
            ? []
            : array_fill_keys(
                Fulfillment::query()
                    ->whereIn('id', $fulfillmentIds)
                    ->where('status', FulfillmentStatus::Failed)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
                true
            );

        return $transactions
            ->filter(function (WalletTransaction $tx) use ($failedLookup): bool {
                $fulfillmentId = data_get($tx->meta, 'fulfillment_id');

                if (! is_numeric($fulfillmentId)) {
                    return false;
                }

                return isset($failedLookup[(int) $fulfillmentId]);
            })
            ->map(function (WalletTransaction $tx): PendingFinancialItemDTO {
                $orderNumber = $this->safeOrderNumber($tx);
                $destination = $orderNumber !== null
                    ? new FinancialDestinationDTO(
                        FinancialDestinationType::OrderDetail,
                        ['order_number' => $orderNumber]
                    )
                    : new FinancialDestinationDTO(FinancialDestinationType::Orders);

                return new PendingFinancialItemDTO(
                    id: 'refund-rejected:'.$tx->id,
                    kind: 'refund_rejected',
                    actor: FinancialPendingActor::NeedsCustomer,
                    titleKey: 'financial_pending_refund_action',
                    amount: bcadd((string) $tx->amount, '0', 2),
                    currency: strtoupper((string) (data_get($tx->meta, 'currency') ?: 'USD')),
                    occurredAt: $tx->created_at instanceof Carbon
                        ? $tx->created_at
                        : Carbon::parse((string) $tx->created_at),
                    destination: $destination,
                    referenceLabel: $orderNumber,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @return list<PendingFinancialItemDTO>
     */
    private function debtRecoveryItem(Wallet $wallet): array
    {
        $debt = $wallet->outstandingDebt();

        if (bccomp($debt, '0', 2) !== 1) {
            return [];
        }

        return [
            new PendingFinancialItemDTO(
                id: 'debt:'.$wallet->id,
                kind: 'outstanding_debt',
                actor: FinancialPendingActor::NeedsCustomer,
                titleKey: 'financial_pending_debt_action',
                amount: $debt,
                currency: 'USD',
                occurredAt: $wallet->updated_at instanceof Carbon
                    ? $wallet->updated_at
                    : now(),
                destination: new FinancialDestinationDTO(FinancialDestinationType::WalletTopup),
            ),
        ];
    }

    private function safeOrderNumber(WalletTransaction $tx): ?string
    {
        $orderNumber = data_get($tx->meta, 'order_number');

        return is_string($orderNumber) && trim($orderNumber) !== ''
            ? trim($orderNumber)
            : null;
    }
}
