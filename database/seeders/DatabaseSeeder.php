<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Single-user app: the one and only account.
        User::updateOrCreate(
            ['email' => 'hlaingminthan92@gmail.com'],
            [
                'name' => 'Hlaing Min Than',
                'password' => Hash::make(env('SEED_USER_PASSWORD', 'lifeos-2026')),
                'email_verified_at' => now(),
            ],
        );
    }
}
