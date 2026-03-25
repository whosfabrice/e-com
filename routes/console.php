<?php

use App\Console\Commands\SendMediaBuyingReport;
use App\Console\Commands\WarmBrandReportCache;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:work --stop-when-empty --tries=3')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command(WarmBrandReportCache::class)
    ->hourly()
    ->withoutOverlapping();

Schedule::command(SendMediaBuyingReport::class)
    ->weekdays()
    ->at('08:00')
    ->timezone('Europe/Berlin');
