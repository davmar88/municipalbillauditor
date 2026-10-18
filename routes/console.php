<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The game checks every minute what is due, so raids, crimes and walks
// settle on time. Each step checks what is due, so a late or repeated run
// settles things once; upkeep and income are paid once per half hour.
Schedule::command('game:tick')->everyMinute()->withoutOverlapping();
