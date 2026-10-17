<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The game moves on every half hour. Each step checks what is due, so a
// late or repeated run settles things once.
Schedule::command('game:tick')->everyThirtyMinutes()->withoutOverlapping();
