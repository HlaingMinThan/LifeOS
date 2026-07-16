<?php

namespace Database\Factories;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LedgerEntry> */
class LedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'direction' => fake()->randomElement(['payable', 'receivable']),
            'title' => fake()->sentence(3),
            'amount_mmk' => fake()->numberBetween(1, 50) * 10000,
            'status' => 'open',
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'paid', 'paid_at' => now()]);
    }
}
