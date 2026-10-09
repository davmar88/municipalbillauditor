<?php

use Illuminate\Support\Facades\Schedule;

// Emails users about lodge/escalation deadlines due within 5 days.
Schedule::command('deadlines:remind')
    ->dailyAt('07:00')
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping()
    ->onOneServer();
