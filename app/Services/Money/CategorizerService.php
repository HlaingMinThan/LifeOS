<?php

namespace App\Services\Money;

use App\Models\CategoryRule;
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

        $labelled = 0;

        // A merchant the user has already ruled on is filed straight away:
        // no API call, and — more importantly — the same answer every time.
        // Classifying each entry in isolation is what let one petrol station
        // scatter across three categories in the first place.
        $rules = CategoryRule::forUser($user)->filing()->pluck('category', 'cluster_key');

        [$ruled, $entries] = $entries->partition(
            fn (LedgerEntry $e) => $rules->has($e->clusterKey())
        );

        foreach ($ruled as $entry) {
            $entry->update(['category' => $rules->get($entry->clusterKey())]);
            $labelled++;
        }

        if ($entries->isEmpty()) {
            return $labelled;
        }

        $existing = $this->existingCategories($user);

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

    /**
     * Name a category for each detected cluster.
     *
     * Reuses the entry categorizer by handing it the cluster's sample lines as
     * one blob of text — naming "these four petrol transactions" is the same
     * job as naming one, so it gets the same prompt and the same vocabulary.
     *
     * @param  array<int, array<string, mixed>>  $clusters  from PatternDetector
     * @return array<string, string> suggested category keyed by cluster key
     */
    public function suggestFor(User $user, array $clusters): array
    {
        if ($clusters === []) {
            return [];
        }

        $samples = collect($clusters)->mapWithKeys(fn (array $c) => [
            $c['key'] => implode(' · ', $c['samples']),
        ])->all();

        return collect($this->categorizer->categorize($samples, $this->existingCategories($user)))
            // A cluster it cannot place is left for the user to name themselves.
            ->reject(fn (string $category) => $category === LedgerEntry::UNCATEGORIZED)
            ->all();
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
