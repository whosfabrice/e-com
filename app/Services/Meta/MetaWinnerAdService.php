<?php

namespace App\Services\Meta;

use App\Enums\AdvertisingPlatform;
use App\Enums\CampaignPhase;
use App\Enums\TargetMetric;
use App\Models\Brand;
use Illuminate\Support\Collection;

class MetaWinnerAdService
{
    public function __construct(
        protected MetaGraphClient $metaGraphClient,
    )
    {
    }

    public function forBrand(Brand $brand): Collection
    {
        $testingCampaignIds = $brand->campaigns()
            ->where('advertising_platform', AdvertisingPlatform::Meta->value)
            ->where('phase', CampaignPhase::Phase2->value)
            ->pluck('campaign_id')
            ->all();
        $scalingCampaignIds = $brand->campaigns()
            ->where('advertising_platform', AdvertisingPlatform::Meta->value)
            ->where('phase', CampaignPhase::Phase4->value)
            ->pluck('campaign_id')
            ->all();

        if ($testingCampaignIds === []) {
            return collect();
        }

        $targetCpa = $this->targetValueForBrand($brand, TargetMetric::Cpa);
        $targetPurchases = (int) $this->targetValueForBrand($brand, TargetMetric::Purchases);

        if ($targetCpa === null || $targetPurchases <= 0) {
            return collect();
        }

        $response = $this->metaGraphClient->get(
            sprintf('act_%s/insights', $brand->meta_ad_account_id),
            [
                'date_preset' => 'last_7d',
                'fields' => 'campaign_id,campaign_name,ad_id,ad_name,spend,actions',
                'filtering' => json_encode([
                    [
                        'field' => 'campaign.id',
                        'operator' => 'IN',
                        'value' => $testingCampaignIds,
                    ],
                    [
                        'field' => 'spend',
                        'operator' => 'GREATER_THAN',
                        'value' => $targetCpa * $targetPurchases,
                    ],
                ], JSON_THROW_ON_ERROR),
                'level' => 'ad',
                'limit' => 100,
            ],
        );

        $winnerAds = collect($response['data'] ?? [])
            ->map(function (array $ad) use ($brand, $targetCpa, $targetPurchases): ?array {
                $purchases = $this->countPurchases($ad['actions'] ?? []);

                if ($purchases < $targetPurchases) {
                    return null;
                }

                $spend = (float) ($ad['spend'] ?? 0);
                $cpa = round($spend / $purchases, 2);

                if ($cpa > $targetCpa) {
                    return null;
                }

                return [
                    'ad_id' => (string) $ad['ad_id'],
                    'ad_link' => sprintf(
                        'https://www.facebook.com/adsmanager/manage/ads?act=%s&selected_ad_ids=%s',
                        $brand->meta_ad_account_id,
                        $ad['ad_id'],
                    ),
                    'ad_name' => (string) $ad['ad_name'],
                    'campaign_id' => (string) $ad['campaign_id'],
                    'campaign_name' => (string) $ad['campaign_name'],
                    'spend' => $spend,
                    'purchases' => $purchases,
                    'cpa' => $cpa,
                ];
            })
            ->filter()
            ->values();

        if ($winnerAds->isEmpty()) {
            return $winnerAds;
        }

        $adDetails = $this->fetchAdDetails($winnerAds->pluck('ad_id')->all());
        $scaledCreativeIds = $this->fetchScaledCreativeIds($brand, $scalingCampaignIds);

        return $winnerAds
            ->map(fn (array $winnerAd): array => [
                ...$winnerAd,
                'creative_id' => $adDetails[$winnerAd['ad_id']]['creative_id'] ?? null,
                'thumbnail_url' => $adDetails[$winnerAd['ad_id']]['thumbnail_url'] ?? null,
            ])
            ->reject(fn (array $winnerAd): bool => in_array($winnerAd['creative_id'], $scaledCreativeIds, true))
            ->sortByDesc('spend')
            ->values();
    }

    protected function fetchAdDetails(array $adIds): array
    {
        $response = $this->metaGraphClient->get('', [
            'ids' => implode(',', $adIds),
            'fields' => 'creative{id,thumbnail_url}',
        ]);

        return collect($response)
            ->mapWithKeys(fn (array $ad, string $adId): array => [
                $adId => [
                    'creative_id' => data_get($ad, 'creative.id'),
                    'thumbnail_url' => data_get($ad, 'creative.thumbnail_url'),
                ],
            ])
            ->all();
    }

    protected function fetchScaledCreativeIds(Brand $brand, array $scalingCampaignIds): array
    {
        if ($scalingCampaignIds === []) {
            return [];
        }

        $response = $this->metaGraphClient->get(
            sprintf('act_%s/ads', $brand->meta_ad_account_id),
            [
                'fields' => 'id,creative{id}',
                'filtering' => json_encode([
                    [
                        'field' => 'campaign.id',
                        'operator' => 'IN',
                        'value' => $scalingCampaignIds,
                    ],
                ], JSON_THROW_ON_ERROR),
                'limit' => 500,
            ],
        );

        return collect($response['data'] ?? [])
            ->pluck('creative.id')
            ->filter(fn (mixed $creativeId): bool => is_string($creativeId) && $creativeId !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function targetValueForBrand(Brand $brand, TargetMetric $metric): ?float
    {
        $target = $brand->targets()
            ->where('platform', AdvertisingPlatform::Meta->value)
            ->where('metric', $metric->value)
            ->first();

        if ($target === null) {
            return null;
        }

        return (float) $target->value;
    }

    protected function countPurchases(array $actions): int
    {
        $purchaseAction = collect($actions)->firstWhere('action_type', 'purchase');

        return (int) ($purchaseAction['value'] ?? 0);
    }
}
