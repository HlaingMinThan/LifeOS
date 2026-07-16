<?php

namespace App\Models;

use App\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Todo extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    // user_id is absent on purpose: ownership comes from the relation used to
    // create the record ($user->todos()->create(...)), never from request input.
    protected $fillable = ['title', 'note', 'bucket', 'status', 'focused', 'due_date', 'due_time', 'reminded_at', 'done_at'];

    protected function casts(): array
    {
        return [
            // Y-m-d keeps JSON free of a midnight timestamp that shifts a
            // day when converted to UTC.
            'due_date' => 'date:Y-m-d',
            'focused' => 'boolean',
            'reminded_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    /** Open, has a time, not yet reminded, and that moment has passed. */
    public function scopeDueForReminder(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('due_date')
            ->whereNotNull('due_time')
            ->whereNull('reminded_at')
            ->whereDate('due_date', '<=', today());
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereDate('due_date', '<', today());
    }
}
