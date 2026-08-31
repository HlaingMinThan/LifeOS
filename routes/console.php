<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('care:run')->everyMinute();
Schedule::command('todos:remind')->everyMinute();
Schedule::command('digest:send')->dailyAt('07:00');

// New entries are labelled right after the response that created them; this
// is the safety net for the ones that missed — an API blip, or a label the
// model could not place until the category vocabulary had grown around it.
// A no-op run costs one query per account, so daily is cheap and self-healing.
Schedule::command('ledger:categorize')->dailyAt('03:00');
