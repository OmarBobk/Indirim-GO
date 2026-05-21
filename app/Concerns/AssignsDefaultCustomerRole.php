<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;

trait AssignsDefaultCustomerRole
{
    /**
     * @param  list<string>  $roleNames
     */
    protected function syncInitialUserRoles(User $user, array $roleNames): void
    {
        $user->syncRoles($roleNames === [] ? ['customer'] : $roleNames);
    }
}
