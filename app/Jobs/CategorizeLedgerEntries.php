<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Money\CategorizerService;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Labels everything this user has left unlabelled.
 *
 * User-scoped rather than entry-scoped on purpose: a pasted brain dump can
 * create thirty entries in one request, and the service batches forty per
 * API call — so the whole dump costs one call instead of thirty. Dispatching
 * this more than once in a request is harmless; later runs find nothing.
 *
 * Deliberately NOT queued: it is dispatched with ->afterResponse(), so the
 * work happens once the user has their page back and no queue worker has to
 * be running for categories to appear.
 */
class CategorizeLedgerEntries
{
    use Dispatchable;

    /** A ceiling so one request can never turn into an unbounded backfill. */
    private const LIMIT = 200;

    public function __construct(private User $user) {}

    public function handle(CategorizerService $categorizer): void
    {
        try {
            $entries = $this->user->ledgerEntries()
                ->whereNull('category')
                ->latest('id')
                ->limit(self::LIMIT)
                ->get();

            if ($entries->isNotEmpty()) {
                $categorizer->categorize($this->user, $entries);
            }
        } catch (Throwable $e) {
            // A missing label is cosmetic, and `ledger:categorize` picks it up
            // later — never let it surface as a failed save.
            report($e);
        }
    }
}
