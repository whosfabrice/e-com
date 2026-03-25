<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\BrandReportCache;
use Illuminate\Console\Command;

class WarmBrandReportCache extends Command
{
    protected $signature = 'report:warm-cache {brand? : Optional brand handle}';

    protected $description = 'Warm the cached brand report payloads.';

    public function handle(BrandReportCache $brandReportCache): int
    {
        $brandHandle = $this->argument('brand');

        $brands = $brandHandle
            ? Brand::query()->where('handle', $brandHandle)->get()
            : Brand::query()->get();

        foreach ($brands as $brand) {
            try {
                $brandReportCache->refresh($brand);
                $this->info(sprintf('Warmed report cache for %s.', $brand->name));
            } catch (\Throwable $throwable) {
                $this->error(sprintf('Failed to warm cache for %s: %s', $brand->name, $throwable->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
