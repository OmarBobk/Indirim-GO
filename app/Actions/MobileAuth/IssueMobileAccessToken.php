<?php

declare(strict_types=1);

namespace App\Actions\MobileAuth;

use App\Actions\Auth\RecordSuccessfulLogin;
use App\DTOs\MobileAuthenticationResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class IssueMobileAccessToken
{
    public function __construct(
        private readonly RecordSuccessfulLogin $recordSuccessfulLogin,
    ) {}

    public function execute(User $user, ?string $deviceName): MobileAuthenticationResult
    {
        return DB::transaction(function () use ($user, $deviceName): MobileAuthenticationResult {
            $expiresAt = CarbonImmutable::now()->addDays(
                (int) config('mobile_api.token.lifetime_days', 30)
            );
            $normalizedDeviceName = trim((string) $deviceName);
            $tokenName = $normalizedDeviceName === ''
                ? 'mobile'
                : 'mobile: '.$normalizedDeviceName;

            $accessToken = $user->createToken(
                $tokenName,
                [(string) config('mobile_api.token.ability', 'mobile:access')],
                $expiresAt,
            );

            $this->recordSuccessfulLogin->execute($user);

            return new MobileAuthenticationResult(
                user: $user->refresh(),
                plainTextToken: $accessToken->plainTextToken,
                expiresAt: $expiresAt,
            );
        });
    }
}
