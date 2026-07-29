<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\DTOs\Financial\CustomerTransactionDetailDTO;
use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\FinancialDestinationType;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Enums\WalletType;
use App\Models\Commission;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Financial\ReceiptSnapshot;
use App\Support\TopupRequestPublicRef;
use App\Support\WalletTransactionPublicRef;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Owned posted customer transaction detail read model (M6.5).
 * Snapshot-first source context with safe owned live fallback.
 */
final class GetCustomerTransactionDetail
{
    /**
     * @var list<WalletTransactionType>
     */
    public const DETAIL_TYPES = GetCustomerWalletTransactions::LEDGER_TYPES;

    public function handle(User $user, string $publicRef): CustomerTransactionDetailDTO
    {
        if (! WalletTransactionPublicRef::isValidFormat($publicRef)) {
            abort(404);
        }

        $normalizedRef = WalletTransactionPublicRef::normalize($publicRef);
        $wallet = Wallet::forUser($user);

        abort_unless($wallet->type === WalletType::Customer, 404);
        abort_unless((int) $wallet->user_id === (int) $user->id, 404);

        $tx = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('public_ref', $normalizedRef)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->whereIn('type', self::DETAIL_TYPES)
            ->first([
                'id',
                'wallet_id',
                'type',
                'direction',
                'amount',
                'status',
                'reference_type',
                'reference_id',
                'public_ref',
                'posted_at',
                'created_at',
                'meta',
            ]);

        if ($tx === null) {
            abort(404);
        }

        return $this->mapDetail($user, $wallet, $tx);
    }

