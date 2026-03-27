<?php

use App\Console\Commands\PruneMetaDaily;
use App\Console\Commands\SendMediaBuyingReport;
use App\Console\Commands\SyncMetaDaily;
use App\Console\Commands\SyncMetaPhase4Creatives;
use App\Console\Commands\SyncMetaWinnerAdThumbnails;
use App\Console\Commands\WarmBrandReportCache;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:work --stop-when-empty --tries=3')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command(PruneMetaDaily::class, ['--days' => 180])
    ->dailyAt('05:50')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping();

Schedule::command(SyncMetaDaily::class, ['--days' => 7])
    ->weekdays()
    ->at('05:55')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping();

Schedule::command(SyncMetaPhase4Creatives::class)
    ->dailyAt('06:00')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping();

Schedule::command(SyncMetaWinnerAdThumbnails::class)
    ->dailyAt('06:05')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping();

/*Schedule::command(SendMediaBuyingReport::class)
    ->weekdays()
    ->at('08:00')
    ->timezone('Europe/Berlin');*/
