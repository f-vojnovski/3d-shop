<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs `schedule:work` (or cron) running; both are safe to run by hand too.
Schedule::command('files:prune')->daily();
Schedule::command('renders:reap')->hourly();
Schedule::command('views:prune')->hourly();
Schedule::command('payments:reconcile')->everyFifteenMinutes();
