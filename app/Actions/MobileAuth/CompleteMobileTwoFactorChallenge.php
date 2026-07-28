<?php

declare(strict_types=1);

namespace App\Actions\MobileAuth;

use App\DTOs\MobileAuthenticationResult;
use App\Exceptions\MobileApiException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

final class CompleteMobileTwoFactorChallenge
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $twoFactorProvider,
        private readonly IssueMobileAccessToken $issueMobileAccessToken,
    ) {}

    public function execute(
        string $challengeToken,
        ?string $code,
        ?string $recoveryCode,
    ): MobileAuthenticationResult {
        $cacheKey = CreateMobileTwoFactorChallenge::cacheKey($challengeToken);
        $lock = Cache::lock(
            $cacheKey.':lock',
            (int) config('mobile_api.two_factor_challenge.lock_seconds', 10),
        );

        try {
            return $lock->block(
                (int) config('mobile_api.two_factor_challenge.lock_wait_seconds', 2),
                fn (): MobileAuthenticationResult => $this->completeUnderLock(
                    $cacheKey,
                    $code,
                    $recoveryCode,
                ),
            );
        } catch (LockTimeoutException) {
            throw new MobileApiException(
                'messages.mobile_api.invalid_two_factor_challenge',
                'invalid_two_factor_challenge',
                422,
            );
        }
    }

    private function completeUnderLock(
        string $cacheKey,
        ?string $code,
        ?string $recoveryCode,
    ): MobileAuthenticationResult {
        $challenge = Cache::get($cacheKey);

        if (! is_array($challenge)
            || ! isset($challenge['user_id'], $challenge['attempts'], $challenge['expires_at'])
            || (int) $challenge['expires_at'] <= CarbonImmutable::now()->getTimestamp()) {
            Cache::forget($cacheKey);

            throw new MobileApiException(
                'messages.mobile_api.invalid_two_factor_challenge',
                'invalid_two_factor_challenge',
                422,
            );
        }

        if ((int) $challenge['attempts']
            >= (int) config('mobile_api.two_factor_challenge.max_attempts', 5)) {
            Cache::forget($cacheKey);

            throw new MobileApiException(
                'messages.mobile_api.two_factor_attempts_exceeded',
                'two_factor_attempts_exceeded',
                422,
            );
        }

        return DB::transaction(function () use ($cacheKey, $challenge, $code, $recoveryCode): MobileAuthenticationResult {
            $user = User::query()->lockForUpdate()->find($challenge['user_id']);

            if ($user === null || ! $user->hasEnabledTwoFactorAuthentication()) {
                Cache::forget($cacheKey);

                throw new MobileApiException(
                    'messages.mobile_api.invalid_two_factor_challenge',
                    'invalid_two_factor_challenge',
                    422,
                );
            }

            $this->assertUserCanCompleteChallenge($user, $cacheKey);

            $valid = $code !== null
                ? $this->verifyAuthenticatorCode($user, $code)
                : $this->consumeRecoveryCode($user, (string) $recoveryCode);

            if (! $valid) {
                $this->recordFailedAttempt(
                    $cacheKey,
                    $challenge,
                    $recoveryCode !== null ? 'invalid_recovery_code' : 'invalid_two_factor_code',
                );
            }

            Cache::forget($cacheKey);

            return $this->issueMobileAccessToken->execute(
                $user,
                is_string($challenge['device_name'] ?? null) ? $challenge['device_name'] : null,
            );
        });
    }

    private function assertUserCanCompleteChallenge(User $user, string $cacheKey): void
    {
        if (! $user->canLogin()) {
            Cache::forget($cacheKey);

            if (! $user->isActive()) {
                throw new MobileApiException(
                    'messages.mobile_api.account_inactive',
                    'account_inactive',
                    422,
                );
            }

            throw new MobileApiException(
                'messages.mobile_api.account_blocked',
                'account_blocked',
                422,
            );
        }

        if (! $user->hasRole('customer')) {
            Cache::forget($cacheKey);

            throw new MobileApiException(
                'messages.mobile_api.customer_role_required',
                'customer_role_required',
                403,
            );
        }
    }

    private function verifyAuthenticatorCode(User $user, string $code): bool
    {
        return (bool) $this->twoFactorProvider->verify(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            $code,
        );
    }

    private function consumeRecoveryCode(User $user, string $providedCode): bool
    {
        if ($user->two_factor_recovery_codes === null) {
            return false;
        }

        $matchedCode = collect($user->recoveryCodes())
            ->first(fn (mixed $storedCode): bool => is_string($storedCode)
                && hash_equals($storedCode, $providedCode));

        if (! is_string($matchedCode)) {
            return false;
        }

        $user->replaceRecoveryCode($matchedCode);

        return true;
    }

    /**
     * @param  array{user_id: mixed, device_name?: mixed, attempts: mixed, expires_at: mixed}  $challenge
     */
    private function recordFailedAttempt(string $cacheKey, array $challenge, string $errorCode): never
    {
        $attempts = (int) $challenge['attempts'] + 1;
        $maxAttempts = (int) config('mobile_api.two_factor_challenge.max_attempts', 5);

        if ($attempts >= $maxAttempts) {
            Cache::forget($cacheKey);

            throw new MobileApiException(
                'messages.mobile_api.two_factor_attempts_exceeded',
                'two_factor_attempts_exceeded',
                422,
            );
        }

        $challenge['attempts'] = $attempts;
        Cache::put(
            $cacheKey,
            $challenge,
            CarbonImmutable::createFromTimestamp((int) $challenge['expires_at']),
        );

        throw new MobileApiException(
            'messages.mobile_api.'.$errorCode,
            $errorCode,
            422,
        );
    }
}
