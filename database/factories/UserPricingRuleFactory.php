<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPricingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPricingRule>
 */
class UserPricingRuleFactory extends Factory
{
    protected $model = UserPricingRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'min_price' => 0,
            'max_price' => 999999.99,
            'wholesale_percentage' => 2,
            'retail_percentage' => 10,
            'priority' => 0,
            'is_active' => true,
            'note' => null,
            'created_by' => null,
        ];
    }
}
