<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

final class RecordSuccessfulLogin
{
    public function execute(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();

        $properties = [
            'user_id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->getRoleNames()->toArray(),
            'is_active' => $user->is_active,
            'email_verified_at' => $user->email_verified_at?->format('M d, Y H:i') ?? '—',
            'phone' => $user->phone,
        ];

        if ($user->hasAnyRole(['admin', 'supervisor'])) {
            activity()
                ->inLog('admin')
                ->event('admin.login')
                ->performedOn($user)
                ->causedBy($user)
                ->withProperties($properties)
                ->log('Admin login');

            return;
        }

        activity()
            ->inLog('admin')
            ->event('user.login')
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties($properties)
            ->log('User login');
    }
}
