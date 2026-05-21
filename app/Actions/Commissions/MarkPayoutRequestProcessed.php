<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\PayoutRequestStatus;
use App\Models\PayoutRequest;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class MarkPayoutRequestProcessed
{
    public function handle(PayoutRequest $request, User $admin): void
    {
        if ($request->status !== PayoutRequestStatus::Pending) {
            return;
        }

        $request->update([
            'status' => PayoutRequestStatus::Processed,
            'processed_at' => now(),
            'processed_by' => $admin->id,
        ]);

        if (Schema::hasTable('activity_log')) {
            activity()
                ->inLog('payments')
                ->event('payout_request.processed')
                ->performedOn($request)
                ->causedBy($admin)
                ->withProperties([
                    'payout_request_id' => $request->id,
                    'user_id' => $request->user_id,
                    'eligible_amount' => (string) $request->eligible_amount,
                    'currency' => $request->currency,
                ])
                ->log('Payout request marked processed');
        }
    }
}
