<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Concerns\ProfileValidationRules;
use App\Data\UpdateUserProfileData;
use App\Enums\Timezone;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateUser
{
    use ProfileValidationRules;

    /**
     * @param  array<string, mixed>  $input  Must include name, username, email; optional: phone, country_code, roles, permissions
     * @param  bool  $profileOnly  When true, only profile fields are updated (no roles, permissions, or commission changes).
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input, int $causedById, bool $profileOnly = false): void
    {
        $rules = [
            ...$this->profileRules($user->id),
        ];
        if (! $profileOnly) {
            $rules['commission_rate_percent'] = ['nullable', 'numeric', 'min:0.01', 'max:100'];
        }

        Validator::make([
            ...$input,
            'preferred_currency' => $this->normalizePreferredCurrency($input['preferred_currency'] ?? $user->preferred_currency ?? null),
        ], $rules)->validate();

        $data = UpdateUserProfileData::from($input);

        $timezone = Timezone::detect(
            $data->timezone_detected,
            $data->country_code
        );

        $previous = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'country_code' => $user->country_code,
            'commission_rate_percent' => $user->commission_rate_percent,
        ];

        $fill = [
            'name' => $data->name,
            'username' => $data->username,
            'email' => $data->email,
            'phone' => $data->phone,
            'country_code' => $data->country_code,
            'timezone' => $timezone,
            'profile_photo' => $data->profile_photo ?? $user->profile_photo,
        ];
        if (! $profileOnly) {
            $fill['commission_rate_percent'] = $input['commission_rate_percent'] ?? null;
        }

        $user->forceFill($fill)->save();

        $loggedRoles = false;
        $loggedPermissions = false;

        if (! $profileOnly) {
            $roleNames = $data->roles;
            if (is_array($roleNames)) {
                $previousRoles = $user->getRoleNames()->all();
                $user->syncRoles($roleNames);
                if ($previousRoles !== $roleNames) {
                    $this->logRolesUpdated($user, $roleNames, $previousRoles, $causedById);
                    $loggedRoles = true;
                }
            }

            $permissionNames = $data->permissions;
            if (is_array($permissionNames)) {
                $previousPermissions = $user->getDirectPermissions()->pluck('name')->all();
                $user->syncPermissions($permissionNames);
                if ($previousPermissions !== $permissionNames) {
                    $this->logPermissionsUpdated($user, $permissionNames, $previousPermissions, $causedById);
                    $loggedPermissions = true;
                }
            }
        }

        if (! $loggedRoles && ! $loggedPermissions) {
            $changes = $this->profileChanges($previous, [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'country_code' => $user->country_code,
                'commission_rate_percent' => $user->commission_rate_percent,
            ]);
            if ($changes !== []) {
                $this->logProfileUpdated($user, $changes, $causedById);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array<string, array{previous: mixed, updated: mixed}>
     */
    private function profileChanges(array $previous, array $current): array
    {
        $changes = [];
        $fields = ['name', 'username', 'email', 'phone', 'country_code', 'commission_rate_percent'];
        foreach ($fields as $field) {
            $old = $previous[$field] ?? null;
            $new = $current[$field] ?? null;
            if ((string) $old !== (string) $new) {
                $changes[$field] = ['previous' => $old, 'updated' => $new];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, array{previous: mixed, updated: mixed}>  $changes
     */
    private function logProfileUpdated(User $user, array $changes, int $causedById): void
    {
        $causer = User::query()->find($causedById);
        $adminUsername = $causer?->username ?? $causer?->name ?? 'admin';
        $targetUsername = $user->username ?? $user->name ?? (string) $user->id;

        $fieldLabels = [
            'name' => 'name',
            'username' => 'username',
            'email' => 'email',
            'phone' => 'phone',
            'country_code' => 'country code',
            'commission_rate_percent' => 'commission rate',
        ];
        $changedParts = [];
        foreach (array_keys($changes) as $field) {
            $changedParts[] = $fieldLabels[$field];
        }
        $fieldsPhrase = count($changedParts) === 1
            ? $changedParts[0]
            : implode(', ', array_slice($changedParts, 0, -1)).' and '.$changedParts[array_key_last($changedParts)];
        $description = "Admin({$adminUsername}) updated the {$fieldsPhrase} of user {$targetUsername}";

        $properties = [
            'user_id' => $user->id,
        ];
        foreach ($changes as $field => $vals) {
            $properties['previous_'.$field] = $vals['previous'];
            $properties['updated_'.$field] = $vals['updated'];
        }

        activity()
            ->inLog('admin')
            ->event('user.updated')
            ->performedOn($user)
            ->causedBy($causer)
            ->withProperties($properties)
            ->log($description);
    }

    /**
     * @param  array<int, string>  $newRoles
     * @param  array<int, string>  $oldRoles
     */
    private function logRolesUpdated(User $user, array $newRoles, array $oldRoles, int $causedById): void
    {
        $causer = User::query()->find($causedById);
        $adminUsername = $causer?->username ?? $causer?->name ?? 'admin';
        $targetUsername = $user->username ?? $user->name ?? (string) $user->id;

        $removed = array_values(array_diff($oldRoles, $newRoles));
        $added = array_values(array_diff($newRoles, $oldRoles));

        $parts = [];
        if ($removed !== []) {
            $rolesList = implode(', ', $removed);
            $parts[] = "removed role {$rolesList} from {$targetUsername}";
        }
        if ($added !== []) {
            $rolesList = implode(', ', $added);
            $parts[] = "assigned role {$rolesList} to {$targetUsername}";
        }
        $action = implode(' and ', $parts) ?: "updated roles for {$targetUsername}";
        $description = "Admin({$adminUsername}) {$action}";

        activity()
            ->inLog('admin')
            ->event('user.roles_updated')
            ->performedOn($user)
            ->causedBy($causer)
            ->withProperties([
                'user_id' => $user->id,
                'roles' => $newRoles,
                'previous_roles' => $oldRoles,
            ])
            ->log($description);
    }

    /**
     * @param  array<int, string>  $newPermissions
     * @param  array<int, string>  $oldPermissions
     */
    private function logPermissionsUpdated(User $user, array $newPermissions, array $oldPermissions, int $causedById): void
    {
        $causer = User::query()->find($causedById);
        $adminUsername = $causer?->username ?? $causer?->name ?? 'admin';
        $targetUsername = $user->username ?? $user->name ?? (string) $user->id;

        $removed = array_values(array_diff($oldPermissions, $newPermissions));
        $added = array_values(array_diff($newPermissions, $oldPermissions));

        $parts = [];
        if ($removed !== []) {
            $permList = implode(', ', $removed);
            $parts[] = "removed permission {$permList} from {$targetUsername}";
        }
        if ($added !== []) {
            $permList = implode(', ', $added);
            $parts[] = "assigned permission {$permList} to {$targetUsername}";
        }
        $action = implode(' and ', $parts) ?: "updated permissions for {$targetUsername}";
        $description = "Admin({$adminUsername}) {$action}";

        activity()
            ->inLog('admin')
            ->event('user.permissions_updated')
            ->performedOn($user)
            ->causedBy($causer)
            ->withProperties([
                'user_id' => $user->id,
                'permissions' => $newPermissions,
                'previous_permissions' => $oldPermissions,
            ])
            ->log($description);
    }

    private function normalizePreferredCurrency(mixed $value): string
    {
        return in_array($value, ['USD', 'TRY'], true) ? $value : 'USD';
    }
}
