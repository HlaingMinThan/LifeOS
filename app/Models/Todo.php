<?php

namespace App\Models;

use App\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    /**
     * Who assigned this to me, if anyone. Like user_id this is never fillable —
     * it is set from the assigning relation, so a request cannot forge
     * provenance and grant itself sight of someone else's list.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    public function isAssigned(): bool
    {
        return $this->assigned_by_id !== null;
    }

    /**
     * Open todos due in the next seven days, across everyone this user can
     * see: their own list plus the tasks they assigned to teammates. Ordered
     * the way a week reads — soonest first, timed before untimed.
     */
    public static function weekAhead(User $user): Builder
    {
        return static::query()
            ->with(['user:id,name,username', 'assignedBy:id,name,username'])
            ->open()
            ->where(fn (Builder $q) => $q->where('user_id', $user->id)
                ->orWhere('assigned_by_id', $user->id))
            ->whereBetween('due_date', [
                today()->toDateString(),
                today()->addDays(6)->toDateString(),
            ])
            ->orderBy('due_date')
            ->orderByRaw('due_time is null')
            ->orderBy('due_time');
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
