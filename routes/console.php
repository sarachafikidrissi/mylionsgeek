<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule the reservation end time checker to run every minute
Schedule::command('reservations:check-end-times')
    ->everyMinute()
    ->runInBackground();

Schedule::command('jobs:close-expired')->daily();

Schedule::command('stories:purge-expired')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('calls:mark-missed')
    ->everyMinute()
    ->withoutOverlapping();

$scheduleTimezone = config('app.timezone', 'Africa/Casablanca');

// Same command at each slot open — job resolves morning|lunch|evening via currentSlot()
Schedule::command('attendance:send-slot-reminder')
    ->weekdays()
    ->dailyAt('09:30')
    ->timezone($scheduleTimezone);

Schedule::command('attendance:send-slot-reminder')
    ->weekdays()
    ->dailyAt('11:30')
    ->timezone($scheduleTimezone);

Schedule::command('attendance:send-slot-reminder')
    ->weekdays()
    ->dailyAt('14:00')
    ->timezone($scheduleTimezone);

// Once after the last slot closes — finalizes all unresolved slots through evening (sync)
Schedule::command('attendance:finalize-closed-slots')
    ->weekdays()
    ->dailyAt('17:05')
    ->timezone($scheduleTimezone)
    ->withoutOverlapping();
