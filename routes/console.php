<?php

use App\Game\AwayNews;
use App\Models\Visit;
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

// News by email for players who are away, of what happened to their animals.
Schedule::call(fn () => app(AwayNews::class)->sendDue())->everyTenMinutes()->name('away-news')->withoutOverlapping();

// The Visitors page: a fresh country list each month, and visits older than 90 days let go.
Schedule::command('geo:update')->monthlyOn(3, '04:30')->withoutOverlapping();
Schedule::call(fn () => Visit::query()->where('created_at', '<', now()->subDays(Visit::KEEP_DAYS))->delete())->dailyAt('04:10')->name('visits:prune');
