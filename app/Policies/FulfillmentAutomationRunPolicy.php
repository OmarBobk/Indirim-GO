<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FulfillmentAutomationRun;
use App\Models\User;

class FulfillmentAutomationRunPolicy
{
    public function view(User $user, FulfillmentAutomationRun $run): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->can('view_fulfillments') || $user->can('manage_fulfillments');
    }
}
