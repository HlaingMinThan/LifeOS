<?php

namespace App\Models;

use App\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LedgerEntry extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $fillable = [
        'contact_id', 'direction', 'title', 'amount_mmk', 'amount_usd',
        'status', 'due_date', 'paid_at', 'note',
    ];

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
}
