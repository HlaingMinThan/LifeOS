<?php

namespace App\Services\Money;

use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonInterface;
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

        $rows = $big->map(fn ($g) => $g + ['share' => $share($g['total'])])->all();

        if ($small->isNotEmpty()) {
            $rows[] = [
                'category' => 'Other',
                'count' => $small->sum('count'),
                'total' => $small->sum('total'),
                'share' => $share((int) $small->sum('total')),
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
