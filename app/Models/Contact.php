<?php

namespace App\Models;

use App\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = ['name', 'aliases'];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
        ];
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** Does this text still refer to this contact, by name or any alias? */
    public function matchesName(?string $text): bool
    {
        if (! $text) {
            return false;
        }

        $text = mb_strtolower(trim($text));

        foreach ([$this->name, ...($this->aliases ?? [])] as $candidate) {
            $candidate = mb_strtolower(trim((string) $candidate));
            if ($candidate !== '' && str_contains($text, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** "Gon Khaung (aliases: ဂွန်ခေါင်)" — the format the parser prompt expects. */
    public function promptLabel(): string
    {
        $aliases = collect($this->aliases ?? [])->reject(fn ($a) => $a === $this->name);

        return $aliases->isEmpty()
            ? $this->name
            : "{$this->name} (aliases: {$aliases->implode(', ')})";
    }
}
