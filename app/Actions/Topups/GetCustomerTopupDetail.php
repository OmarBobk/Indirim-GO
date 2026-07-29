<?php

declare(strict_types=1);

namespace App\Actions\Topups;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\DTOs\Topups\CustomerTopupDetailDTO;
use App\Enums\FinancialDestinationType;
use App\Enums\TopupRequestStatus;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\TopupRequestPublicRef;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Owned customer top-up detail read model (M6.3).
 */
final class GetCustomerTopupDetail
{
    public function handle(User $user, string $publicRef): CustomerTopupDetailDTO
    {
        $normalized = TopupRequestPublicRef::normalize($publicRef);

        if (! TopupRequestPublicRef::isValidFormat($normalized)) {
            throw new NotFoundHttpException;
        }

        $request = TopupRequest::query()
            ->where('user_id', $user->id)
            ->where('public_ref', $normalized)
            ->with([
                'paymentMethod:id,name,account_text',
                'proofs:id,topup_request_id',
                'walletTransaction:id,reference_type,reference_id,status,public_ref,posted_at,amount,created_at',
            ])
            ->first();

        if ($request === null) {
            throw new NotFoundHttpException;
        }

        return $this->map($request);
    }

    private function map(TopupRequest $request): CustomerTopupDetailDTO
    {
        $tx = $request->walletTransaction;
        $posted = $tx instanceof WalletTransaction
            && $tx->status === WalletTransaction::STATUS_POSTED;
        $postedRef = $posted && filled($tx->public_ref) ? (string) $tx->public_ref : null;

        $isApproved = $request->status === TopupRequestStatus::Approved;
        $moneyMoved = $isApproved && $posted;
        $isAnomaly = $isApproved && ! $posted;
        $canRetry = $request->status === TopupRequestStatus::Rejected;

        $submittedAt = $request->created_at instanceof Carbon
            ? $request->created_at
            : Carbon::parse((string) $request->created_at);

        $reviewedAt = null;
        if ($request->status === TopupRequestStatus::Approved && $request->approved_at instanceof Carbon) {
            $reviewedAt = $request->approved_at;
        } elseif ($request->status === TopupRequestStatus::Rejected) {
            $reviewedAt = $request->updated_at instanceof Carbon
                ? $request->updated_at
                : null;
        }

        $creditedAt = null;
        if ($posted && $tx->posted_at !== null) {
            $creditedAt = $tx->posted_at instanceof Carbon
                ? $tx->posted_at
                : Carbon::parse((string) $tx->posted_at);
        }

        $proof = $request->proofs->first();
        $publicRef = (string) $request->public_ref;
        $reason = is_string($request->note) && trim($request->note) !== ''
            ? trim($request->note)
            : null;

        $timeline = [
            [
                'key' => 'submitted',
                'label_key' => 'topup_timeline_submitted',
                'occurred_at' => $submittedAt->toIso8601String(),
            ],
        ];

        if ($request->proofs->isNotEmpty()) {
            $timeline[] = [
                'key' => 'proof',
                'label_key' => 'topup_timeline_proof_received',
                'occurred_at' => $submittedAt->toIso8601String(),
            ];
        }

        if ($request->status === TopupRequestStatus::Pending) {
            $timeline[] = [
                'key' => 'under_review',
                'label_key' => 'topup_timeline_under_review',
                'occurred_at' => null,
            ];
        }

        if ($reviewedAt !== null && $request->status === TopupRequestStatus::Approved) {
            $timeline[] = [
                'key' => 'reviewed',
                'label_key' => 'topup_timeline_reviewed',
                'occurred_at' => $reviewedAt->toIso8601String(),
            ];
        }

        if ($creditedAt !== null) {
            $timeline[] = [
                'key' => 'credited',
                'label_key' => 'topup_timeline_credited',
                'occurred_at' => $creditedAt->toIso8601String(),
            ];
        }

        if ($request->status === TopupRequestStatus::Rejected && $reviewedAt !== null) {
            $timeline[] = [
                'key' => 'rejected',
                'label_key' => 'topup_timeline_rejected',
                'occurred_at' => $reviewedAt->toIso8601String(),
            ];
        }

        if ($request->status === TopupRequestStatus::Cancelled) {
            $timeline[] = [
                'key' => 'cancelled',
                'label_key' => 'topup_timeline_cancelled',
                'occurred_at' => ($request->updated_at instanceof Carbon
                    ? $request->updated_at
                    : $submittedAt)->toIso8601String(),
            ];
        }

        $instructions = $request->paymentMethod?->accountTextPlain();

        return new CustomerTopupDetailDTO(
            publicReference: $publicRef,
            status: $request->status,
            amount: bcadd((string) $request->amount, '0', 2),
            currency: strtoupper((string) ($request->currency ?: 'USD')),
            paymentMethodName: $request->paymentMethod?->name,
            paymentInstructionsPlain: filled($instructions) ? (string) $instructions : null,
            submittedAt: $submittedAt,
            reviewedAt: $reviewedAt,
            creditedAt: $creditedAt,
            moneyMoved: $moneyMoved,
            hasProof: $proof !== null,
            proofId: $proof?->id,
            canRetry: $canRetry,
            isIntegrityAnomaly: $isAnomaly,
            customerSafeReason: $reason,
            postedTransactionPublicRef: $postedRef,
            timeline: $timeline,
            destination: new FinancialDestinationDTO(
                FinancialDestinationType::WalletTopupDetail,
                ['public_ref' => $publicRef]
            ),
            ledgerDestination: $postedRef !== null
                ? new FinancialDestinationDTO(
                    FinancialDestinationType::WalletTransactionsSearch,
                    ['search' => $postedRef]
                )
                : null,
            retryDestination: $canRetry
                ? new FinancialDestinationDTO(
                    FinancialDestinationType::WalletTopup,
                    ['retry' => $publicRef]
                )
                : null,
            proofDestination: $proof !== null
                ? new FinancialDestinationDTO(
                    FinancialDestinationType::TopupProof,
                    ['proof_id' => (string) $proof->id]
                )
                : null,
        );
    }
}
