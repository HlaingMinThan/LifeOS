<?php

namespace App\Models;

use App\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InboxEvent extends Model
{
    use BelongsToUser;

    protected $fillable = [
        'raw_text', 'parsed_json', 'applied',
        'subject_type', 'subject_id', 'reverted_at',
    ];

    protected function casts(): array
    {
        return [
            'parsed_json' => 'array',
            'applied' => 'boolean',
            'reverted_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
