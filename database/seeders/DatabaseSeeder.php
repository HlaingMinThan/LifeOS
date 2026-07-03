<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\LedgerEntry;
use App\Models\Todo;
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

        // Spec §4 demo data so the magic box has real records to match
        // against. Replaced by the brain-dump onboarding later.
        $gonKhaung = Contact::updateOrCreate(
            ['name' => 'Gon Khaung'], ['aliases' => ['ဂွန်ခေါင်']],
        );
        Contact::updateOrCreate(['name' => 'Arkar'], ['aliases' => ['အာကာ']]);
        Contact::updateOrCreate(['name' => 'Cargo Pro'], ['aliases' => ['ကာဂိုပရို']]);

        if (LedgerEntry::count() === 0) {
            LedgerEntry::create([
                'contact_id' => $gonKhaung->id,
                'direction' => 'payable',
                'title' => 'Gon Khaung loan',
                'amount_mmk' => 500000,
                'status' => 'open',
            ]);
        }

        if (Todo::count() === 0) {
            Todo::create(['title' => 'FB page video content', 'bucket' => 'work']);
        }
    }
}
