<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

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

    /** "Gon Khaung (aliases: ဂွန်ခေါင်)" — the format the parser prompt expects. */
    public function promptLabel(): string
    {
        $aliases = collect($this->aliases ?? [])->reject(fn ($a) => $a === $this->name);

        return $aliases->isEmpty()
            ? $this->name
            : "{$this->name} (aliases: {$aliases->implode(', ')})";
    }
}
