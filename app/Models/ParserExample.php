<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParserExample extends Model
{
    protected $fillable = ['raw_text', 'corrected_json'];

    protected function casts(): array
    {
        return [
            'corrected_json' => 'array',
        ];
    }
}
