<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareTaskLog extends Model
{
    protected $fillable = ['care_task_id', 'ran_at', 'status'];

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
        ];
    }

    public function careTask(): BelongsTo
    {
        return $this->belongsTo(CareTask::class);
    }
}
