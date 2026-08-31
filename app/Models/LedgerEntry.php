<?php

namespace App\Models;

use App\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;

class LedgerEntry extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'contact_id', 'direction', 'title', 'amount_mmk', 'amount_usd',
        'status', 'due_date', 'paid_at', 'note', 'image', 'category',
    ];

    /** Shown wherever an entry has no category yet, and used as a group key. */
    public const UNCATEGORIZED = 'Uncategorized';

    protected function casts(): array
    {
        return [
            'amount_mmk' => 'integer',
            'amount_usd' => 'decimal:2',
            // Y-m-d keeps JSON free of a midnight timestamp that shifts a
            // day when converted to UTC.
            'due_date' => 'date:Y-m-d',
            'paid_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopePayable(Builder $query): Builder
    {
        return $query->where('direction', 'payable');
    }

    public function scopeReceivable(Builder $query): Builder
    {
        return $query->where('direction', 'receivable');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }

    /**
     * Settled entries whose money actually moved inside the window.
     *
     * The review is cash-flow, not accrual: an entry counts on the day it was
     * paid, not the day it was owed. paid_at is set whenever an entry is
     * settled, but older rows imported before that was true fall back to
     * due_date so their money is not silently dropped from every period.
     */
    public function scopeSettledBetween(Builder $query, string $start, string $end): Builder
    {
        return $query->paid()->whereRaw(
            'date(coalesce(paid_at, due_date)) between ? and ?', [$start, $end]
        );
    }

    /** $ym is "2026-08". */
    public function scopeForMonth(Builder $query, string $ym): Builder
    {
        $start = Date::parse($ym.'-01');

        return $query->settledBetween(
            $start->toDateString(),
            $start->endOfMonth()->toDateString(),
        );
    }
}
