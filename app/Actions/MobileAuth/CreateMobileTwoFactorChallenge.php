<?php

declare(strict_types=1);

namespace App\Actions\MobileAuth;

use App\DTOs\MobileTwoFactorChallenge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class CreateMobileTwoFactorChallenge
{
    public function execute(User $user, ?string $deviceName): MobileTwoFactorChallenge
    {
        $plainTextToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = CarbonImmutable::now()->addMinutes(
            (int) config('mobile_api.two_factor_challenge.lifetime_minutes', 5)
        );

        Cache::put(self::cacheKey($plainTextToken), [
            'user_id' => $user->getKey(),
            'device_name' => $deviceName,
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        return new MobileTwoFactorChallenge($plainTextToken, $expiresAt);
    }

    public static function cacheKey(string $plainTextToken): string
    {
        return (string) config('mobile_api.two_factor_challenge.cache_prefix', 'mobile-api:two-factor:')
            .hash('sha256', $plainTextToken);
    }
}
