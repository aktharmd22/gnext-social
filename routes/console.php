<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|------------------------------------------------------------------------------
| Scheduled work
|------------------------------------------------------------------------------
| Requires a single cron entry on the server:
|
|   * * * * * cd /path/to/gnextsocial && php artisan schedule:run >> /dev/null 2>&1
|
| On Windows, the equivalent is a Task Scheduler entry running every minute.
*/

/*
| The clock. Instagram has no scheduling API, so this is what makes a post go
| out at the minute someone chose.
|
| withoutOverlapping matters: a slow Instagram video container can take longer
| than a minute, and a second pass finding the same due row would try to publish
| it twice. The job's own unique lock is the second line of defence.
*/
Schedule::command('gnext:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

/*
| Kill the worker for two hours and restart it: these publish, late, rather than
| vanishing. Hourly rather than per-minute because the per-minute dispatcher
| already covers the normal case; this is purely for gaps in uptime.
*/
Schedule::command('gnext:recover-missed')
    ->hourly()
    ->withoutOverlapping();

/*
| Long-lived Page tokens expire. Not "might" -- will. Early enough in the
| morning that an admin has the day to act on it.
*/
Schedule::command('gnext:check-tokens')
    ->dailyAt('07:00')
    ->withoutOverlapping();

/*
| Metrics at +24h, +7d and +30d after each post goes out.
*/
Schedule::command('gnext:capture-insights')
    ->hourly()
    ->withoutOverlapping();

/*
| What published, what is scheduled, what is empty next week.
*/
Schedule::command('gnext:weekly-digest')
    ->weeklyOn(0, '08:00');
