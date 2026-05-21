<?php

namespace App\Livewire\Users;

use App\Actions\Users\AdminResetUserPassword;
use App\Actions\Users\BlockUser;
use App\Actions\Users\CreateUser;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\UnblockUser;
use App\Actions\Users\UpdateUser;
use App\Actions\Users\VerifyUserEmail;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Masmerise\Toaster\Toastable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserModals extends Component
{
    use Toastable;

    private const ALLOWED_COUNTRY_CODES = ['+963', '+90'];

    /** When true, create/edit only manage users referred by the current salesperson (no roles, permissions, or commission). */
    public bool $referredOnly = false;

    public bool $showCreate = false;

    public bool $showEdit = false;

    public bool $showDeleteModal = false;

    public bool $showBlockModal = false;

    public bool $showUnblockModal = false;

    public bool $showResetPasswordModal = false;

    public bool $showVerifyModal = false;

    public string $newName = '';

    public string $newUsername = '';

    public string $newEmail = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public ?string $newPhone = null;

    public ?string $newCountryCode = null;

    public ?string $newCommissionRatePercent = null;

    /** @var array<int, string> */
    public array $newRoles = [];

    /** @var array<int, string> */
    public array $newPermissions = [];

    public ?int $editingUserId = null;

    public string $editName = '';

    public string $editUsername = '';

    public string $editEmail = '';

    public ?string $editPhone = null;

    public ?string $editCountryCode = null;

    public ?string $editCommissionRatePercent = null;

    /** @var array<int, string> */
    public array $editRoles = [];

    /** @var array<int, string> */
    public array $editPermissions = [];

    public string $editPassword = '';

    public string $editPasswordConfirmation = '';

    public ?int $deleteUserId = null;

    public string $deleteUserName = '';

    public ?int $blockUserId = null;

    public string $blockUserName = '';

    public ?int $unblockUserId = null;

    public string $unblockUserName = '';

    public ?int $resetPasswordUserId = null;

    public string $resetPasswordNew = '';

    public string $resetPasswordNewConfirmation = '';

    public ?int $verifyUserId = null;

    public string $verifyUserName = '';

    public function openCreate(): void
    {
        $this->authorize('create', User::class);
        $this->resetCreateForm();
        $this->showCreate = true;
    }

    public function closeCreate(): void
    {
        $this->resetCreateForm();
        $this->showCreate = false;
    }

    public function saveCreate(): void
    {
        $this->authorize('create', User::class);

        $input = [
            'name' => $this->newName,
            'username' => $this->newUsername,
            'email' => $this->newEmail,
            'password' => $this->newPassword,
            'password_confirmation' => $this->newPasswordConfirmation,
            'phone' => $this->newPhone,
            'country_code' => $this->normalizeCountryCode($this->newCountryCode),
            'commission_rate_percent' => $this->normalizeCommissionRatePercent($this->newCommissionRatePercent),
            'roles' => $this->newRoles,
            'permissions' => $this->newPermissions,
        ];

        try {
            if ($this->referredOnly) {
                $input['roles'] = ['customer'];
                $input['permissions'] = [];
                $input['commission_rate_percent'] = null;
                app(CreateUser::class)->handle($input, (int) auth()->id(), (int) auth()->id());
            } else {
                app(CreateUser::class)->handle($input, (int) auth()->id());
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            $v = $e->validator;
            foreach (['name' => 'newName', 'username' => 'newUsername', 'email' => 'newEmail', 'password' => 'newPassword', 'phone' => 'newPhone', 'country_code' => 'newCountryCode', 'commission_rate_percent' => 'newCommissionRatePercent'] as $actionKey => $prop) {
                if ($v->errors()->has($actionKey)) {
                    $this->addError($prop, $v->errors()->first($actionKey));
                }
            }
            $this->error(__('messages.validation_failed'));

            return;
        }

        $this->success(__('messages.user_created'));
        $this->closeCreate();
        $this->dispatch('users-updated');
    }

    public function startEdit(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $this->authorize('update', $user);

        $this->editingUserId = $user->id;
        $this->editName = $user->name;
        $this->editUsername = $user->username;
        $this->editEmail = $user->email;
        $this->editPhone = $user->phone;
        $this->editCountryCode = $user->country_code && in_array($user->country_code, self::ALLOWED_COUNTRY_CODES, true)
            ? $user->country_code
            : null;
        $this->editCommissionRatePercent = $user->commission_rate_percent !== null
            ? number_format((float) $user->commission_rate_percent, 2, '.', '')
            : null;
        $this->editRoles = $user->getRoleNames()->all();
        $this->editPermissions = $user->getDirectPermissions()->pluck('name')->all();
        $this->editPassword = '';
        $this->editPasswordConfirmation = '';
        $this->showEdit = true;
    }

    public function closeEdit(): void
    {
        $this->reset(['editingUserId', 'editName', 'editUsername', 'editEmail', 'editPhone', 'editCountryCode', 'editCommissionRatePercent', 'editRoles', 'editPermissions', 'editPassword', 'editPasswordConfirmation', 'showEdit']);
        $this->resetValidation();
    }

    public function saveEdit(): void
    {
        $user = User::query()->findOrFail($this->editingUserId);
        $this->authorize('update', $user);

        $input = [
            'name' => $this->editName,
            'username' => $this->editUsername,
            'email' => $this->editEmail,
            'phone' => $this->editPhone ?: null,
            'country_code' => $this->normalizeCountryCode($this->editCountryCode),
        ];
        if (! $this->referredOnly) {
            $input['commission_rate_percent'] = $this->normalizeCommissionRatePercent($this->editCommissionRatePercent);
            $input['roles'] = $this->editRoles;
            $input['permissions'] = $this->editPermissions;
        }

        try {
            app(UpdateUser::class)->handle($user, $input, (int) auth()->id(), $this->referredOnly);
        } catch (ValidationException $e) {
            $v = $e->validator;
            foreach (['name' => 'editName', 'username' => 'editUsername', 'email' => 'editEmail', 'phone' => 'editPhone', 'country_code' => 'editCountryCode', 'commission_rate_percent' => 'editCommissionRatePercent'] as $actionKey => $prop) {
                if ($v->errors()->has($actionKey)) {
                    $this->addError($prop, $v->errors()->first($actionKey));
                }
            }
            $this->error(__('messages.validation_failed'));

            return;
        }

        if ($this->referredOnly && trim($this->editPassword) !== '') {
            $user->refresh();
            $this->authorize('resetPassword', $user);
            try {
                app(AdminResetUserPassword::class)->handle($user, [
                    'password' => $this->editPassword,
                    'password_confirmation' => $this->editPasswordConfirmation,
                ], (int) auth()->id());
            } catch (ValidationException $e) {
                if ($e->validator->errors()->has('password')) {
                    $this->addError('editPassword', $e->validator->errors()->first('password'));
                }
                $this->error(__('messages.validation_failed'));

                return;
            }
        }

        $this->success(__('messages.user_updated'));
        $this->closeEdit();
        $this->dispatch('users-updated');
    }

    private function normalizeCountryCode(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array($value, self::ALLOWED_COUNTRY_CODES, true) ? $value : null;
    }

    private function normalizeCommissionRatePercent(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    public function confirmDelete(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $this->authorize('delete', $user);
        $this->deleteUserId = $user->id;
        $this->deleteUserName = $user->name;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->reset(['showDeleteModal', 'deleteUserId', 'deleteUserName']);
    }

    public function deleteUser(): void
    {
        if ($this->deleteUserId === null) {
            return;
        }
        $user = User::query()->findOrFail($this->deleteUserId);
        $this->authorize('delete', $user);
        app(DeleteUser::class)->handle($user, (int) auth()->id());
        $this->success(__('messages.user_deleted'));
        $this->cancelDelete();
        $this->dispatch('users-updated');
    }

    public function confirmBlock(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $this->authorize('block', $user);
        $this->blockUserId = $user->id;
        $this->blockUserName = $user->name;
        $this->showBlockModal = true;
    }

    public function cancelBlock(): void
    {
        $this->reset(['showBlockModal', 'blockUserId', 'blockUserName']);
    }

    public function blockUser(): void
    {
        if ($this->blockUserId === null) {
            return;
        }
        $user = User::query()->findOrFail($this->blockUserId);
        $this->authorize('block', $user);
        app(BlockUser::class)->handle($user, (int) auth()->id());
        $this->success(__('messages.user_blocked'));
        $this->cancelBlock();
        $this->dispatch('users-updated');
    }

    public function confirmUnblock(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $this->authorize('unblock', $user);
        $this->unblockUserId = $user->id;
        $this->unblockUserName = $user->name;
        $this->showUnblockModal = true;
    }

    public function cancelUnblock(): void
    {
        $this->reset(['showUnblockModal', 'unblockUserId', 'unblockUserName']);
    }

    public function unblockUser(): void
    {
        if ($this->unblockUserId === null) {
            return;
        }
        $user = User::query()->findOrFail($this->unblockUserId);
        $this->authorize('unblock', $user);
        app(UnblockUser::class)->handle($user, (int) auth()->id());
        $this->success(__('messages.user_unblocked'));
        $this->cancelUnblock();
        $this->dispatch('users-updated');
    }

    public function confirmResetPassword(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $this->authorize('resetPassword', $user);
        $this->resetPasswordUserId = $user->id;
        $this->resetPasswordNew = '';
        $this->resetPasswordNewConfirmation = '';
        $this->showResetPasswordModal = true;
    }

    public function cancelResetPassword(): void
    {
        $this->reset(['showResetPasswordModal', 'resetPasswordUserId', 'resetPasswordNew', 'resetPasswordNewConfirmation']);
        $this->resetValidation();
    }

    public function resetPassword(): void
    {
        if ($this->resetPasswordUserId === null) {
            return;
        }
        $user = User::query()->findOrFail($this->resetPasswordUserId);
        $this->authorize('resetPassword', $user);

        $input = [
            'password' => $this->resetPasswordNew,
            'password_confirmation' => $this->resetPasswordNewConfirmation,
        ];

        try {
            app(AdminResetUserPassword::class)->handle($user, $input, (int) auth()->id());
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($e->validator->errors()->has('password')) {
                $this->addError('resetPasswordNew', $e->validator->errors()->first('password'));
            }
            $this->error(__('messages.validation_failed'));

            return;
        }

        $this->success(__('messages.password_reset'));
        $this->cancelResetPassword();
        $this->dispatch('users-updated');
    }

    public function confirmVerifyEmail(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $this->authorize('verifyEmail', $user);
        $this->verifyUserId = $user->id;
        $this->verifyUserName = $user->name;
        $this->showVerifyModal = true;
    }

    public function cancelVerify(): void
    {
        $this->reset(['showVerifyModal', 'verifyUserId', 'verifyUserName']);
    }

    public function verifyEmail(): void
    {
        if ($this->verifyUserId === null) {
            return;
        }
        $user = User::query()->findOrFail($this->verifyUserId);
        $this->authorize('verifyEmail', $user);
        app(VerifyUserEmail::class)->handle($user, (int) auth()->id());
        $this->success(__('messages.email_verification_sent'));
        $this->cancelVerify();
        $this->dispatch('users-updated');
    }

    private function resetCreateForm(): void
    {
        $this->reset([
            'newName', 'newUsername', 'newEmail', 'newPassword', 'newPasswordConfirmation',
            'newPhone', 'newCountryCode', 'newCommissionRatePercent', 'newRoles', 'newPermissions',
        ]);
        $this->resetValidation();
    }

    /** @return Collection<int, string> */
    public function getAllRolesProperty(): Collection
    {
        return Role::query()->orderBy('name')->pluck('name');
    }

    /** @return Collection<int, string> */
    public function getAllPermissionsProperty(): Collection
    {
        return Permission::query()->orderBy('name')->pluck('name');
    }

    /**
     * Permissions grouped by domain for a cleaner UI.
     *
     * @return array<string, array{label: string, icon: string, permissions: array<string>}>
     */
    public function getGroupedPermissionsProperty(): array
    {
        $all = $this->allPermissions->all();

        $groups = [
            'users' => [
                'label' => __('messages.permission_group_users'),
                'icon' => 'users',
                'permissions' => ['view_dashboard', 'manage_users'],
            ],
            'catalog' => [
                'label' => __('messages.permission_group_catalog'),
                'icon' => 'squares-2x2',
                'permissions' => ['manage_sections', 'manage_products', 'manage_topups', 'manage_settlements'],
            ],
            'orders' => [
                'label' => __('messages.permission_group_orders'),
                'icon' => 'shopping-cart',
                'permissions' => ['view_referrals', 'view_orders', 'create_orders', 'edit_orders', 'delete_orders'],
            ],
            'fulfillments' => [
                'label' => __('messages.permission_group_fulfillments'),
                'icon' => 'truck',
                'permissions' => ['view_fulfillments', 'manage_fulfillments'],
            ],
            'refunds' => [
                'label' => __('messages.permission_group_refunds'),
                'icon' => 'arrow-uturn-left',
                'permissions' => ['view_refunds', 'process_refunds'],
            ],
            'activities' => [
                'label' => __('messages.permission_group_activities'),
                'icon' => 'chart-bar',
                'permissions' => ['view_activities'],
            ],
            'profile' => [
                'label' => __('messages.permission_group_profile'),
                'icon' => 'user-circle',
                'permissions' => ['customer_profile'],
            ],
        ];

        $result = [];
        foreach ($groups as $key => $config) {
            $perms = array_intersect($config['permissions'], $all);
            if ($perms !== []) {
                $result[$key] = [
                    'label' => $config['label'],
                    'icon' => $config['icon'],
                    'permissions' => array_values($perms),
                ];
            }
        }

        $assigned = collect($result)->pluck('permissions')->flatten()->all();
        $other = array_diff($all, $assigned);
        if ($other !== []) {
            $result['other'] = [
                'label' => __('messages.permission_group_other'),
                'icon' => 'ellipsis-horizontal-circle',
                'permissions' => array_values($other),
            ];
        }

        return $result;
    }

    public function humanizePermission(string $name): string
    {
        return str_replace('_', ' ', ucwords($name, '_'));
    }

    public function render()
    {
        return view('livewire.users.user-modals');
    }
}
