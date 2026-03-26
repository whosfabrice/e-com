<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Services\BrandReportCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WarmBrandReportCache implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $brandId,
        public int $days = 7,
    )
    {
    }

    public function handle(BrandReportCache $brandReportCache): void
    {
        $brand = Brand::query()->find($this->brandId);

        if ($brand === null) {
            return;
        }

        try {
            $brandReportCache->refresh($brand, $this->days);
        } finally {
            $brandReportCache->clearWarming($brand, $this->days);
        }
    }
}
