<?php

declare(strict_types=1);

namespace App\Actions\Topups;

use App\Enums\TopupRequestStatus;
use App\Enums\WalletTransactionDirection;
use App\Events\TopupRequestsChanged;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\TopupApprovedNotification;
use App\Services\OperationalIntelligenceService;
use App\Services\SystemEventService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApproveTopupRequest
{
    /**
     * Post the ledger entry and update balance only once.
     */
    public function handle(User $actor, TopupRequest $topupRequest): TopupRequest
    {
        if (! $actor->can('manage_topups')) {
            throw new AuthorizationException(__('messages.topup_approve_unauthorized'));
        }

        $approvedById = (int) $actor->id;

        return DB::transaction(function () use ($topupRequest, $approvedById, $actor): TopupRequest {
            $request = TopupRequest::query()
                ->whereKey($topupRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status === TopupRequestStatus::Approved) {
                return $request;
            }

            $transaction = WalletTransaction::query()
                ->where('reference_type', TopupRequest::class)
                ->where('reference_id', $request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->status === WalletTransaction::STATUS_POSTED) {
                return $request;
            }

            if ($request->status !== TopupRequestStatus::Pending) {
                return $request;
            }

            $wallet = Wallet::query()
                ->whereKey($request->wallet_id)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                $user = User::query()->find($request->user_id);

                if ($user === null) {
                    throw new \RuntimeException('Wallet user not found.');
                }

                $wallet = Wallet::forUser($user);
                $request->wallet_id = $wallet->id;
                $request->save();
            }

            if ($transaction->direction !== WalletTransactionDirection::Credit) {
                throw new \RuntimeException('Top-up transaction must be credit.');
            }

            if ($transaction->status === WalletTransaction::STATUS_PENDING) {
                if ((float) $transaction->amount <= 0) {
                    throw new \RuntimeException('Top-up amount must be greater than zero.');
                }

                if ($wallet->currency !== $request->currency) {
                    throw new \RuntimeException('Wallet currency does not match top-up request.');
                }

                $transaction->status = WalletTransaction::STATUS_POSTED;
                $transaction->meta = array_merge($transaction->meta ?? [], [
                    'approved_by' => $approvedById,
                    'approved_at' => now()->toIso8601String(),
                ]);
                $transaction->save();

                $wallet->increment('balance', $transaction->amount);
            }

            $request->fill([
                'status' => TopupRequestStatus::Approved,
                'approved_by' => $approvedById,
                'approved_at' => now(),
            ])->save();

            app(SystemEventService::class)->record(
                'wallet.topup.posted',
                $request,
                $actor,
                [
                    'amount' => (float) $request->amount,
                    'wallet_id' => $wallet->id,
                ],
                'info',
                true,
            );

            if (Schema::hasTable('activity_log')) {
                activity()
                    ->inLog('payments')
                    ->event('topup.approved')
                    ->performedOn($request)
                    ->causedBy($actor)
                    ->withProperties([
                        'topup_request_id' => $request->id,
                        'wallet_id' => $request->wallet_id,
                        'user_id' => $request->user_id,
                        'amount' => $request->amount,
                        'currency' => $request->currency,
                        'transaction_id' => $transaction->id,
                    ])
                    ->log('Topup approved');

                activity()
                    ->inLog('payments')
                    ->event('wallet.credited')
                    ->performedOn($wallet)
                    ->causedBy($actor)
                    ->withProperties([
                        'wallet_id' => $wallet->id,
                        'user_id' => $wallet->user_id,
                        'amount' => $transaction->amount,
                        'currency' => $wallet->currency,
                        'transaction_id' => $transaction->id,
                        'source' => 'topup',
                    ])
                    ->log('Wallet credited');
            }

            $approvedRequestId = $request->id;
            $postedTxId = $transaction->id;
            DB::afterCommit(function () use ($approvedRequestId, $postedTxId): void {
                $tx = WalletTransaction::query()->find($postedTxId);
                if ($tx !== null) {
                    app(OperationalIntelligenceService::class)->detectWalletVelocity($tx);
                }

                $approvedRequest = TopupRequest::query()->find($approvedRequestId);
                if ($approvedRequest === null) {
                    return;
                }

                event(new TopupRequestsChanged($approvedRequest->id, 'status-updated'));

                $owner = $approvedRequest->user;
                if ($owner !== null) {
                    $owner->notify(TopupApprovedNotification::fromTopupRequest($approvedRequest));
                }
            });

            return $request;
        });
    }
}
