<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\SoftDeletes;

class CareTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title', 'schedule_type', 'time_of_day', 'weekday',
        'random_min_days', 'random_max_days', 'next_run_at', 'active',
    ];

    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CareTaskLog::class);
    }

    /**
     * Compute the run after $from. Random schedules pick a fresh offset
     * every time — that is what keeps surprises unpredictable.
     */
    public function nextRunAfter(CarbonInterface $from): CarbonInterface
    {
        $time = $this->time_of_day ?? '09:00:00';

        return match ($this->schedule_type) {
            'daily' => $from->copy()->addDay()->setTimeFromTimeString($time),
            'weekly' => $from->copy()->next($this->weekday ?? 1)->setTimeFromTimeString($time),
            'random' => $from->copy()
                ->addDays(random_int($this->random_min_days ?? 7, $this->random_max_days ?? 20))
                ->setTimeFromTimeString($time),
        };
    }
}