    private function mapDetail(User $user, Wallet $wallet, WalletTransaction $tx): CustomerTransactionDetailDTO
    {
        $meta = is_array($tx->meta) ? $tx->meta : [];
        $receipt = ReceiptSnapshot::fromMeta($meta) ?? [];

        $balanceBefore = $this->metaMoney($meta, ['balance_before', 'previous_balance']);
        $balanceAfter = $this->metaMoney($meta, ['balance_after', 'new_balance']);
        $hasBalanceSnapshots = $balanceBefore !== null && $balanceAfter !== null;

        $postedAt = $tx->posted_at instanceof Carbon
            ? $tx->posted_at
            : ($tx->created_at instanceof Carbon
                ? $tx->created_at
                : Carbon::parse((string) ($tx->posted_at ?? $tx->created_at ?? now())));

        $isCredit = $tx->direction === WalletTransactionDirection::Credit;
        $typeKnown = in_array($tx->type, self::DETAIL_TYPES, true);

        $source = $this->resolveSource($user, $tx, $meta, $receipt);
        $anomaly = $this->classifyAnomaly($tx, $meta, $hasBalanceSnapshots, $typeKnown, $source);

        if ($anomaly) {
            Log::warning('customer.transaction_detail.integrity_anomaly', [
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'public_ref' => $tx->public_ref,
                'type' => $tx->type?->value,
                'flags' => $anomaly['flags'],
            ]);
        }

        return new CustomerTransactionDetailDTO(
            stableKey: 'wtx:'.(string) $tx->id,
            publicReference: (string) $tx->public_ref,
            transactionType: $tx->type,
            direction: $tx->direction,
            status: WalletTransaction::STATUS_POSTED,
            amount: bcadd((string) $tx->amount, '0', 2),
            currency: ReceiptSnapshot::string($meta, 'currency')
                ?? (is_string($meta['currency'] ?? null) ? strtoupper(trim((string) $meta['currency'])) : 'USD'),
            postedAt: $postedAt,
            balanceBefore: $balanceBefore,
            balanceAfter: $balanceAfter,
            moneyIn: $isCredit,
            hasBalanceSnapshots: $hasBalanceSnapshots,
            isIntegrityAnomaly: $anomaly !== null,
            sourceTitle: $source['title'],
            sourceDescription: $source['description'],
            relatedOrderNumber: $source['order_number'],
            relatedTopupPublicRef: $source['topup_public_ref'],
            relatedRefundPublicRef: $source['refund_public_ref'],
            paymentMethodName: $source['payment_method'],
            productLabel: $source['product_label'],
            customerSafeReason: $source['customer_safe_reason'],
            timeline: $this->buildTimeline($tx, $postedAt, $meta),
            sourceDestination: $source['destination'],
            listDestination: new FinancialDestinationDTO(FinancialDestinationType::WalletTransactions),
            receiptVersion: ReceiptSnapshot::version($meta),
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $receipt
     * @return array{
     *     title: ?string,
     *     description: ?string,
     *     order_number: ?string,
     *     topup_public_ref: ?string,
     *     refund_public_ref: ?string,
     *     payment_method: ?string,
     *     product_label: ?string,
     *     customer_safe_reason: ?string,
     *     destination: ?FinancialDestinationDTO,
     *     source_missing: bool,
     *     foreign_source: bool
     * }
     */
    private function resolveSource(User $user, WalletTransaction $tx, array $meta, array $receipt): array
    {
        $orderNumber = $this->stringFrom($receipt['order_number'] ?? null)
            ?? $this->stringFrom($meta['order_number'] ?? null);
        $topupRef = $this->stringFrom($receipt['topup_public_ref'] ?? null)
            ?? $this->stringFrom($meta['topup_public_ref'] ?? null);
        $refundRef = $this->stringFrom($receipt['refund_public_ref'] ?? null)
            ?? ($tx->type === WalletTransactionType::Refund ? (string) $tx->public_ref : null);
        $paymentMethod = $this->stringFrom($receipt['payment_method'] ?? null)
            ?? $this->stringFrom($meta['payment_method'] ?? null);
        $productLabel = $this->stringFrom($receipt['product_label'] ?? null)
            ?? $this->stringFrom($meta['product_name'] ?? null)
            ?? $this->stringFrom($meta['product_label'] ?? null);
        $safeReason = $this->stringFrom($receipt['customer_safe_reason'] ?? null)
            ?? ($tx->type === WalletTransactionType::Adjustment
                ? $this->stringFrom($meta['reason'] ?? null)
                : null);
        $title = $this->stringFrom($receipt['source_title'] ?? null);
        $description = $this->stringFrom($receipt['source_description'] ?? null);

        $sourceMissing = false;
        $foreignSource = false;
        $destination = null;

        return match ($tx->type) {
            WalletTransactionType::Purchase => $this->resolvePurchaseSource(
                $user,
                $tx,
                $orderNumber,
                $productLabel,
                $title,
                $description,
                $sourceMissing,
                $foreignSource,
            ),
            WalletTransactionType::Topup => $this->resolveTopupSource(
                $user,
                $tx,
                $topupRef,
                $paymentMethod,
                $title,
                $description,
                $sourceMissing,
                $foreignSource,
            ),
            WalletTransactionType::Refund => $this->resolveRefundSource(
                $user,
                $tx,
                $meta,
                $orderNumber,
                $productLabel,
                $refundRef,
                $title,
                $description,
                $sourceMissing,
                $foreignSource,
            ),
            WalletTransactionType::Adjustment => [
                'title' => $title,
                'description' => $description ?? $safeReason,
                'order_number' => null,
                'topup_public_ref' => null,
                'refund_public_ref' => null,
                'payment_method' => null,
                'product_label' => null,
                'customer_safe_reason' => $safeReason !== null ? mb_substr($safeReason, 0, 120) : null,
                'destination' => null,
                'source_missing' => false,
                'foreign_source' => false,
            ],
            WalletTransactionType::CommissionCredit => $this->resolveCommissionSource(
                $user,
                $tx,
                $orderNumber,
                $title,
                $description,
                $sourceMissing,
                $foreignSource,
            ),
            default => [
                'title' => $title,
                'description' => $description,
                'order_number' => $orderNumber,
                'topup_public_ref' => $topupRef,
                'refund_public_ref' => $refundRef,
                'payment_method' => $paymentMethod,
                'product_label' => $productLabel,
                'customer_safe_reason' => $safeReason,
                'destination' => null,
                'source_missing' => true,
                'foreign_source' => false,
            ],
        };
    }

    /**
     * @return array{
     *     title: ?string,
     *     description: ?string,
     *     order_number: ?string,
     *     topup_public_ref: ?string,
     *     refund_public_ref: ?string,
     *     payment_method: ?string,
     *     product_label: ?string,
     *     customer_safe_reason: ?string,
     *     destination: ?FinancialDestinationDTO,
     *     source_missing: bool,
     *     foreign_source: bool
     * }
     */
    private function resolvePurchaseSource(
        User $user,
        WalletTransaction $tx,
        ?string $orderNumber,
        ?string $productLabel,
        ?string $title,
        ?string $description,
        bool $sourceMissing,
        bool $foreignSource,
    ): array {
        $destination = null;
        $liveOrder = null;

        if ($tx->reference_type === Order::class && is_numeric($tx->reference_id)) {
            $liveOrder = Order::query()
                ->whereKey((int) $tx->reference_id)
                ->first(['id', 'user_id', 'order_number']);

            if ($liveOrder === null) {
                $sourceMissing = true;
            } elseif ((int) $liveOrder->user_id !== (int) $user->id) {
                $foreignSource = true;
                $liveOrder = null;
            } else {
                $orderNumber ??= (string) $liveOrder->order_number;
                if ($productLabel === null) {
                    $productLabel = $this->ownedOrderProductSummary($liveOrder);
                }
            }
        } elseif ($orderNumber === null) {
            $sourceMissing = true;
        }

        if ($orderNumber !== null && ! $foreignSource) {
            $owned = Order::query()
                ->where('user_id', $user->id)
                ->where('order_number', $orderNumber)
                ->exists();

            if ($owned) {
                $destination = new FinancialDestinationDTO(
                    FinancialDestinationType::OrderDetail,
                    ['order_number' => $orderNumber]
                );
            } else {
                $destination = new FinancialDestinationDTO(FinancialDestinationType::Orders);
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'order_number' => $foreignSource ? null : $orderNumber,
            'topup_public_ref' => null,
            'refund_public_ref' => null,
            'payment_method' => null,
            'product_label' => $foreignSource ? null : $productLabel,
            'customer_safe_reason' => null,
            'destination' => $destination,
            'source_missing' => $sourceMissing || $foreignSource,
            'foreign_source' => $foreignSource,
        ];
    }

    /**
     * @return array{
     *     title: ?string,
     *     description: ?string,
     *     order_number: ?string,
     *     topup_public_ref: ?string,
     *     refund_public_ref: ?string,
     *     payment_method: ?string,
     *     product_label: ?string,
     *     customer_safe_reason: ?string,
     *     destination: ?FinancialDestinationDTO,
     *     source_missing: bool,
     *     foreign_source: bool
     * }
     */
    private function resolveTopupSource(
        User $user,
        WalletTransaction $tx,
        ?string $topupRef,
        ?string $paymentMethod,
        ?string $title,
        ?string $description,
        bool $sourceMissing,
        bool $foreignSource,
    ): array {
        $destination = null;

        if ($tx->reference_type === TopupRequest::class && is_numeric($tx->reference_id)) {
            $request = TopupRequest::query()
                ->whereKey((int) $tx->reference_id)
                ->with('paymentMethod:id,name')
                ->first(['id', 'user_id', 'public_ref', 'payment_method_id']);

            if ($request === null) {
                $sourceMissing = true;
            } elseif ((int) $request->user_id !== (int) $user->id) {
                $foreignSource = true;
            } else {
                $topupRef ??= is_string($request->public_ref) ? $request->public_ref : null;
                $paymentMethod ??= $request->paymentMethod?->name;
            }
        } elseif ($topupRef === null) {
            $sourceMissing = true;
        }

        if ($topupRef !== null && TopupRequestPublicRef::isValidFormat($topupRef) && ! $foreignSource) {
            $owned = TopupRequest::query()
                ->where('user_id', $user->id)
                ->where('public_ref', TopupRequestPublicRef::normalize($topupRef))
                ->exists();

            if ($owned) {
                $destination = new FinancialDestinationDTO(
                    FinancialDestinationType::WalletTopupDetail,
                    ['public_ref' => TopupRequestPublicRef::normalize($topupRef)]
                );
            } else {
                $destination = new FinancialDestinationDTO(FinancialDestinationType::WalletTopups);
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'order_number' => null,
            'topup_public_ref' => $foreignSource ? null : ($topupRef !== null && TopupRequestPublicRef::isValidFormat($topupRef)
                ? TopupRequestPublicRef::normalize($topupRef)
                : null),
            'refund_public_ref' => null,
            'payment_method' => $foreignSource ? null : $paymentMethod,
            'product_label' => null,
            'customer_safe_reason' => null,
            'destination' => $destination,
            'source_missing' => $sourceMissing || $foreignSource,
            'foreign_source' => $foreignSource,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{
     *     title: ?string,
     *     description: ?string,
     *     order_number: ?string,
     *     topup_public_ref: ?string,
     *     refund_public_ref: ?string,
     *     payment_method: ?string,
     *     product_label: ?string,
     *     customer_safe_reason: ?string,
     *     destination: ?FinancialDestinationDTO,
     *     source_missing: bool,
     *     foreign_source: bool
     * }
     */
    private function resolveRefundSource(
        User $user,
        WalletTransaction $tx,
        array $meta,
        ?string $orderNumber,
        ?string $productLabel,
        ?string $refundRef,
        ?string $title,
        ?string $description,
        bool $sourceMissing,
        bool $foreignSource,
    ): array {
        $orderId = is_numeric($meta['order_id'] ?? null) ? (int) $meta['order_id'] : null;
        if ($orderId === null && $tx->reference_type === Order::class && is_numeric($tx->reference_id)) {
            $orderId = (int) $tx->reference_id;
        }

        if ($orderId === null && $tx->reference_type === Fulfillment::class && is_numeric($tx->reference_id)) {
            $fulfillment = Fulfillment::query()->whereKey((int) $tx->reference_id)->first(['id', 'order_id', 'order_item_id']);
            if ($fulfillment !== null) {
                $orderId = (int) $fulfillment->order_id;
                if ($productLabel === null && $fulfillment->order_item_id !== null) {
                    $item = OrderItem::query()->whereKey((int) $fulfillment->order_item_id)->first(['id', 'name']);
                    $productLabel = $item?->name;
                }
            }
        }

        if ($orderId !== null) {
            $order = Order::query()->whereKey($orderId)->first(['id', 'user_id', 'order_number']);
            if ($order === null) {
                $sourceMissing = true;
            } elseif ((int) $order->user_id !== (int) $user->id) {
                $foreignSource = true;
                $orderNumber = null;
            } else {
                $orderNumber ??= (string) $order->order_number;
            }
        } elseif ($orderNumber === null) {
            $sourceMissing = true;
        }

        $destination = null;
        if ($refundRef !== null && WalletTransactionPublicRef::isValidFormat($refundRef) && ! $foreignSource) {
            $destination = new FinancialDestinationDTO(
                FinancialDestinationType::WalletRefundDetail,
                ['public_ref' => WalletTransactionPublicRef::normalize($refundRef)]
            );
        }

        return [
            'title' => $title,
            'description' => $description,
            'order_number' => $foreignSource ? null : $orderNumber,
            'topup_public_ref' => null,
            'refund_public_ref' => $foreignSource ? null : $refundRef,
            'payment_method' => null,
            'product_label' => $foreignSource ? null : $productLabel,
            'customer_safe_reason' => null,
            'destination' => $destination,
            'source_missing' => $sourceMissing || $foreignSource,
            'foreign_source' => $foreignSource,
        ];
    }

    /**
     * @return array{
     *     title: ?string,
     *     description: ?string,
     *     order_number: ?string,
     *     topup_public_ref: ?string,
     *     refund_public_ref: ?string,
     *     payment_method: ?string,
     *     product_label: ?string,
     *     customer_safe_reason: ?string,
     *     destination: ?FinancialDestinationDTO,
     *     source_missing: bool,
     *     foreign_source: bool
     * }
     */
    private function resolveCommissionSource(
        User $user,
        WalletTransaction $tx,
        ?string $orderNumber,
        ?string $title,
        ?string $description,
        bool $sourceMissing,
        bool $foreignSource,
    ): array {
        if ($tx->reference_type === Commission::class && is_numeric($tx->reference_id)) {
            $commission = Commission::query()
                ->whereKey((int) $tx->reference_id)
                ->first(['id', 'salesperson_id', 'order_id']);

            if ($commission === null) {
                $sourceMissing = true;
            } elseif ((int) $commission->salesperson_id !== (int) $user->id) {
                $foreignSource = true;
            } elseif ($orderNumber === null && $commission->order_id !== null) {
                $orderNumber = Order::query()
                    ->whereKey((int) $commission->order_id)
                    ->value('order_number');
                $orderNumber = is_string($orderNumber) ? $orderNumber : null;
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'order_number' => $foreignSource ? null : $orderNumber,
            'topup_public_ref' => null,
            'refund_public_ref' => null,
            'payment_method' => null,
            'product_label' => null,
            'customer_safe_reason' => null,
            'destination' => $foreignSource
                ? null
                : new FinancialDestinationDTO(FinancialDestinationType::SalespersonDashboard),
            'source_missing' => $sourceMissing || $foreignSource,
            'foreign_source' => $foreignSource,
        ];
    }

    private function ownedOrderProductSummary(Order $order): ?string
    {
        $names = OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->limit(3)
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => mb_substr(trim($name), 0, 80))
            ->values()
            ->all();

        if ($names === []) {
            return null;
        }

        $label = implode(', ', $names);
        $count = OrderItem::query()->where('order_id', $order->id)->count();
        if ($count > 3) {
            $label .= '…';
        }

        return mb_substr($label, 0, 200);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array{
     *     source_missing: bool,
     *     foreign_source: bool
     * }  $source
     * @return array{flags: list<string>}|null
     */
    private function classifyAnomaly(
        WalletTransaction $tx,
        array $meta,
        bool $hasBalanceSnapshots,
        bool $typeKnown,
        array $source,
    ): ?array {
        $flags = [];

        if (! is_string($tx->public_ref) || $tx->public_ref === '') {
            $flags[] = 'missing_public_ref';
        }

        if ($tx->posted_at === null) {
            $flags[] = 'missing_posted_at';
        }

        if (! $hasBalanceSnapshots) {
            $flags[] = 'missing_balance_snapshots';
        }

        if (! $typeKnown) {
            $flags[] = 'unknown_type';
        }

        if ($source['foreign_source']) {
            $flags[] = 'foreign_source';
        }

        if ($source['source_missing']) {
            $flags[] = 'missing_source';
        }

        $impossible = (
            $tx->type === WalletTransactionType::Purchase && $tx->direction !== WalletTransactionDirection::Debit
        ) || (
            in_array($tx->type, [
                WalletTransactionType::Topup,
                WalletTransactionType::Refund,
                WalletTransactionType::CommissionCredit,
            ], true) && $tx->direction !== WalletTransactionDirection::Credit
        );

        if ($impossible) {
            $flags[] = 'impossible_direction_type';
        }

        if ($meta !== [] && ! is_array($tx->meta)) {
            $flags[] = 'malformed_metadata';
        }

        return $flags === [] ? null : ['flags' => $flags];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<array{key: string, label_key: string, occurred_at: string|null}>
     */
    private function buildTimeline(WalletTransaction $tx, Carbon $postedAt, array $meta): array
    {
        $createdAt = $tx->created_at instanceof Carbon
            ? $tx->created_at->toIso8601String()
            : null;

        $timeline = [];

        if ($createdAt !== null && $tx->created_at !== null && ! $tx->created_at->equalTo($postedAt)) {
            $timeline[] = [
                'key' => 'created',
                'label_key' => 'messages.transaction_timeline_recorded',
                'occurred_at' => $createdAt,
            ];
        }

        $timeline[] = [
            'key' => 'posted',
            'label_key' => 'messages.transaction_timeline_posted',
            'occurred_at' => $postedAt->toIso8601String(),
        ];

        return $timeline;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $keys
     */
    private function metaMoney(array $meta, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $meta[$key] ?? null;
            if (is_numeric($value)) {
                return bcadd((string) $value, '0', 2);
            }
        }

        return null;
    }

    private function stringFrom(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
