<?php

declare(strict_types=1);

namespace App\Actions\UserPricingRules;

use App\Models\User;
use App\Models\UserPricingRule;
use Illuminate\Support\Facades\Gate;

class DeleteUserPricingRule
{
    public function handle(User $targetUser, int $ruleId, User $admin): void
    {
        Gate::forUser($admin)->authorize('manage_user_prices');

        $rule = UserPricingRule::query()
            ->where('user_id', $targetUser->id)
            ->whereKey($ruleId)
            ->firstOrFail();

        activity()
            ->inLog('user_prices')
            ->event('user_pricing_rule.deleted')
            ->performedOn($targetUser)
            ->causedBy($admin)
            ->withProperties([
                'user_id' => $targetUser->id,
                'rule_id' => $rule->id,
                'min_price' => $rule->min_price,
                'max_price' => $rule->max_price,
                'wholesale_percentage' => $rule->wholesale_percentage,
                'retail_percentage' => $rule->retail_percentage,
            ])
            ->log('User pricing rule deleted');

        $rule->delete();
    }
}
