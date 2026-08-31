<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $telegram_bot_token
 * @property string|null $telegram_bot_username
 * @property string|null $telegram_chat_id
 * @property array|null $telegram_authorized_chats
 * @property string|null $telegram_webhook_secret
 * @property Carbon|null $telegram_linked_at
 * @property Carbon|null $telegram_prompt_dismissed_at
 * @property Carbon|null $notification_prompt_dismissed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden([
    'password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token',
    // A bot token is a bearer credential: anyone holding it controls the bot.
    // Hidden so it can never ride along in an Inertia prop or JSON response.
    'telegram_bot_token', 'telegram_webhook_secret',
])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'telegram_bot_token' => 'encrypted',
            'telegram_authorized_chats' => 'array',
            'telegram_linked_at' => 'datetime',
            'telegram_prompt_dismissed_at' => 'datetime',
            'notification_prompt_dismissed_at' => 'datetime',
        ];
    }

    /** Both halves are needed: a token can send, but only to a known chat. */
    public function hasTelegram(): bool
    {
        return filled($this->telegram_bot_token) && filled($this->telegram_chat_id);
    }

    /** Is this chat_id the owner or an authorized collaborator? */
    public function isTelegramAuthorized(string $chatId): bool
    {
        if ((string) $this->telegram_chat_id === $chatId) {
            return true;
        }

        return in_array($chatId, $this->telegram_authorized_chats ?? []);
    }

    /** @return HasMany<Todo, $this> */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** @return HasMany<CareTask, $this> */
    public function careTasks(): HasMany
    {
        return $this->hasMany(CareTask::class);
    }

    /** @return HasMany<Idea, $this> */
    public function ideas(): HasMany
    {
        return $this->hasMany(Idea::class);
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /** @return HasMany<InboxEvent, $this> */
    public function inboxEvents(): HasMany
    {
        return $this->hasMany(InboxEvent::class);
    }

    /** @return HasMany<ParserExample, $this> */
    public function parserExamples(): HasMany
    {
        return $this->hasMany(ParserExample::class);
    }

    /** People I invited into my team (any status). */
    public function teamMembers(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'owner_id');
    }

    /** Teams I belong to — i.e. who may assign work to me. */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'member_id');
    }

    /** Todos I handed to teammates — the only ones I may see of theirs. */
    public function assignedTodos(): HasMany
    {
        return $this->hasMany(Todo::class, 'assigned_by_id');
    }

    /** Accepted members of my team, resolved to accounts. */
    public function teammates(): Collection
    {
        return $this->teamMembers()->accepted()->with('member')->get()
            ->pluck('member')->filter()->values();
    }

    public function canAssignTo(User $other): bool
    {
        return $this->teamMembers()->accepted()->where('member_id', $other->id)->exists();
    }
}
