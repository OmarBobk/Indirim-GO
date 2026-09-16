<?php

declare(strict_types=1);

namespace App\Actions\MobileWallet;

use App\Actions\Topups\SubmitCustomerTopupRequest;
use App\Enums\TopupRequestStatus;
use App\Exceptions\InvalidWalletPostingAmountException;
use App\Exceptions\MobileApiException;
use App\Models\MobileTopupAttempt;
use App\Models\PaymentMethod;
use App\Models\TopupRequest;
use App\Models\User;
use App\Support\Api\V1\MobileTopupFactory;
use App\Support\Api\V1\MobileTopupIdempotency;
use App\Support\LedgerMoney;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitMobileTopup
{
    public function __construct(
        private readonly SubmitCustomerTopupRequest $submit,
        private readonly MobileTopupIdempotency $idempotency,
    ) {}

    /**
     * @return array{data: array<string, mixed>, status: int}
     */
    public function handle(
        User $user,
        string $amount,
        string $currency,
        int $paymentMethodId,
        ?UploadedFile $proof,
        string $rawIdempotencyKey,
    ): array {
        $currency = strtoupper($currency);
        $normalizedAmount = $this->normalizeEnteredAmount($amount);
        $this->assertActivePaymentMethod($paymentMethodId);

        $proofFingerprint = $proof instanceof UploadedFile
            ? hash_file('sha256', (string) $proof->getRealPath())
            : 'none';

        if (! is_string($proofFingerprint) || $proofFingerprint === '') {
            $proofFingerprint = 'none';
        }

        $canonicalRequest = [
            'amount' => $normalizedAmount,
            'currency' => $currency,
            'payment_method_id' => $paymentMethodId,
            'proof' => $proofFingerprint,
        ];

        $keyHash = $this->idempotency->hashKey($rawIdempotencyKey);
        $requestHash = $this->idempotency->hashRequest($canonicalRequest);
        $detailBuilder = fn (TopupRequest $request, User $owner): array => MobileTopupFactory::forUser($owner)
            ->fromOwnedRequest($request, $owner);

        $existing = $this->idempotency->findForUser($user, $keyHash);
        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                throw new MobileApiException(
                    'messages.mobile_api.idempotency_conflict',
                    'idempotency_conflict',
                    409,
                );
            }

            $reconciled = $this->idempotency->reconcile($existing, $user, $detailBuilder);
            if ($reconciled['state'] === 'completed' && is_array($reconciled['detail'])) {
                return $this->completedPayload($reconciled['detail'], replayed: true);
            }
        }

        $this->assertNoUnrelatedPending($user, $existing);

        $claim = $this->idempotency->claim($user, $keyHash, $requestHash, $detailBuilder);
        if ($claim['replay'] && is_array($claim['detail'])) {
            return $this->completedPayload($claim['detail'], replayed: true);
        }

        $attempt = $claim['attempt'];

        try {
            $created = $this->submit->handle(
                $user,
                $normalizedAmount,
                $paymentMethodId,
                $proof !== null,
                $proof,
                $currency,
            );

            return DB::transaction(function () use ($attempt, $created, $user, $detailBuilder): array {
                $locked = $this->idempotency->lockForUpdate($attempt);
                if ($locked === null) {
                    throw new MobileApiException(
                        'messages.mobile_api.topup_in_progress',
                        'topup_in_progress',
                        202,
                    );
                }

                $this->idempotency->markCompleted($locked, (int) $created->id);
                $detail = $detailBuilder($created->refresh(), $user);

                return $this->completedPayload($detail, replayed: false);
            });
        } catch (ValidationException $exception) {
            $this->idempotency->releaseIfSafe($attempt, $user, $detailBuilder);
            $this->rethrowValidation($exception);
        } catch (MobileApiException $exception) {
            $this->idempotency->releaseIfSafe($attempt, $user, $detailBuilder);
            throw $exception;
        } catch (\Throwable $exception) {
            $this->idempotency->releaseIfSafe($attempt, $user, $detailBuilder);
            throw $exception;
        }
    }

    private function normalizeEnteredAmount(string $amount): string
    {
        try {
            return LedgerMoney::normalizePositive($amount);
        } catch (InvalidWalletPostingAmountException) {
            throw new MobileApiException(
                'messages.mobile_api.invalid_topup_amount',
                'invalid_topup_amount',
                422,
            );
        }
    }

    private function assertActivePaymentMethod(int $paymentMethodId): void
    {
        $exists = PaymentMethod::query()
            ->active()
            ->whereKey($paymentMethodId)
            ->exists();

        if (! $exists) {
            throw new MobileApiException(
                'messages.mobile_api.payment_method_unavailable',
                'payment_method_unavailable',
                422,
            );
        }
    }

    private function assertNoUnrelatedPending(User $user, ?MobileTopupAttempt $existing): void
    {
        $pending = TopupRequest::query()
            ->where('user_id', $user->id)
            ->where('status', TopupRequestStatus::Pending)
            ->first();

        if ($pending === null) {
            return;
        }

        if ($existing !== null && (int) $existing->topup_request_id === (int) $pending->id) {
            return;
        }

        throw new MobileApiException(
            'messages.mobile_api.topup_request_pending',
            'topup_request_pending',
            422,
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{data: array<string, mixed>, status: int}
     */
    private function completedPayload(array $detail, bool $replayed): array
    {
        return [
            'data' => [
                'replayed' => $replayed,
                'pending_until_admin_approval' => (bool) ($detail['pending_until_admin_approval'] ?? false),
                'topup' => $detail,
            ],
            'status' => 200,
        ];
    }

    private function rethrowValidation(ValidationException $exception): never
    {
        $errors = $exception->errors();

        if (isset($errors['topupAmount'])) {
            throw new MobileApiException(
                'messages.mobile_api.topup_request_pending',
                'topup_request_pending',
                422,
            );
        }

        if (isset($errors['currency'])) {
            throw new MobileApiException(
                'messages.mobile_api.topup_conversion_unavailable',
                'topup_conversion_unavailable',
                422,
            );
        }

        throw $exception;
    }
}
