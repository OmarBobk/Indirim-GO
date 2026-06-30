<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Enums\AdminDashboardVariant;
use App\Models\User;

class ResolveAdminDashboardVariant
{
    public function handle(User $user): AdminDashboardVariant
    {
        $hasFinance = $user->can('manage_topups')
            || $user->can('view_refunds')
            || $user->can('manage_settlements');
        $hasFulfillment = $user->can('view_fulfillments');
        $hasBreadth = $user->can('manage_users')
            || $user->can('manage_products')
            || $user->can('manage_sections')
            || $user->can('view_activities')
            || $user->can('manage_bugs');

        if ($hasBreadth || ($hasFinance && $hasFulfillment)) {
            return AdminDashboardVariant::Full;
        }

        if ($hasFulfillment && ! $hasFinance) {
            return AdminDashboardVariant::Fulfillment;
        }

        if ($hasFinance && ! $hasFulfillment) {
            return AdminDashboardVariant::Finance;
        }

        if ($user->can('view_orders')) {
            return AdminDashboardVariant::Orders;
        }

        return AdminDashboardVariant::Full;
    }
}
