<?php

namespace App\Models;

use App\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A merchant the user has already ruled on: "everything from Max Energy is
 * Fuel". Rules are consulted before the model is, so a known merchant is
 * filed instantly, consistently, and without an API call.
 */
class CategoryRule extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = ['user_id', 'cluster_key', 'category', 'label'];

    /** Rules that file entries, as opposed to dismissals that only silence. */
    public function scopeFiling(Builder $query): Builder
    {
        return $query->whereNotNull('category');
    }

    /** The user said "leave this alone" — remembered so it is not re-suggested. */
    public function isDismissal(): bool
    {
        return $this->category === null;
    }
}
