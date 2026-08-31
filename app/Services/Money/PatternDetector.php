<?php

namespace App\Services\Money;

use App\Models\CategoryRule;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Finds recurring merchants whose entries disagree with each other.
 *
 * Every entry is classified in isolation, so the model never sees that it
 * filed this same merchant differently last week — three trips to one petrol
 * station end up under Shopping, Business and nothing at all. This spots that
 * scatter. It is pure SQL and PHP: no API call, so it can run on every page
 * load and only the naming step ever costs anything.
 */
class PatternDetector
{
    /** Below this a "pattern" is just a coincidence. */
    private const MIN_ENTRIES = 2;

    /**
     * Clusters worth asking the user about, biggest money first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function detect(User $user): array
    {
        // Rules record decisions already made — accepted or dismissed — so a
        // settled merchant is never raised twice.
        $decided = CategoryRule::forUser($user)->pluck('cluster_key')->all();

        // Ordered so the cluster's label is always its earliest entry: without
        // this the name shown could change between page loads on DB whim.
        return $user->ledgerEntries()->payable()->with('contact')->orderBy('id')->get()
            ->groupBy(fn (LedgerEntry $e) => $e->clusterKey())
            ->reject(fn (Collection $group, string $key) => in_array($key, $decided, true))
            ->filter(fn (Collection $group) => $group->count() >= self::MIN_ENTRIES)
            ->map(fn (Collection $group, string $key) => $this->describe($key, $group))
            ->filter(fn (array $c) => $c['conflicted'] || $c['unlabelled'])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /** @param  Collection<int, LedgerEntry>  $group */
    private function describe(string $key, Collection $group): array
    {
        // NULL is a bucket like any other here: a merchant half-labelled and
        // half-not is exactly the inconsistency worth fixing.
        $categories = $group->map(fn (LedgerEntry $e) => $e->category)->unique()->values();
        $labelled = $categories->filter()->values();

        return [
            'key' => $key,
            'label' => $group->first()->clusterLabel(),
            'count' => $group->count(),
            'total' => (int) $group->sum('amount_mmk'),
            'current' => $categories->map(fn (?string $c) => $c ?? LedgerEntry::UNCATEGORIZED)->all(),
            // The model reads these to name the merchant.
            'samples' => $group->take(4)->map(fn (LedgerEntry $e) => trim(
                $e->title.' '.($e->note ?? '')
            ))->values()->all(),
            'conflicted' => $categories->count() > 1,
            'unlabelled' => $labelled->isEmpty(),
            'entry_ids' => $group->pluck('id')->all(),
        ];
    }
}
