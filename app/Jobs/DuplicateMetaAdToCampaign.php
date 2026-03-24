<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Services\Meta\MetaAdDuplicator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DuplicateMetaAdToCampaign implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $brandId,
        public string $adId,
        public string $campaignId,
    ) {
    }

    public function handle(MetaAdDuplicator $metaAdDuplicator): void
    {
        $brand = Brand::query()->findOrFail($this->brandId);

        Log::info('Queued ad duplication started.', [
            'brand_id' => $this->brandId,
            'campaign_id' => $this->campaignId,
            'ad_id' => $this->adId,
        ]);

        $metaAdDuplicator->duplicateToCampaign(
            $brand,
            $this->adId,
            $this->campaignId,
        );

        Log::info('Queued ad duplication finished.', [
            'brand_id' => $this->brandId,
            'campaign_id' => $this->campaignId,
            'ad_id' => $this->adId,
        ]);
    }
}
