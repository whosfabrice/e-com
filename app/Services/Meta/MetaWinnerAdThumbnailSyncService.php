<?php

namespace App\Services\Meta;

use App\Enums\AdvertisingPlatform;
use App\Models\AdDailyEntity;
use App\Models\Brand;
use App\Services\StoredAdReportService;
use Illuminate\Support\Collection;

class MetaWinnerAdThumbnailSyncService
{
    public function __construct(
        protected StoredAdReportService $storedAdReportService,
        protected MetaWinnerAdService $metaWinnerAdService,
    )
    {
    }

    public function sync(Brand $brand): array
    {
        $winnerCandidates = collect([7, 14, 30])
            ->flatMap(fn (int $days): Collection => collect($this->storedAdReportService->reportDataForBrand($brand, $days)['winner_ads'] ?? []))
            ->unique('ad_id')
            ->values();

        if ($winnerCandidates->isEmpty()) {
            return ['count' => 0];
        }

        $enrichedWinners = $this->metaWinnerAdService->enrichAdsWithCreativeDetails($brand, $winnerCandidates);
        $updatedCount = 0;

        foreach ($enrichedWinners as $winnerAd) {
            $adId = (string) ($winnerAd['ad_id'] ?? '');

            if ($adId === '') {
                continue;
            }

            $attributes = [];

            if (is_string($winnerAd['thumbnail_url'] ?? null) && $winnerAd['thumbnail_url'] !== '') {
                $attributes['thumbnail_url'] = $winnerAd['thumbnail_url'];
            }

            if (is_string($winnerAd['creative_id'] ?? null) && $winnerAd['creative_id'] !== '') {
                $attributes['creative_id'] = $winnerAd['creative_id'];
            }

            if ($attributes === []) {
                continue;
            }

            $updatedCount += AdDailyEntity::query()
                ->where('brand_id', $brand->id)
                ->where('platform', AdvertisingPlatform::Meta->value)
                ->where('ad_id', $adId)
                ->update($attributes);
        }

        return [
            'count' => $updatedCount,
        ];
    }
}
