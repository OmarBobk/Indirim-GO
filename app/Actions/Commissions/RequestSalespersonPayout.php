<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\PayoutRequestStatus;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Notifications\SalespersonPayoutRequestedNotification;
use App\Services\NotificationRecipientService;
use App\Services\SalespersonDashboardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RequestSalespersonPayout
{
    /** Eligible total must be strictly greater than this to allow a request (matches dashboard button). */
    public const MIN_ELIGIBLE_EXCLUSIVE = 10.0;

    /**
     * Creates at most one pending payout request per salesperson; notifies admins once per new request.
     *
     * @return 'below_min'|'already_pending'|'created'
     */
    public function handle(User $salesperson): string
    {
        $eligible = app(SalespersonDashboardService::class)->eligiblePendingPayoutTotal((int) $salesperson->id);

        if ($eligible <= self::MIN_ELIGIBLE_EXCLUSIVE) {
            return 'below_min';
        }

        $currency = (string) ($salesperson->preferred_currency ?? 'USD');

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
                        'eligible_amount' => (string) $eligible,
                        'currency' => $currency,
                    ])
                    ->log('Salesperson requested payout');
            }

            foreach (app(NotificationRecipientService::class)->adminUsers() as $admin) {
                $admin->notify(SalespersonPayoutRequestedNotification::forPayoutRequest($request, $salesperson));
            }

            return 'created';
        });
    }
}
