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
use Illuminate\Support\Facades\RateLimiter;
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

        $userAttemptKey = $this->userAttemptKey($challenge['user_id']);
        $userLock = Cache::lock(
            $userAttemptKey.':lock',
            (int) config('mobile_api.two_factor_challenge.lock_seconds', 10),
        );

        return $userLock->block(
            (int) config('mobile_api.two_factor_challenge.lock_wait_seconds', 2),
            fn (): MobileAuthenticationResult => $this->completeForUserUnderLock(
                $cacheKey,
                $challenge,
                $code,
                $recoveryCode,
                $userAttemptKey,
            ),
        );
    }

    /**
     * @param  array{user_id: mixed, device_name?: mixed, attempts: mixed, expires_at: mixed}  $challenge
     */
    private function completeForUserUnderLock(
        string $cacheKey,
        array $challenge,
        ?string $code,
        ?string $recoveryCode,
        string $userAttemptKey,
    ): MobileAuthenticationResult {
        $maxAttempts = (int) config('mobile_api.two_factor_challenge.max_attempts', 5);

        if (RateLimiter::tooManyAttempts($userAttemptKey, $maxAttempts)) {
            Cache::forget($cacheKey);

            throw new MobileApiException(
                'messages.mobile_api.two_factor_attempts_exceeded',
                'two_factor_attempts_exceeded',
                422,
            );
        }

        $outcome = DB::transaction(function () use (
            $cacheKey,
            $challenge,
            $code,
            $recoveryCode,
            $userAttemptKey,
        ): array {
            $user = User::query()->lockForUpdate()->find($challenge['user_id']);

            if ($user === null || ! $user->hasEnabledTwoFactorAuthentication()) {
                return [
                    'error_code' => 'invalid_two_factor_challenge',
                    'status' => 422,
                    'counts_attempt' => false,
                ];
            }

            if (! $user->canLogin()) {
                return [
                    'error_code' => $user->isActive() ? 'account_blocked' : 'account_inactive',
                    'status' => 422,
                    'counts_attempt' => false,
                ];
            }

            if (! $user->hasRole('customer')) {
                return [
                    'error_code' => 'customer_role_required',
                    'status' => 403,
                    'counts_attempt' => false,
                ];
            }

            $valid = $code !== null
                ? $this->verifyAuthenticatorCode($user, $code)
                : $this->consumeRecoveryCode($user, (string) $recoveryCode);

            if (! $valid) {
                return [
                    'error_code' => $recoveryCode !== null
                        ? 'invalid_recovery_code'
                        : 'invalid_two_factor_code',
                    'status' => 422,
                    'counts_attempt' => true,
                ];
            }

            Cache::forget($cacheKey);
            RateLimiter::clear($userAttemptKey);

            return [
                'result' => $this->issueMobileAccessToken->execute(
                    $user,
                    is_string($challenge['device_name'] ?? null) ? $challenge['device_name'] : null,
                ),
            ];
        });

        if (($outcome['counts_attempt'] ?? false) === true) {
            $this->recordFailedAttempt(
                $cacheKey,
                $challenge,
                $userAttemptKey,
                (string) $outcome['error_code'],
            );
        }

        if (isset($outcome['error_code'], $outcome['status'])) {
            Cache::forget($cacheKey);

            throw new MobileApiException(
                'messages.mobile_api.'.$outcome['error_code'],
                (string) $outcome['error_code'],
                (int) $outcome['status'],
            );
        }

        if (! ($outcome['result'] ?? null) instanceof MobileAuthenticationResult) {
            Cache::forget($cacheKey);

            throw new MobileApiException(
                'messages.mobile_api.invalid_two_factor_challenge',
                'invalid_two_factor_challenge',
                422,
            );
        }

        return $outcome['result'];
    }

    private function userAttemptKey(mixed $userId): string
    {
        return 'mobile-api:two-factor-user:'.hash('sha256', (string) $userId);
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
    private function recordFailedAttempt(
        string $cacheKey,
        array $challenge,
        string $userAttemptKey,
        string $errorCode,
    ): never {
        $attempts = (int) $challenge['attempts'] + 1;
        $maxAttempts = (int) config('mobile_api.two_factor_challenge.max_attempts', 5);
        $decaySeconds = (int) config('mobile_api.two_factor_challenge.lifetime_minutes', 5) * 60;
        $userAttempts = RateLimiter::hit($userAttemptKey, $decaySeconds);

        if ($attempts >= $maxAttempts || $userAttempts >= $maxAttempts) {
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
