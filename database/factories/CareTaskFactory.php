<?php

namespace Database\Factories;

use App\Models\CareTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CareTask> */
class CareTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'schedule_type' => 'daily',
            'time_of_day' => '09:00:00',
            'next_run_at' => now()->addDay(),
            'active' => true,
        ];
    }

    public function weekly(int $weekday = 1): static
    {
        return $this->state(fn () => ['schedule_type' => 'weekly', 'weekday' => $weekday]);
    }

    public function random(int $min = 7, int $max = 20): static
    {
        return $this->state(fn () => [
            'schedule_type' => 'random',
            'random_min_days' => $min,
            'random_max_days' => $max,
        ]);
    }
}
