<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\BrandReportCache;
use Illuminate\Console\Command;

class WarmBrandReportCache extends Command
{
    protected $signature = 'report:warm-cache {brand? : Optional brand handle} {days? : Optional timeframe in days}';

    protected $description = 'Warm the cached brand report payloads.';

    public function handle(BrandReportCache $brandReportCache): int
    {
        $brandHandle = $this->argument('brand');
        $daysArgument = $this->argument('days');
        $timeframes = $daysArgument !== null
            ? [max(1, (int) $daysArgument)]
            : [7, 14, 30];

        $brands = $brandHandle
            ? Brand::query()->where('handle', $brandHandle)->get()
            : Brand::query()->get();

        foreach ($brands as $brand) {
            foreach ($timeframes as $days) {
                try {
                    $brandReportCache->refresh($brand, $days);
                    $this->info(sprintf('Warmed %s-day report cache for %s.', $days, $brand->name));
                } catch (\Throwable $throwable) {
                    $this->error(sprintf('Failed to warm %s-day cache for %s: %s', $days, $brand->name, $throwable->getMessage()));
                }
            }
        }

        return self::SUCCESS;
    }
}
