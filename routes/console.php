<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deadline reminders go out each morning, South African time.
Schedule::command('disputes:remind')->dailyAt('07:00')->timezone('Africa/Johannesburg')->withoutOverlapping();

// Agents' and businesses' monthly fees are raised on the 1st.
Schedule::command('fees:invoice')->monthlyOn(1, '06:00')->timezone('Africa/Johannesburg')->withoutOverlapping();

// Card payments still open are checked with Paystack. Its webhook can't be
// relied on: an account has one, and a shared account's goes to the other site.
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping();
