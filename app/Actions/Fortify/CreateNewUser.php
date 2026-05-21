<?php

namespace App\Actions\Fortify;

use App\Concerns\AssignsDefaultCustomerRole;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Timezone;
use App\Models\User;
use App\Support\SupportedLocale;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use AssignsDefaultCustomerRole, PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        // Detect timezone from JavaScript-detected timezone or fallback to country code
        $timezone = Timezone::detect(
            $input['timezone_detected'] ?? null,
            $input['country_code'] ?? null
        );
        $referrerId = $this->resolveReferrerIdFromCookie();

        $user = User::create([
            'name' => $input['name'],
            'username' => $input['username'],
            'email' => $input['email'],
            'referred_by_user_id' => $referrerId,
            'locale' => SupportedLocale::fromRequest(request()),
            'locale_manually_set' => false,
            'preferred_currency' => $input['preferred_currency'],
            'password' => $input['password'],
            'phone' => $input['phone'] ?? null,
            'country_code' => $input['country_code'] ?? null,
            'timezone' => $timezone,
            'profile_photo' => $input['profile_photo'] ?? null,
            'is_active' => true,
        ]);

        $this->syncInitialUserRoles($user, []);

        activity()
            ->inLog('admin')
            ->event('user.registered')
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties([
                'user_id' => $user->id,
                'email' => $user->email,
                'username' => $user->username,
                'country_code' => $user->country_code,
                'timezone' => $user->timezone,
                'preferred_currency' => $user->preferred_currency,
            ])
            ->log('User registered');

        return $user;
    }

    private function resolveReferrerIdFromCookie(): ?int
    {
        $cookieName = (string) config('referral.cookie_name', 'karman_ref');
        $raw = request()->cookie($cookieName);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $referrer = User::findByReferralCode($raw);

        return $referrer?->id;
    }
}
