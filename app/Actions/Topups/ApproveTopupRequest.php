<?php

declare(strict_types=1);

namespace App\Actions\Topups;

use App\DTOs\WalletPosting;
use App\Enums\CustomerActivityInvalidationReason;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\TopupRequestStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Events\TopupRequestsChanged;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\TopupApprovedNotification;
use App\Services\OperationalIntelligenceService;
use App\Services\SystemEventService;
use App\Services\WalletLedger;
use App\Support\CustomerActivityBroadcaster;
use App\Support\CustomerFinancialBroadcaster;
use App\Support\LedgerMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApproveTopupRequest
{
    public function __construct(
        private readonly WalletLedger $ledger = new WalletLedger,
    ) {}

    /**
     * Post the ledger entry and update balance only once via WalletLedger.
     * Lock order: topup request → pending TX → wallet (kernel).
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

            if ($request->status !== TopupRequestStatus::Pending) {
                return $request;
            }

            $transaction = WalletTransaction::query()
                ->where('reference_type', TopupRequest::class)
                ->where('reference_id', $request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->status === WalletTransaction::STATUS_POSTED) {
                $request->fill([
                    'status' => TopupRequestStatus::Approved,
                    'approved_by' => $approvedById,
                    'approved_at' => $request->approved_at ?? now(),
                ])->save();

                return $request;
            }

            if ($transaction->status !== WalletTransaction::STATUS_PENDING) {
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

                $wallet = Wallet::query()
                    ->whereKey($wallet->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            if ($transaction->direction !== WalletTransactionDirection::Credit) {
                throw new \RuntimeException('Top-up transaction must be credit.');
            }

            if ($wallet->currency !== $request->currency) {
                throw new \RuntimeException('Wallet currency does not match top-up request.');
            }

            $amount = LedgerMoney::normalizePositive((string) $transaction->amount);
            $idempotencyKey = 'topup:'.$request->id;

            $result = $this->ledger->post(new WalletPosting(
                wallet: $wallet,
                type: WalletTransactionType::Topup,
                direction: WalletTransactionDirection::Credit,
                amount: $amount,
                idempotencyKey: $idempotencyKey,
                meta: [
                    'approved_by' => $approvedById,
                    'approved_at' => now()->toIso8601String(),
                ],
                referenceType: TopupRequest::class,
                referenceId: (int) $request->id,
                pendingTransaction: $transaction,
            ));

            $transaction = $result->transaction;
            $wallet = $result->wallet;

            $request->fill([
                'status' => TopupRequestStatus::Approved,
                'approved_by' => $approvedById,
                'approved_at' => now(),
            ])->save();

            if (! $result->wasReplayed) {
                CustomerFinancialBroadcaster::dispatch(
                    (int) $request->user_id,
                    CustomerFinancialInvalidationReason::BalanceChanged,
                );
                CustomerFinancialBroadcaster::dispatch(
                    (int) $request->user_id,
                    CustomerFinancialInvalidationReason::TopupStateChanged,
                );
                CustomerActivityBroadcaster::dispatch(
                    (int) $request->user_id,
                    CustomerActivityInvalidationReason::TopupStateChanged,
                );
            }

            app(SystemEventService::class)->record(
                'wallet.topup.posted',
                $request,
                $actor,
                [
                    'amount' => $amount,
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
                        'amount' => $amount,
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
                        'amount' => $amount,
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
