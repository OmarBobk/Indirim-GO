<?php

declare(strict_types=1);

namespace App\Actions\UserPricingRules;

use App\Models\User;
use App\Models\UserPricingRule;
use Illuminate\Support\Facades\Gate;

class UpsertUserPricingRule
{
    /**
     * @param  array{
     *     min_price: float|string,
     *     max_price: float|string,
     *     wholesale_percentage: float|string,
     *     retail_percentage: float|string,
     *     priority: int,
     *     is_active: bool,
     *     note?: string|null
     * }  $data
     */
    public function handle(User $targetUser, ?int $ruleId, array $data, User $admin): UserPricingRule
    {
        Gate::forUser($admin)->authorize('manage_user_prices');

        $rule = $ruleId !== null
            ? UserPricingRule::query()
                ->where('user_id', $targetUser->id)
                ->whereKey($ruleId)
                ->firstOrFail()
            : new UserPricingRule(['user_id' => $targetUser->id]);

        $changed = $rule->exists
            ? [
                'min_price' => $rule->min_price,
                'max_price' => $rule->max_price,
                'wholesale_percentage' => $rule->wholesale_percentage,
                'retail_percentage' => $rule->retail_percentage,
                'priority' => $rule->priority,
                'is_active' => $rule->is_active,
                'note' => $rule->note,
            ]
            : [];

        $rule->fill([
            'min_price' => $data['min_price'],
            'max_price' => $data['max_price'],
            'wholesale_percentage' => $data['wholesale_percentage'],
            'retail_percentage' => $data['retail_percentage'],
            'priority' => (int) $data['priority'],
            'is_active' => $data['is_active'],
            'note' => $data['note'] ?? null,
            'created_by' => $rule->exists ? $rule->created_by : $admin->id,
        ]);

        $rule->save();

        $event = $rule->wasRecentlyCreated ? 'user_pricing_rule.created' : 'user_pricing_rule.updated';

        activity()
            ->inLog('user_prices')
            ->event($event)
            ->performedOn($targetUser)
            ->causedBy($admin)
            ->withProperties([
                'user_id' => $targetUser->id,
                'rule_id' => $rule->id,
                'min_price' => $rule->min_price,
                'max_price' => $rule->max_price,
                'wholesale_percentage' => $rule->wholesale_percentage,
                'retail_percentage' => $rule->retail_percentage,
                'priority' => $rule->priority,
                'is_active' => $rule->is_active,
                'note' => $rule->note,
                'changed' => $rule->wasRecentlyCreated ? null : $changed,
            ])
            ->log($rule->wasRecentlyCreated ? 'User pricing rule created' : 'User pricing rule updated');

        return $rule;
    }
}
