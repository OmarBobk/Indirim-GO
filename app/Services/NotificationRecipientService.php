<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Resolves notification recipients. Admins = users with role 'admin' only.
 */
class NotificationRecipientService
{
    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function adminUsers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::role('admin')->get();
    }

    /**
     * Users with an explicit permission (no role-name shortcut).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function usersWithPermission(string $permission): \Illuminate\Database\Eloquent\Collection
    {
        $userIds = User::permission($permission)->pluck('id')->unique()->values();

        if ($userIds->isEmpty()) {
            return User::query()->whereRaw('1 = 0')->get();
        }

        return User::query()->whereIn('id', $userIds)->get();
    }

    /**
     * Admins and users who can update product entry prices (e.g. supervisors).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function priceReviewRecipients(): \Illuminate\Database\Eloquent\Collection
    {
        $permission = (string) config(
            'fulfillment_automation.price_scan.notify_permission',
            'update_product_prices',
        );

        $userIds = User::permission($permission)->pluck('id');

        if (Role::query()->where('name', 'admin')->exists()) {
            $userIds = $userIds->merge(User::role('admin')->pluck('id'));
        }

        $userIds = $userIds->unique()->values();

        if ($userIds->isEmpty()) {
            return User::query()->whereRaw('1 = 0')->get();
        }

        return User::query()->whereIn('id', $userIds)->get();
    }
}
