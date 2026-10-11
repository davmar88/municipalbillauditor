<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deadline reminders go out each morning, South African time.
Schedule::command('disputes:remind')->dailyAt('07:00')->timezone('Africa/Johannesburg')->withoutOverlapping();
