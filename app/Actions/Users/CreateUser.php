<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Concerns\AssignsDefaultCustomerRole;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Timezone;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateUser
{
    use AssignsDefaultCustomerRole, PasswordValidationRules, ProfileValidationRules;

    /**
     * @param  array<string, mixed>  $input  name, username, email, password; optional: phone, country_code, roles, permissions
     * @param  int|null  $referrerUserId  When set, the new user is linked as referred by this user (salesperson flow); roles become customer only.
     *
     * @throws ValidationException
     */
    public function handle(array $input, int $causedById, ?int $referrerUserId = null): User
    {
        if ($referrerUserId !== null) {
            if ((int) $causedById !== (int) $referrerUserId) {
                throw new AuthorizationException('Referrer mismatch for referred user creation.');
            }
            $referrer = User::query()->find($referrerUserId);
            if ($referrer === null || ! $referrer->can('manage_referred_users')) {
                throw new AuthorizationException('Cannot create referred user for this account.');
            }
        }

        $rules = [
            ...$this->profileRules(null),
            'password' => $this->passwordRules(),
        ];
        if ($referrerUserId === null) {
            $rules['commission_rate_percent'] = ['nullable', 'numeric', 'min:0.01', 'max:100'];
        }

        Validator::make([
            ...$input,
            'preferred_currency' => $this->normalizePreferredCurrency($input['preferred_currency'] ?? null),
        ], $rules)->validate();

        $timezone = Timezone::detect(
            $input['timezone_detected'] ?? null,
            $input['country_code'] ?? null
        );

        $user = User::create([
            'name' => $input['name'],
            'username' => $input['username'],
            'email' => $input['email'],
            'password' => $input['password'],
            'phone' => $input['phone'] ?? null,
            'country_code' => $input['country_code'] ?? null,
            'timezone' => $timezone,
            'profile_photo' => $input['profile_photo'] ?? null,
            'commission_rate_percent' => $referrerUserId === null ? ($input['commission_rate_percent'] ?? null) : null,
            'referred_by_user_id' => $referrerUserId,
            'is_active' => true,
        ]);

        $roleNames = $referrerUserId !== null
            ? ['customer']
            : (is_array($input['roles'] ?? null) ? $input['roles'] : []);

        $this->syncInitialUserRoles($user, $roleNames);

        if ($referrerUserId === null) {
            $permissionNames = $input['permissions'] ?? [];
            if (is_array($permissionNames) && $permissionNames !== []) {
                $user->syncPermissions($permissionNames);
            }
        }

        $causer = User::query()->find($causedById);
        $logMessage = $referrerUserId !== null
            ? 'User created under salesperson referral'
            : 'User created by admin';
        activity()
            ->inLog('admin')
            ->event('user.created')
            ->performedOn($user)
            ->causedBy($causer)
            ->withProperties([
                'user_id' => $user->id,
                'email' => $user->email,
                'username' => $user->username,
                'referred_by_user_id' => $referrerUserId,
            ])
            ->log($logMessage);

        return $user;
    }

    private function normalizePreferredCurrency(mixed $value): string
    {
        return in_array($value, ['USD', 'TRY'], true) ? $value : 'USD';
    }
}
