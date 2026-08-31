<?php

namespace App\Services\Money;

use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

/**
 * "Is my spending in good shape?" answered from settled entries.
 *
 * Everything here is cash-flow: an entry counts on the day its money moved
 * (see LedgerEntry::scopeSettledBetween), so an unpaid bill sitting in the
 * ledger changes the outstanding section, never the savings rate.
 */
class ReviewService
{
    /** Saving at least this share of income reads as healthy. */
    private const HEALTHY_SAVINGS_RATE = 20;

    /** Categories below this share of spending collapse into "Other". */
    private const SMALL_CATEGORY_SHARE = 3;

    /** $ym is "2026-08". */
    public function monthSummary(User $user, string $ym): array
    {
        $start = Date::parse($ym.'-01')->startOfMonth();
        $prevStart = $start->subMonth();

        $current = $this->periodSummary($user, $start, $start->endOfMonth());
        $previous = $this->periodSummary($user, $prevStart, $prevStart->endOfMonth());

        return $current + [
            'month' => $ym,
            'label' => $start->format('F Y'),
            'previous' => $previous + ['label' => $prevStart->format('F Y')],
            'change' => [
                'income' => $this->percentChange($previous['income'], $current['income']),
                'expenses' => $this->percentChange($previous['expenses'], $current['expenses']),
            ],
        ];
    }

    /** The Mon–Sun window containing $day. */
    public function weekSummary(User $user, ?CarbonInterface $day = null): array
    {
        $start = ($day ?? Date::now())->startOfWeek();
        $end = $start->endOfWeek();

        return $this->periodSummary($user, $start, $end) + [
            'label' => $start->format('j M').' – '.$end->format('j M'),
        ];
    }

    /**
     * What is still owed, in and out, bucketed by how late it is.
     * This is the half of the picture the savings rate cannot show.
     */
    public function outstanding(User $user): array
    {
        $today = Date::now()->startOfDay();
        $weekEnd = $today->addDays(6);

        $open = $user->ledgerEntries()->open()->get();

        $bucket = function (string $key) use ($open, $today, $weekEnd) {
            $items = $open->filter(function (LedgerEntry $e) use ($key, $today, $weekEnd) {
                $due = $e->due_date;

                return match ($key) {
                    'overdue' => $due && $due->lt($today),
                    'this_week' => $due && $due->gte($today) && $due->lte($weekEnd),
                    'later' => $due && $due->gt($weekEnd),
                    default => ! $due,
                };
            });

            return [
                'receivable' => $this->tally($items->where('direction', 'receivable')),
                'payable' => $this->tally($items->where('direction', 'payable')),
            ];
        };

        return [
            'overdue' => $bucket('overdue'),
            'this_week' => $bucket('this_week'),
            'later' => $bucket('later'),
            'no_date' => $bucket('no_date'),
        ];
    }

    /**
     * The one-glance verdict. Overdue bills are weighed alongside the savings
     * rate on purpose: a month can look thrifty only because its bills were
     * never paid, and that is the opposite of healthy.
     */
    public function indicator(array $month, array $outstanding): array
    {
        $rate = $month['savings_rate'];
        $overdueBills = $outstanding['overdue']['payable'];

        if ($month['income'] === 0 && $month['expenses'] === 0) {
            return $this->verdict('none', '⚪️', 'Nothing settled this month yet.');
        }

        if ($month['net'] < 0) {
            return $this->verdict('bad', '🔴', 'You spent more than you took in this month.');
        }

        if ($overdueBills['count'] > 0) {
            return $this->verdict('watch', '🟡', $overdueBills['count'].' overdue '
                .($overdueBills['count'] === 1 ? 'bill is' : 'bills are')
                .' still unpaid — '.number_format($overdueBills['total']).' Ks.');
        }

        if ($rate !== null && $rate >= self::HEALTHY_SAVINGS_RATE) {
            return $this->verdict('good', '🟢', "Healthy — keeping {$rate}% of what you earned.");
        }

        if ($rate === null) {
            return $this->verdict('watch', '🟡', 'Spending recorded, but no income this month.');
        }

        return $this->verdict('watch', '🟡', "Keeping {$rate}% — under the "
            .self::HEALTHY_SAVINGS_RATE.'% mark.');
    }

    /**
     * One category, opened up: the real transactions behind the total.
     * Sorted biggest-first — the question being asked is "what cost me a lot",
     * and that answer is at the top of the list, not in date order.
     */
    public function categoryDetail(User $user, string $name, string $ym): array
    {
        $start = Date::parse($ym.'-01')->startOfMonth();
        $previous = $start->subMonth();

        $entries = $this->categoryEntries($user, $name, $start, $start->endOfMonth());
        $total = (int) $entries->sum('amount_mmk');
        $count = $entries->count();

        $previousTotal = (int) $this->categoryEntries(
            $user, $name, $previous, $previous->endOfMonth()
        )->sum('amount_mmk');

        // Share is of the month's whole spend, so the row reads the same here
        // as it does in the breakdown that linked to it.
        $monthSpend = (int) $user->ledgerEntries()->payable()
            ->settledBetween($start->toDateString(), $start->endOfMonth()->toDateString())
            ->sum('amount_mmk');

        return [
            'category' => $name,
            'month' => $ym,
            'label' => $start->format('F Y'),
            'total' => $total,
            'count' => $count,
            'average' => $count > 0 ? (int) round($total / $count) : 0,
            'biggest' => (int) $entries->max('amount_mmk'),
            'share' => $monthSpend > 0 ? (int) round(($total / $monthSpend) * 100) : 0,
            'previous' => ['total' => $previousTotal, 'label' => $previous->format('F Y')],
            'change' => $this->percentChange($previousTotal, $total),
            'trend' => $this->categoryTrend($user, $name, $start),
            'entries' => $entries->sortByDesc('amount_mmk')->values()->map(fn (LedgerEntry $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'amount_mmk' => $e->amount_mmk,
                'date' => ($e->paid_at ?? $e->due_date)?->toDateString(),
                'note' => $e->note,
                'contact' => $e->contact?->name,
                'image' => $e->image,
            ])->all(),
        ];
    }

