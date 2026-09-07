<?php

use App\Services\Publisher;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sendae:publish')->everyMinute()->withoutOverlapping(15);
Schedule::call(fn () => app(Publisher::class)->refreshDueAnalytics())->hourly()->name('sendae-analytics')->withoutOverlapping(60);
