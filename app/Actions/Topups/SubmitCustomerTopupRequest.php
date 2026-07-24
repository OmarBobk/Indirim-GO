<?php

declare(strict_types=1);

namespace App\Actions\Topups;

use App\Enums\TopupRequestStatus;
use App\Events\TopupRequestsChanged;
use App\Models\TopupProof;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebsiteSetting;
use App\Notifications\TopupRequestedNotification;
use App\Services\NotificationRecipientService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SubmitCustomerTopupRequest
{
    public function __construct(
        private readonly CreateTopupRequestAction $createTopupRequest,
    ) {}

    public function handle(
        User $user,
        float $enteredAmount,
        int $paymentMethodId,
        bool $attachProof,
        ?UploadedFile $proofFile = null,
    ): TopupRequest {
        $wallet = Wallet::forUser($user);
        $requestAmount = $this->normalizeAmountForWalletCurrency($enteredAmount, $user, $wallet);

        $hasPending = TopupRequest::query()
            ->where('user_id', $user->id)
            ->where('status', TopupRequestStatus::Pending)
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages([
                'topupAmount' => __('messages.topup_request_pending'),
            ]);
        }

        return DB::transaction(function () use ($user, $wallet, $paymentMethodId, $requestAmount, $attachProof, $proofFile): TopupRequest {
            $topupRequest = $this->createTopupRequest->handle([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'payment_method_id' => $paymentMethodId,
                'amount' => $requestAmount,
                'currency' => $wallet->currency,
                'status' => TopupRequestStatus::Pending,
            ]);

            if ($attachProof && $proofFile !== null) {
                $this->storeProof($topupRequest, $proofFile);
            }

            activity()
                ->inLog('payments')
                ->event('topup.requested')
                ->performedOn($topupRequest)
                ->causedBy($user)
                ->withProperties([
                    'topup_request_id' => $topupRequest->id,
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'amount' => $topupRequest->amount,
                    'currency' => $wallet->currency,
                    'payment_method_id' => $topupRequest->payment_method_id,
                    'payment_method' => $topupRequest->paymentMethod?->name,
                ])
                ->log('Topup requested');

            $topupRequestId = $topupRequest->id;

            DB::afterCommit(function () use ($topupRequestId): void {
                $request = TopupRequest::query()->find($topupRequestId);

                if ($request === null) {
                    return;
                }

                event(new TopupRequestsChanged($request->id, 'created'));

                $notification = TopupRequestedNotification::fromTopupRequest($request);
                app(NotificationRecipientService::class)
                    ->adminUsers()
                    ->each(fn ($admin) => $admin->notify($notification));
            });

            return $topupRequest->refresh();
        });
    }

    private function storeProof(TopupRequest $topupRequest, UploadedFile $proofFile): void
    {
        $ext = $proofFile->getClientOriginalExtension() ?: $proofFile->guessExtension() ?? 'bin';
        $filename = Str::uuid()->toString().'.'.$ext;
        $dir = 'topups/proofs/'.$topupRequest->id;

        $path = $proofFile->storeAs($dir, $filename, 'local');

        if ($path === false) {
            throw new RuntimeException('Failed to store top-up proof file.');
        }

        TopupProof::create([
            'topup_request_id' => $topupRequest->id,
            'file_path' => $path,
            'file_original_name' => $proofFile->getClientOriginalName(),
            'mime_type' => $proofFile->getMimeType(),
            'size_bytes' => $proofFile->getSize(),
        ]);
    }

    private function normalizeAmountForWalletCurrency(float $enteredAmount, User $user, Wallet $wallet): float
    {
        if (
            strtoupper((string) $user->preferred_currency) === 'TRY'
            && strtoupper((string) $wallet->currency) === 'USD'
        ) {
            $rate = WebsiteSetting::getUsdTryRate();

            if ($rate !== null && $rate > 0) {
                $tryCents = (int) round($enteredAmount * 100);
                $usdCents = (int) ceil($tryCents / $rate);

                return $usdCents / 100;
            }
        }

        return $enteredAmount;
    }
}
