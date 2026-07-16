<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as part of one person's Life OS.
 *
 * Scoping is deliberately explicit rather than a global scope: the scheduler,
 * the Telegram webhook and the listener all run with no authenticated user, so
 * a scope keyed on Auth::id() would quietly return nothing there — the kind of
 * bug that looks like "the digest stopped working" a week later.
 */
trait BelongsToUser
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where(
            $this->qualifyColumn('user_id'),
            $user instanceof User ? $user->getKey() : $user,
        );
    }
}
