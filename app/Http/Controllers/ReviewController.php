<?php

namespace App\Http\Controllers;

use App\Models\CategoryRule;
use App\Models\LedgerEntry;
use App\Services\Money\CategorizerService;
use App\Services\Money\PatternDetector;
use App\Services\Money\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ReviewController extends Controller
{
    public function __construct(
        private ReviewService $review,
        private PatternDetector $patterns,
        private CategorizerService $categorizer,
    ) {}

    /** Is my spending in good shape: month, week, categories, what's owed. */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? $request->query('month')
            : $this->review->latestActiveMonth($user);

        $monthly = $this->review->monthSummary($user, $month);
        $outstanding = $this->review->outstanding($user);

        return Inertia::render('os/MoneyReview', [
            'monthly' => $monthly,
            'thisWeek' => $this->review->weekSummary($user),
            'lastWeek' => $this->review->weekSummary($user, Date::now()->subWeek()),
            'outstanding' => $outstanding,
            'indicator' => $this->review->indicator($monthly, $outstanding),
            // Free to compute — pure grouping, no API call — so it can ride
            // along on every load. Naming them is what costs, and that waits
            // for a tap.
            'patterns' => $this->patterns->detect($user),
            // Every label in use, so fixing a cluster is picking from a list
            // rather than retyping a name that has to match exactly to group.
            'knownCategories' => $this->categorizer->existingCategories($user),
        ]);
    }

    /**
     * Ask the model to name the detected clusters. This is the only step that
     * costs anything, so it runs on demand rather than on page load.
     */
    public function nameSuggestions(Request $request): JsonResponse
    {
        $user = $request->user();
        $clusters = $this->patterns->detect($user);

        try {
            $named = $this->categorizer->suggestFor($user, $clusters);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'Could not reach the categorizer — try again in a moment.'], 422);
        }

        return response()->json(['suggestions' => $named]);
    }

    /** Accept a suggestion: file every entry in the cluster, and remember why. */
    public function applySuggestion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:60'],
            'label' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $category = trim($data['category']);

        // Re-derive membership from the key rather than trusting ids from the
        // page: the cluster may have grown since it was rendered, and an id
        // list from the client is a list of someone else's entries waiting to
        // happen.
        $entries = $user->ledgerEntries()->payable()->with('contact')->get()
            ->filter(fn (LedgerEntry $e) => $e->clusterKey() === $data['key']);

        $user->ledgerEntries()->whereKey($entries->pluck('id'))->update(['category' => $category]);

        // The rule is the durable half: future entries from this merchant are
        // filed the same way without asking the model again.
        CategoryRule::updateOrCreate(
            ['user_id' => $user->id, 'cluster_key' => $data['key']],
            ['category' => $category, 'label' => $data['label']],
        );

        return back();
    }

    /** Not a category — remembered so it stops being suggested. */
    public function dismissSuggestion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'label' => ['required', 'string', 'max:255'],
        ]);

        CategoryRule::updateOrCreate(
            ['user_id' => $request->user()->id, 'cluster_key' => $data['key']],
            ['category' => null, 'label' => $data['label']],
        );

        return back();
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
