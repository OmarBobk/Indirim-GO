<?php

declare(strict_types=1);

namespace App\Actions\Topups;

use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\TopupRequestStatus;
use App\Events\TopupRequestsChanged;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\TopupRejectedNotification;
use App\Services\SystemEventService;
use App\Support\CustomerFinancialBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RejectTopupRequest
{
    /**
     * Reject a pending top-up without changing wallet balance.
     * Optionally store a reason in the request's note.
     */
    public function handle(TopupRequest $topupRequest, int $rejectedById, ?string $reason = null): TopupRequest
    {
        return DB::transaction(function () use ($topupRequest, $rejectedById, $reason): TopupRequest {
            $request = TopupRequest::query()
                ->whereKey($topupRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status === TopupRequestStatus::Rejected) {
                return $request;
            }

            if ($request->status !== TopupRequestStatus::Pending) {
                return $request;
            }

            $transaction = WalletTransaction::query()
                ->where('reference_type', TopupRequest::class)
                ->where('reference_id', $request->id)
                ->lockForUpdate()
                ->first();

            if ($transaction !== null && $transaction->status === WalletTransaction::STATUS_PENDING) {
                $transaction->status = WalletTransaction::STATUS_REJECTED;
                $meta = $transaction->meta ?? [];
                if ($reason !== null && $reason !== '') {
                    $meta['note'] = $reason;
                }
                $transaction->meta = $meta;
                $transaction->save();
            }

            $request->fill([
                'status' => TopupRequestStatus::Rejected,
                'note' => $reason !== null && $reason !== '' ? $reason : $request->note,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            if (Schema::hasTable('activity_log')) {
                $properties = [
                    'topup_request_id' => $request->id,
                    'wallet_id' => $request->wallet_id,
                    'user_id' => $request->user_id,
                    'amount' => $request->amount,
                    'currency' => $request->currency,
                    'transaction_id' => $transaction?->id,
                ];
                if ($reason !== null && $reason !== '') {
                    $properties['rejection_reason'] = $reason;
                }
                activity()
                    ->inLog('payments')
                    ->event('topup.rejected')
                    ->performedOn($request)
                    ->causedBy(User::query()->find($rejectedById))
                    ->withProperties($properties)
                    ->log('Topup rejected');
            }

            $rejectedRequestId = $request->id;
            $rejectionReason = $reason;
            $rejectedByIdForEvent = $rejectedById;
            $ownerUserId = (int) $request->user_id;

            CustomerFinancialBroadcaster::dispatch(
                $ownerUserId,
                CustomerFinancialInvalidationReason::TopupStateChanged,
            );

            DB::afterCommit(function () use ($rejectedRequestId, $rejectionReason, $rejectedByIdForEvent): void {
                $rejectedRequest = TopupRequest::query()->find($rejectedRequestId);
                if ($rejectedRequest === null) {
                    return;
                }

                $admin = User::query()->find($rejectedByIdForEvent);
                app(SystemEventService::class)->record(
                    'admin.rejected.topup',
                    $rejectedRequest,
                    $admin,
                    [
                        'amount' => (float) $rejectedRequest->amount,
                        'currency' => $rejectedRequest->currency,
                        'reason' => $rejectionReason,
                    ],
                    'info',
                    false,
                );

                event(new TopupRequestsChanged($rejectedRequest->id, 'status-updated'));

                $owner = $rejectedRequest->user;
                if ($owner !== null) {
                    $owner->notify(TopupRejectedNotification::fromTopupRequest($rejectedRequest, $rejectionReason));
                }
            });

            return $request;
        });
    }
}
