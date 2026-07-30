<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\PayoutRequestStatus;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Notifications\SalespersonPayoutRequestedNotification;
use App\Services\NotificationRecipientService;
use App\Support\AdminOpsBroadcaster;
use App\Support\Commissions\SalespersonCommissionEligibility;
use App\Support\CustomerFinancialBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class RequestSalespersonPayout
{
    /**
     * Eligible total must be strictly greater than this to allow a request (matches dashboard button).
     * Distinct from WebsiteSetting::getCommissionPayoutMinAmount() used by CreatePayoutBatch.
     */
    public const MIN_ELIGIBLE_EXCLUSIVE = 10.0;

    public function __construct(
        private readonly SalespersonCommissionEligibility $eligibility = new SalespersonCommissionEligibility,
    ) {}

    /**
     * Creates at most one pending payout request per salesperson; notifies admins once per new request.
     * Does not move wallet money — CreatePayoutBatch credits commissions.
     *
     * @return 'below_min'|'already_pending'|'created'
     */
    public function handle(User $salesperson): string
    {
        abort_unless($salesperson->can('view_referrals'), 403);

        $eligible = $this->eligibility->eligiblePendingTotal($salesperson);

        if (! $this->eligibility->canRequestPayout($eligible)) {
            return 'below_min';
        }

        $currency = 'USD';

        return DB::transaction(function () use ($salesperson, $eligible, $currency): string {
            $existing = PayoutRequest::query()
                ->where('user_id', $salesperson->id)
                ->where('status', PayoutRequestStatus::Pending)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return 'already_pending';
            }

            $request = PayoutRequest::query()->create([
                'user_id' => $salesperson->id,
                'eligible_amount' => $eligible,
                'currency' => $currency,
                'status' => PayoutRequestStatus::Pending,
            ]);

            if (Schema::hasTable('activity_log')) {
                activity()
                    ->inLog('payments')
                    ->event('payout_request.created')
                    ->performedOn($request)
                    ->causedBy($salesperson)
                    ->withProperties([
                        'payout_request_id' => $request->id,
                        'eligible_amount' => $eligible,
                        'currency' => $currency,
                    ])
                    ->log('Salesperson requested payout');
            }

            $requestId = (int) $request->id;
            $salespersonId = (int) $salesperson->id;
            DB::afterCommit(static function () use ($requestId, $salespersonId): void {
                try {
                    $committedRequest = PayoutRequest::query()->find($requestId);
                    $committedSalesperson = User::query()->find($salespersonId);

                    if ($committedRequest === null || $committedSalesperson === null) {
                        return;
                    }

                    foreach (app(NotificationRecipientService::class)->adminUsers() as $admin) {
                        $admin->notify(SalespersonPayoutRequestedNotification::forPayoutRequest(
                            $committedRequest,
                            $committedSalesperson,
                        ));
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Salesperson payout request notification failed', [
                        'payout_request_id' => $requestId,
                        'salesperson_id' => $salespersonId,
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

            AdminOpsBroadcaster::dispatch('payout-requested');

            CustomerFinancialBroadcaster::dispatch(
                (int) $salesperson->id,
                CustomerFinancialInvalidationReason::PayoutRequestStateChanged,
            );

            return 'created';
        });
    }
}
