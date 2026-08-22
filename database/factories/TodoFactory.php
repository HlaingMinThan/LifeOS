<?php

namespace Database\Factories;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Todo> */
class TodoFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Defaults to a fresh owner, so a test that forgets ->for($user)
            // fails loudly instead of silently reading someone else's data.
            'user_id' => User::factory(),
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
