<?php

declare(strict_types=1);

namespace App\Actions\Topups;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\DTOs\Topups\CustomerTopupFilters;
use App\DTOs\Topups\CustomerTopupPageDTO;
use App\DTOs\Topups\CustomerTopupRequestDTO;
use App\Enums\FinancialDestinationType;
use App\Enums\TopupRequestStatus;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WebsiteSetting;
use App\Support\TopupRequestPublicRef;
use Illuminate\Support\Carbon;

/**
 * Customer top-up workspace list read model (M6.3).
 */
final class GetCustomerTopupRequests
{
    public function handle(User $user, CustomerTopupFilters $filters): CustomerTopupPageDTO
    {
        $query = TopupRequest::query()
            ->where('user_id', $user->id)
            ->with([
                'paymentMethod:id,name',
                'proofs:id,topup_request_id',
                'walletTransaction:id,reference_type,reference_id,status,public_ref,posted_at,amount',
            ]);

        $statuses = $filters->statusEnums();
        if ($statuses !== null) {
            $query->whereIn('status', $statuses);
        }

        if ($filters->filter === 'credited') {
            $query->whereHas('walletTransaction', function ($tx): void {
                $tx->where('status', WalletTransaction::STATUS_POSTED);
            });
        }

        if ($filters->filter === 'needs_action') {
            // Rejected requests are customer-correctable; keep SQL-side filter aligned with canRetry.
            $query->where('status', TopupRequestStatus::Rejected);
        }

        if ($filters->search !== '') {
            $term = TopupRequestPublicRef::normalize($filters->search);
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $query->where('public_ref', 'like', $escaped.'%');
        }

        $paginator = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: $filters->perPage,
                page: $filters->page,
            );

        $items = collect($paginator->items())
            ->map(fn (TopupRequest $request): CustomerTopupRequestDTO => $this->map($request))
            ->all();

        $pendingRef = TopupRequest::query()
            ->where('user_id', $user->id)
            ->where('status', TopupRequestStatus::Pending)
            ->orderByDesc('id')
            ->value('public_ref');

        return new CustomerTopupPageDTO(
            items: $items,
            filters: $filters,
            currentPage: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            lastPage: $paginator->lastPage(),
            pricesVisible: WebsiteSetting::getPricesVisible(),
            pendingTopupPublicRef: is_string($pendingRef) && $pendingRef !== '' ? $pendingRef : null,
        );
    }

    private function map(TopupRequest $request): CustomerTopupRequestDTO
    {
        $tx = $request->walletTransaction;
        $posted = $tx instanceof WalletTransaction
            && $tx->status === WalletTransaction::STATUS_POSTED;
        $postedRef = $posted && filled($tx->public_ref) ? (string) $tx->public_ref : null;

        $isApproved = $request->status === TopupRequestStatus::Approved;
        $moneyMoved = $isApproved && $posted;
        $isAnomaly = $isApproved && ! $posted;
        $canRetry = $request->status === TopupRequestStatus::Rejected;

        $publicRef = filled($request->public_ref)
            ? (string) $request->public_ref
            : 'TUP-PENDING';

        $reason = is_string($request->note) && trim($request->note) !== ''
            ? trim($request->note)
            : null;

        return new CustomerTopupRequestDTO(
            stableKey: 'topup:'.$publicRef,
            publicReference: $publicRef,
            status: $request->status,
            amount: bcadd((string) $request->amount, '0', 2),
            currency: strtoupper((string) ($request->currency ?: 'USD')),
            submittedAt: $request->created_at instanceof Carbon
                ? $request->created_at
                : Carbon::parse((string) $request->created_at),
            updatedAt: $request->updated_at instanceof Carbon
                ? $request->updated_at
                : Carbon::parse((string) $request->updated_at),
            approvedAt: $request->approved_at instanceof Carbon ? $request->approved_at : null,
            paymentMethodName: $request->paymentMethod?->name,
            hasProof: $request->proofs->isNotEmpty(),
            moneyMoved: $moneyMoved,
            canRetry: $canRetry,
            isIntegrityAnomaly: $isAnomaly,
            postedTransactionPublicRef: $postedRef,
            customerSafeReason: $reason,
            destination: new FinancialDestinationDTO(
                FinancialDestinationType::WalletTopupDetail,
                ['public_ref' => $publicRef]
            ),
        );
    }
}
