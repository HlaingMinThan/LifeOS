<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An invitation, and once accepted the membership itself. Assignment is
 * one-way: the owner may assign todos to the member, never the reverse.
 *
 * @property int $id
 * @property int $owner_id
 * @property int|null $member_id
 * @property string $email
 * @property string $status
 * @property string $token
 * @property Carbon|null $accepted_at
 */
class TeamMember extends Model
{
    use HasFactory;

    protected $fillable = ['member_id', 'email', 'status', 'token', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', 'accepted');
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    /** Name to show before the invite is taken up — no account exists yet. */
    public function displayName(): string
    {
        return $this->member?->name ?? $this->email;
    }
}
