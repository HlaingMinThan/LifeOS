<?php

namespace App\Services\Money;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns "what did I spend on" into a label the review can group by.
 * The contract does the naming; this class decides what to send it and
 * writes the answer back.
 */
class CategorizerService
{
    /** One request per chunk — big enough to be cheap, small enough to stay accurate. */
    private const CHUNK = 40;

    public function __construct(private CategorizerContract $categorizer) {}

    /**
     * Categorize entries in place. Returns how many were labelled.
     *
     * @param  Collection<int, LedgerEntry>  $entries
     */
    public function categorize(User $user, Collection $entries): int
    {
        if ($entries->isEmpty()) {
            return 0;
        }

        $existing = $this->existingCategories($user);
        $labelled = 0;

        foreach ($entries->chunk(self::CHUNK) as $chunk) {
            $titles = $chunk->mapWithKeys(
                fn (LedgerEntry $e) => [$e->id => $this->label($e)]
            )->all();

            foreach ($this->categorizer->categorize($titles, $existing) as $id => $category) {
                // "Uncategorized" is a display label, never a stored value:
                // null is what marks a row as still needing one, and writing
                // the word would hide it from every later backfill.
                if ($category === LedgerEntry::UNCATEGORIZED) {
                    continue;
                }

                if ($entry = $chunk->firstWhere('id', $id)) {
                    $entry->update(['category' => $category]);
                    $labelled++;
                }
            }

            // Categories named in this chunk are available to the next one,
            // so a long backfill converges instead of drifting into synonyms.
            $existing = $this->existingCategories($user);
        }

        return $labelled;
    }

    /** The distinct categories this user already uses, for prompt context. */
    public function existingCategories(User $user): array
    {
        return $user->ledgerEntries()
            ->whereNotNull('category')
            ->where('category', '!=', LedgerEntry::UNCATEGORIZED)
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    /** The note carries the real purpose far more often than the title does. */
    private function label(LedgerEntry $entry): string
    {
        return trim($entry->title.' '.($entry->note ?? ''));
    }
}
