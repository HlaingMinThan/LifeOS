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

    /**
     * Which recurring merchant this entry belongs to.
     *
     * A linked contact is the strongest signal and survives spelling drift, so
     * it wins. Otherwise the first two words of the title stand in: branches
     * and reference numbers trail the merchant name ("Max Energy-Thein Phyu",
     * "Buy DataPack U9 (0994…)"), so the head of the string is the part that
     * repeats. Both detection and rule lookup derive the key here, so they can
     * never disagree about what counts as the same merchant.
     */
    public function clusterKey(): string
    {
        if ($this->contact_id) {
            return "contact:{$this->contact_id}";
        }

        $words = preg_split('/[\s\-]+/', trim((string) $this->title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return 'title:'.mb_strtolower(implode(' ', array_slice($words, 0, 2)));
    }

    /** How the merchant is named to the user. */
    public function clusterLabel(): string
    {
        return $this->contact?->name ?: $this->title;
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

    /**
     * Entries carrying one category label. "Uncategorized" is a display name
     * for the absence of one, so it has to resolve to NULL rather than to a
     * row that literally stores that word (nothing ever writes it).
     */
    public function scopeInCategory(Builder $query, string $name): Builder
    {
        return $name === self::UNCATEGORIZED
            ? $query->whereNull('category')
            : $query->where('category', $name);
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