    /** Monthly totals for one category, oldest first — is this growing? */
    public function categoryTrend(User $user, string $name, CarbonInterface $anchor, int $months = 6): array
    {
        $anchor = $anchor->startOfMonth();
        $first = $anchor->subMonths($months - 1);

        // One query for the whole window; bucketing by month happens in PHP
        // so the settled-date fallback stays in a single place.
        $totals = $user->ledgerEntries()->payable()->inCategory($name)
            ->settledBetween($first->toDateString(), $anchor->endOfMonth()->toDateString())
            ->get(['amount_mmk', 'paid_at', 'due_date'])
            ->groupBy(fn (LedgerEntry $e) => ($e->paid_at ?? $e->due_date)->format('Y-m'))
            ->map(fn ($group) => (int) $group->sum('amount_mmk'));

        return collect(range(0, $months - 1))
            ->map(function (int $i) use ($first, $totals) {
                $month = $first->addMonths($i);

                return [
                    'month' => $month->format('Y-m'),
                    'label' => $month->format('M'),
                    'total' => $totals->get($month->format('Y-m'), 0),
                ];
            })->all();
    }

    /** @return Collection<int, LedgerEntry> */
    private function categoryEntries(User $user, string $name, CarbonInterface $start, CarbonInterface $end)
    {
        return $user->ledgerEntries()->payable()->inCategory($name)
            ->settledBetween($start->toDateString(), $end->toDateString())
            ->with('contact')
            ->get();
    }

    /** Income, spending and the category split for any window. */
    public function periodSummary(User $user, CarbonInterface $start, CarbonInterface $end): array
    {
        $entries = $user->ledgerEntries()
            ->settledBetween($start->toDateString(), $end->toDateString())
            ->get(['id', 'direction', 'amount_mmk', 'category']);

        $income = (int) $entries->where('direction', 'receivable')->sum('amount_mmk');
        $expenses = (int) $entries->where('direction', 'payable')->sum('amount_mmk');

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'income' => $income,
            'expenses' => $expenses,
            'net' => $income - $expenses,
            // Undefined without income — showing 0% would read as "saved
            // nothing" when the truth is "earned nothing".
            'savings_rate' => $income > 0
                ? (int) round((($income - $expenses) / $income) * 100)
                : null,
            'categories' => $this->categoryBreakdown($entries->where('direction', 'payable'), $expenses),
        ];
    }

    /** Spending grouped by category, biggest first, long tail folded into "Other". */
    private function categoryBreakdown(iterable $payables, int $total): array
    {
        $groups = collect($payables)
            ->groupBy(fn (LedgerEntry $e) => $e->category ?: LedgerEntry::UNCATEGORIZED)
            ->map(fn ($items, $name) => [
                'category' => $name,
                'count' => $items->count(),
                'total' => (int) $items->sum('amount_mmk'),
            ])
            ->sortByDesc('total')
            ->values();

        if ($total === 0) {
            return $groups->all();
        }

        $share = fn (int $amount) => (int) round(($amount / $total) * 100);

        // A dozen 1% rows bury the three that actually explain the month.
        [$big, $small] = $groups->partition(
            fn ($g) => $share($g['total']) >= self::SMALL_CATEGORY_SHARE
        );

        $rows = $big->map(fn ($g) => $g + ['share' => $share($g['total']), 'members' => []])->all();

        if ($small->isNotEmpty()) {
            // "Other" is a display bucket, not a label anything carries, so it
            // has no detail page of its own — it names its members instead so
            // each one stays reachable.
            $rows[] = [
                'category' => 'Other',
                'count' => $small->sum('count'),
                'total' => $small->sum('total'),
                'share' => $share((int) $small->sum('total')),
                'members' => $small->map(fn ($g) => [
                    'category' => $g['category'],
                    'total' => $g['total'],
                    'count' => $g['count'],
                ])->values()->all(),
            ];
        }

        return $rows;
    }

    private function tally(iterable $items): array
    {
        $items = collect($items);

        return ['count' => $items->count(), 'total' => (int) $items->sum('amount_mmk')];
    }

    /** Null when there is no baseline to compare against. */
    private function percentChange(int $from, int $to): ?int
    {
        return $from > 0 ? (int) round((($to - $from) / $from) * 100) : null;
    }

    private function verdict(string $level, string $emoji, string $message): array
    {
        return ['level' => $level, 'emoji' => $emoji, 'message' => $message];
    }
}
