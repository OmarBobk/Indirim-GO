<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'image' => null,
            'account_text' => fake()->iban(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }

    public function shamCash(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Sham Cash',
            'account_text' => 'SHAM-123456',
            'sort_order' => 0,
        ]);
    }

    public function eftTransfer(): static
    {
        return $this->state(fn (): array => [
            'name' => 'EFT Transfer',
            'account_text' => 'TR00 0000 0000 0000 0000 0000 00',
            'sort_order' => 1,
        ]);
    }
}
