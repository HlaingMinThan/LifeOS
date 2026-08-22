<?php

namespace App\Models;

use App\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class ParserExample extends Model
{
    use BelongsToUser;

    protected $fillable = ['raw_text', 'corrected_json'];

    protected function casts(): array
    {
        return [
            'corrected_json' => 'array',
        ];
    }
}
