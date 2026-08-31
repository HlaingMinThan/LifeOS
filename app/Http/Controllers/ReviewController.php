<?php

namespace App\Http\Controllers;

use App\Services\Money\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function __construct(private ReviewService $review) {}

    /** Is my spending in good shape: month, week, categories, what's owed. */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? $request->query('month')
            : Date::now()->format('Y-m');

        $monthly = $this->review->monthSummary($user, $month);
        $outstanding = $this->review->outstanding($user);

        return Inertia::render('os/MoneyReview', [
            'monthly' => $monthly,
            'thisWeek' => $this->review->weekSummary($user),
            'lastWeek' => $this->review->weekSummary($user, Date::now()->subWeek()),
            'outstanding' => $outstanding,
            'indicator' => $this->review->indicator($monthly, $outstanding),
        ]);
    }

    /** One category opened up: the transactions behind the total. */
    public function category(Request $request): Response
    {
        $name = trim((string) $request->query('name'));

        abort_if($name === '', 404);

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? $request->query('month')
            : Date::now()->format('Y-m');

        return Inertia::render('os/MoneyCategory', [
            'detail' => $this->review->categoryDetail($request->user(), $name, $month),
        ]);
    }

    /**
     * Rename a category across every entry that carries it. Categories are
     * strings on entries, not rows in a table, so the rename IS the update.
     */
    public function rename(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:60'],
            'to' => ['required', 'string', 'max:60'],
        ]);

        $request->user()->ledgerEntries()
            ->where('category', $data['from'])
            ->update(['category' => trim($data['to'])]);

        return back();
    }
}
