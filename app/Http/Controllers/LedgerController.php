<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Services\Inbox\ClaudeParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    /** Calendar month view + capped open entry lists. */
    public function index(Request $request): Response
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? $request->query('month')
            : now()->format('Y-m');
        $start = now()->parse($month.'-01');

        $entries = $request->user()->ledgerEntries();

        // Per-day counts for the calendar (both open due_date + settled paid_at).
        $dueCounts = (clone $entries)->open()
            ->whereBetween('due_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->get(['due_date', 'direction'])
            ->groupBy(fn ($e) => $e->due_date->toDateString())
            ->map(fn ($group) => [
                'income' => $group->where('direction', 'receivable')->count(),
                'expense' => $group->where('direction', 'payable')->count(),
            ]);

        $paidCounts = (clone $entries)->where('status', 'paid')
            ->whereBetween('paid_at', [$start->startOfDay(), $start->copy()->endOfMonth()->endOfDay()])
            ->get(['paid_at', 'direction'])
            ->groupBy(fn ($e) => $e->paid_at->toDateString())
            ->map(fn ($group) => [
                'income' => $group->where('direction', 'receivable')->count(),
                'expense' => $group->where('direction', 'payable')->count(),
            ]);

        // Merge both count sets per date.
        $counts = [];
        foreach ($dueCounts->union($paidCounts)->keys() as $date) {
            $due = $dueCounts->get($date, ['income' => 0, 'expense' => 0]);
            $paid = $paidCounts->get($date, ['income' => 0, 'expense' => 0]);
            $counts[$date] = [
                'income' => $due['income'] + $paid['income'],
                'expense' => $due['expense'] + $paid['expense'],
            ];
        }

        // Capped open lists: This Week + Later (max 10 each).
        $todayIso = now()->toDateString();
        $weekEndIso = now()->addDays(6)->toDateString();

        $openEntries = (clone $entries)->open()->with('contact')
            ->orderByRaw('due_date is null')->orderBy('due_date')->get();

        $thisWeek = $openEntries->filter(
            fn ($e) => $e->due_date && $e->due_date->toDateString() >= $todayIso
                && $e->due_date->toDateString() <= $weekEndIso
        )->take(10)->values();

        $later = $openEntries->filter(
            fn ($e) => $e->due_date && $e->due_date->toDateString() > $weekEndIso
        )->take(10)->values();

        $overdue = $openEntries->filter(
            fn ($e) => $e->due_date && $e->due_date->toDateString() < $todayIso
        )->values();

        $noDate = $openEntries->filter(fn ($e) => ! $e->due_date)->values();

        return Inertia::render('os/Money', [
            'month' => $start->format('Y-m'),
            'counts' => $counts,
            'overdue' => $overdue,
            'thisWeek' => $thisWeek,
            'later' => $later,
            'noDate' => $noDate,
        ]);
    }

    /** Day detail: open entries due on this date + settled entries paid on this date. */
    public function day(Request $request, string $date): Response
    {
        $dateStr = now()->parse($date)->toDateString();
        $entries = $request->user()->ledgerEntries();

        $dueOnDay = (clone $entries)->open()->with('contact')
            ->whereDate('due_date', $dateStr)->get();

        $paidOnDay = (clone $entries)->where('status', 'paid')->with('contact')
            ->whereDate('paid_at', $dateStr)->get();

        $all = $dueOnDay->concat($paidOnDay)->unique('id')->sortBy('direction')->values();

        return Inertia::render('os/MoneyDay', [
            'date' => $date,
            'entries' => $all,
        ]);
    }

    /** Parse an uploaded screenshot with Claude vision and return extracted fields. */
    public function parseScreenshot(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $file = $request->file('image');
        $imageData = file_get_contents($file->getRealPath());
        $ext = $file->getClientOriginalExtension() ?: 'jpg';

        // Store the image so it can be attached to the entry later.
        $storagePath = $file->store('ledger', 'public');

        try {
            $parsed = app(ClaudeParser::class)->parseImage($imageData, $ext, '', $request->user());
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Could not parse the screenshot. Please fill in manually.',
                'image' => $storagePath,
            ], 422);
        }

        return response()->json([
            'direction' => match ($parsed['action']) {
                'add_receivable', 'income_received' => 'receivable',
                default => 'payable',
            },
            'title' => $parsed['target'] ?? '',
            'amount_mmk' => $parsed['amount_mmk'] ?? null,
            'note' => $parsed['note'] ?? null,
            'time' => $parsed['time'] ?? null,
            'image' => $storagePath,
            'confidence' => $parsed['confidence'] ?? 0,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request) + ['status' => 'open'];

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('ledger', 'public');
        } elseif ($request->filled('stored_image')) {
            $data['image'] = $request->input('stored_image');
        }

        // Screenshot entries are born settled (paid immediately).
        if ($data['image'] ?? null) {
            $data['status'] = 'paid';
            $data['paid_at'] = now();
        }

        $request->user()->ledgerEntries()->create($data);

        return back();
    }

    public function update(Request $request, int $entry): RedirectResponse
    {
        $model = $this->find($request, $entry);
        $validated = $this->validated($request);

        if ($request->hasFile('image')) {
            if ($model->image) {
                Storage::disk('public')->delete($model->image);
            }
            $validated['image'] = $request->file('image')->store('ledger', 'public');
        }

        // A contact is only attached because it matched the original title.
        // Renaming away from it makes the link a lie, so drop it unless the
        // new title still refers to that contact (name or alias).
        if ($model->contact && $validated['title'] !== $model->title
            && ! $model->contact->matchesName($validated['title'])) {
            $validated['contact_id'] = null;
        }

        $model->update($validated);

        return back();
    }

    public function toggle(Request $request, int $entry): RedirectResponse
    {
        $model = $this->find($request, $entry);

        $model->update($model->status === 'open'
            ? ['status' => 'paid', 'paid_at' => now()]
            : ['status' => 'open', 'paid_at' => null]);

        return back();
    }

    public function destroy(Request $request, int $entry): RedirectResponse
    {
        $model = $this->find($request, $entry);

        if ($model->image) {
            Storage::disk('public')->delete($model->image);
        }

        $model->delete();

        return back();
    }

    /** Resolve through the owner, so another user's id is a 404, not a leak. */
    private function find(Request $request, int $id): LedgerEntry
    {
        return $request->user()->ledgerEntries()->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'direction' => ['required', 'in:payable,receivable'],
            'title' => ['required', 'string', 'max:255'],
            'amount_mmk' => ['required', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);
    }
}
