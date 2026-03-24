<?php

use App\Console\Commands\SendMediaBuyingReport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:work --stop-when-empty --tries=3')
    ->everyMinute()
    ->withoutOverlapping();

// Schedule::command(SendMediaBuyingReport::class)->dailyAt('09:00');
