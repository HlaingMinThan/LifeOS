<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('care:run')->everyMinute();
Schedule::command('digest:send')->dailyAt('07:00');
