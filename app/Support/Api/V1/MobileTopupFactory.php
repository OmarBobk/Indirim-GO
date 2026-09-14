<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\DTOs\Financial\WalletTransactionDTO;
use App\DTOs\Topups\CustomerTopupRequestDTO;
use App\Enums\FinancialDestinationType;
use App\Enums\TopupRequestStatus;
use App\Models\PaymentMethod;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\LedgerMoney;

/**
 * Customer-safe mobile wallet/top-up envelopes. Never includes storage paths,
 * admin notes beyond customer-safe rejection text, hashes, or raw models.
 */
final class MobileTopupFactory
{
    public function __construct(
        private readonly MobileMoneyFactory $money,
    ) {}

    public static function forUser(User $user): self
    {
        return new self(MobileMoneyFactory::forUser($user));
    }

    /**
     * @return array<string, mixed>
     */
    public function fromOwnedRequest(TopupRequest $request, User $user): array
    {
        $request->loadMissing([
            'paymentMethod:id,name,account_text,image,is_active',
            'proofs:id,topup_request_id',
            'walletTransaction:id,reference_type,reference_id,status,public_ref,posted_at,amount,meta',
        ]);

        $tx = $request->walletTransaction;
        $posted = $tx instanceof WalletTransaction
            && $tx->status === WalletTransaction::STATUS_POSTED;
        $isApproved = $request->status === TopupRequestStatus::Approved;
        $moneyMoved = $isApproved && $posted;
        $walletAmount = LedgerMoney::normalize((string) $request->amount);
        [$enteredAmount, $enteredCurrency] = $this->enteredAmount($request, $tx, $walletAmount);

        return [
            'public_ref' => (string) $request->public_ref,
            'status' => $request->status->value,
            'pending_until_admin_approval' => $request->status === TopupRequestStatus::Pending,
            'credited' => $moneyMoved,
            'money_moved' => $moneyMoved,
            'can_retry' => $request->status === TopupRequestStatus::Rejected,
            'entered_amount' => $enteredAmount,
            'entered_currency' => $enteredCurrency,
            'entered_display' => $this->formatEntered($enteredAmount, $enteredCurrency),
            'wallet_amount' => $this->money->fromUsdDecimal($walletAmount),
            'payment_method' => $request->paymentMethod !== null
                ? $this->paymentMethodSummary($request->paymentMethod)
                : null,
            'has_proof' => $request->proofs->isNotEmpty(),
            'customer_safe_reason' => $this->customerSafeReason($request),
            'submitted_at' => optional($request->created_at)?->toIso8601String(),
            'reviewed_at' => $this->reviewedAt($request),
            'credited_at' => $posted && $tx->posted_at !== null
                ? $tx->posted_at->toIso8601String()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fromListItem(CustomerTopupRequestDTO $item): array
    {
        return [
            'public_ref' => $item->publicReference,
            'status' => $item->status->value,
            'pending_until_admin_approval' => $item->status === TopupRequestStatus::Pending,
            'credited' => $item->moneyMoved,
            'money_moved' => $item->moneyMoved,
            'can_retry' => $item->canRetry,
            'wallet_amount' => $this->money->fromUsdDecimal($item->amount),
            'payment_method_name' => $item->paymentMethodName,
            'has_proof' => $item->hasProof,
            'submitted_at' => $item->submittedAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fromLedgerItem(WalletTransactionDTO $item): array
    {
        $topupRef = null;
        if ($item->sourceType === 'topup' && $item->sourceDestination !== null
            && $item->sourceDestination->type === FinancialDestinationType::WalletTopupDetail) {
            $candidate = $item->sourceDestination->params['public_ref'] ?? null;
            $topupRef = is_string($candidate) && $candidate !== '' ? $candidate : null;
        }

        return [
            'public_ref' => $item->publicReference,
            'type' => $item->transactionType->value,
            'direction' => $item->direction->value,
            'amount' => $this->money->fromUsdDecimal($item->amount),
            'occurred_at' => $item->occurredAt->toIso8601String(),
            'related_order_number' => $item->relatedOrderNumber,
            'related_topup_public_ref' => $topupRef,
            'customer_safe_description' => $item->customerSafeDescription,
        ];
    }

    /**
     * @return array{id: int, name: string, instructions: string|null, image_url: string|null}
     */
    public function paymentMethodSummary(?PaymentMethod $method): array
    {
        if ($method === null) {
            return [
                'id' => 0,
                'name' => '',
                'instructions' => null,
                'image_url' => null,
            ];
        }

        $instructions = $method->accountTextPlain();

        return [
            'id' => (int) $method->id,
            'name' => (string) $method->name,
            'instructions' => filled($instructions) ? (string) $instructions : null,
            'image_url' => SafePublicAssetUrl::fromRelativePath($method->image),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function enteredAmount(TopupRequest $request, mixed $tx, string $walletAmount): array
    {
        $meta = $tx instanceof WalletTransaction && is_array($tx->meta) ? $tx->meta : [];
        $enteredAmount = $meta['entered_amount'] ?? null;
        $enteredCurrency = $meta['entered_currency'] ?? null;

        if (is_string($enteredAmount) && $enteredAmount !== '' && is_string($enteredCurrency) && $enteredCurrency !== '') {
            return [
                LedgerMoney::normalize($enteredAmount),
                strtoupper($enteredCurrency),
            ];
        }

        return [
            $walletAmount,
            strtoupper((string) ($request->currency ?: 'USD')),
        ];
    }

    /**
     * Format the customer-entered amount in that currency only.
     * Never converts USD↔TRY; Flutter must display this envelope as-is.
     *
     * @return array{currency: string, formatted: string}
     */
    private function formatEntered(string $amount, string $currency): array
    {
        $code = in_array(strtoupper($currency), ['USD', 'TRY'], true) ? strtoupper($currency) : 'USD';
        $normalized = LedgerMoney::normalize($amount);
        $numeric = (float) $normalized;

        if (extension_loaded('intl')) {
            $locale = $code === 'TRY' ? 'tr-TR' : 'en-US';
            $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
            $formatted = $formatter->formatCurrency($numeric, $code);
            if ($formatted !== false) {
                return ['currency' => $code, 'formatted' => $formatted];
            }
        }

        $value = number_format($numeric, 2, '.', ',');

        return [
            'currency' => $code,
            'formatted' => $code === 'TRY' ? $value.' TRY' : '$'.$value,
        ];
    }

    private function customerSafeReason(TopupRequest $request): ?string
    {
        if ($request->status !== TopupRequestStatus::Rejected) {
            return null;
        }

        $reason = is_string($request->note) && trim($request->note) !== ''
            ? trim($request->note)
            : null;

        return $reason;
    }

    private function reviewedAt(TopupRequest $request): ?string
    {
        if ($request->status === TopupRequestStatus::Approved && $request->approved_at !== null) {
            return $request->approved_at->toIso8601String();
        }

        if ($request->status === TopupRequestStatus::Rejected && $request->updated_at !== null) {
            return $request->updated_at->toIso8601String();
        }

        return null;
    }
}
