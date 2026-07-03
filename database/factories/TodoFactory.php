<?php

namespace Database\Factories;

use App\Models\Todo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Todo> */
class TodoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'bucket' => fake()->randomElement(['work', 'personal', 'money_task']),
            'status' => 'open',
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => ['status' => 'done', 'done_at' => now()]);
    }
}
