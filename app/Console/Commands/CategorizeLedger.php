<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Money\CategorizerService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfills categories on entries that predate the feature.
 * Safe to re-run: only untouched entries are sent to the model.
 */
class CategorizeLedger extends Command
{
    protected $signature = 'ledger:categorize
        {--user= : limit to one account, by email}
        {--all : re-label entries that already have a category}';

    protected $description = 'Name a spending category for ledger entries that have none';

    public function handle(CategorizerService $categorizer): int
    {
        $users = User::query()
            ->when($this->option('user'), fn ($q, $email) => $q->where('email', $email))
            ->get();

        if ($users->isEmpty()) {
            $this->error('No matching account.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $entries = $user->ledgerEntries()
                ->when(! $this->option('all'), fn ($q) => $q->whereNull('category'))
                ->orderBy('id')
                ->get();

            if ($entries->isEmpty()) {
                $this->line("{$user->email}: nothing to do.");

                continue;
            }

            $this->line("{$user->email}: labelling {$entries->count()} entries…");

            try {
                $done = $categorizer->categorize($user, $entries);
                $this->info("  ✓ {$done} labelled.");
            } catch (Throwable $e) {
                $this->error("  ✗ {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
