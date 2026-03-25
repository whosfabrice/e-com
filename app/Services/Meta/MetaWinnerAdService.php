<?php

namespace App\Services\Meta;

use App\Enums\AdvertisingPlatform;
use App\Enums\TargetMetric;
use App\Models\Brand;
use Illuminate\Support\Carbon;
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
        return $this->reportDataForBrand($brand)['winner_ads'];
    }

    public function reportDataForBrand(Brand $brand): array
    {
        $targetCpa = $this->targetValueForBrand($brand, TargetMetric::Cpa);
        $targetPurchases = (int) $this->targetValueForBrand($brand, TargetMetric::Purchases);
        $scalingCampaigns = $this->fetchPhase4Campaigns($brand);

        $response = $this->metaGraphClient->get(
            sprintf('act_%s/insights', $brand->meta_ad_account_id),
            [
                'date_preset' => 'last_7d',
                'fields' => 'campaign_id,campaign_name,ad_id,ad_name,date_start,date_stop,spend,actions',
                'filtering' => json_encode([
                    [
                        'field' => 'campaign.name',
                        'operator' => 'CONTAIN',
                        'value' => 'Coredrive | ',
                    ],
                    [
                        'field' => 'spend',
                        'operator' => 'GREATER_THAN',
                        'value' => 0,
                    ],
                ], JSON_THROW_ON_ERROR),
                'level' => 'ad',
                'limit' => 500,
                'time_increment' => 1,
            ],
        );

        $dailyRows = collect($response['data'] ?? [])
            ->map(fn (array $ad): array => [
                'ad_id' => (string) $ad['ad_id'],
                'ad_name' => (string) $ad['ad_name'],
                'campaign_id' => (string) $ad['campaign_id'],
                'campaign_name' => (string) $ad['campaign_name'],
                'date' => (string) ($ad['date_start'] ?? ''),
                'spend' => (float) ($ad['spend'] ?? 0),
                'purchases' => $this->countPurchases($ad['actions'] ?? []),
            ])
            ->reject(fn (array $ad): bool => str_starts_with($ad['ad_name'], '[KEEP OFF]'))
            ->values();

        $fetchedAds = $dailyRows
            ->groupBy('ad_id')
            ->map(function (Collection $group) use ($brand): array {
                $first = $group->first();
                $spend = round($group->sum('spend'), 2);
                $purchases = (int) $group->sum('purchases');
                $adId = (string) ($first['ad_id'] ?? '');
                $cpa = $purchases > 0 ? round($spend / $purchases, 2) : 0.0;

                return [
                    'ad_id' => $adId,
                    'ad_link' => sprintf(
                        'https://www.facebook.com/adsmanager/manage/ads?act=%s&selected_ad_ids=%s',
                        $brand->meta_ad_account_id,
                        $adId,
                    ),
                    'ad_name' => (string) ($first['ad_name'] ?? ''),
                    'campaign_id' => (string) ($first['campaign_id'] ?? ''),
                    'campaign_name' => (string) ($first['campaign_name'] ?? ''),
                    'spend' => $spend,
                    'purchases' => $purchases,
                    'cpa' => $cpa,
                ];
            })
            ->values()
            ->values();

        $dailyTotals = $this->buildDailyTotals($dailyRows);

        $phase4Ads = $this->fetchPhase4Ads($brand, $scalingCampaigns);
        $scaledCreativeIds = $phase4Ads
            ->pluck('creative_id')
            ->filter(fn (mixed $creativeId): bool => is_string($creativeId) && $creativeId !== '')
            ->unique()
            ->values()
            ->all();

        if ($targetCpa === null || $targetPurchases <= 0) {
            return [
                'winner_ads' => collect(),
                'fetched_ads' => $fetchedAds,
                'scaling_campaigns' => $scalingCampaigns,
                'daily_totals' => $dailyTotals,
            ];
        }

        $winnerAds = $fetchedAds
            ->filter(fn (array $ad): bool => str_starts_with($ad['campaign_name'], 'Coredrive | Phase 2'))
            ->filter(fn (array $ad): bool => $ad['purchases'] >= $targetPurchases)
            ->filter(fn (array $ad): bool => $ad['cpa'] <= $targetCpa)
            ->values();

        if ($winnerAds->isEmpty()) {
            return [
                'winner_ads' => $winnerAds,
                'fetched_ads' => $fetchedAds,
                'scaling_campaigns' => $scalingCampaigns,
                'daily_totals' => $dailyTotals,
            ];
        }

        $adDetails = $this->fetchAdDetails($winnerAds->pluck('ad_id')->all());

        return [
            'winner_ads' => $winnerAds
            ->map(fn (array $winnerAd): array => [
                ...$winnerAd,
                'creative_id' => $adDetails[$winnerAd['ad_id']]['creative_id'] ?? null,
                'thumbnail_url' => $adDetails[$winnerAd['ad_id']]['thumbnail_url'] ?? null,
            ])
            ->reject(fn (array $winnerAd): bool => in_array($winnerAd['creative_id'], $scaledCreativeIds, true))
            ->sortByDesc('spend')
            ->values(),
            'fetched_ads' => $fetchedAds,
            'scaling_campaigns' => $scalingCampaigns,
            'daily_totals' => $dailyTotals,
        ];
    }

    protected function buildDailyTotals(Collection $dailyRows): Collection
    {
        $groupedByDate = $dailyRows
            ->groupBy('date')
            ->map(function (Collection $group): array {
                $spend = round($group->sum('spend'), 2);
                $purchases = (int) $group->sum('purchases');

                return [
                    'spend' => $spend,
                    'purchases' => $purchases,
                    'cpa' => $purchases > 0 ? round($spend / $purchases, 2) : 0.0,
                ];
            });

        return collect(range(7, 1))
            ->map(function (int $daysAgo) use ($groupedByDate): array {
                $date = Carbon::now('Europe/Berlin')->subDays($daysAgo)->toDateString();
                $dailyTotal = $groupedByDate->get($date, [
                    'spend' => 0.0,
                    'purchases' => 0,
                    'cpa' => 0.0,
                ]);

                return [
                    'date' => $date,
                    'spend' => (float) $dailyTotal['spend'],
                    'purchases' => (int) $dailyTotal['purchases'],
                    'cpa' => (float) $dailyTotal['cpa'],
                ];
            })
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
                    'creative_id' => $this->normalizeMetaId(data_get($ad, 'creative.id')),
                    'thumbnail_url' => data_get($ad, 'creative.thumbnail_url'),
                ],
            ])
            ->all();
    }

    protected function fetchPhase4Campaigns(Brand $brand): Collection
    {
        $response = $this->metaGraphClient->get(
            sprintf('act_%s/campaigns', $brand->meta_ad_account_id),
            [
                'fields' => 'id,name,effective_status',
                'effective_status' => json_encode(['ACTIVE'], JSON_THROW_ON_ERROR),
                'limit' => 500,
            ],
        );

        return collect($response['data'] ?? [])
            ->filter(fn (array $campaign): bool => str_starts_with((string) ($campaign['name'] ?? ''), 'Coredrive | Phase 4'))
            ->map(fn (array $campaign): array => [
                'campaign_id' => (string) ($campaign['id'] ?? ''),
                'campaign_name' => (string) ($campaign['name'] ?? ''),
            ])
            ->filter(fn (array $campaign): bool => $campaign['campaign_id'] !== '' && $campaign['campaign_name'] !== '')
            ->sortBy('campaign_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    protected function fetchPhase4Ads(Brand $brand, Collection $scalingCampaigns): Collection
    {
        $campaignIds = $scalingCampaigns->pluck('campaign_id')->all();

        if ($campaignIds === []) {
            return collect();
        }

        $campaignNamesById = $scalingCampaigns
            ->mapWithKeys(fn (array $campaign): array => [
                $campaign['campaign_id'] => $campaign['campaign_name'],
            ])
            ->all();

        $response = $this->metaGraphClient->get(
            sprintf('act_%s/ads', $brand->meta_ad_account_id),
            [
                'fields' => 'id,campaign_id,creative{id}',
                'filtering' => json_encode([
                    [
                        'field' => 'campaign.id',
                        'operator' => 'IN',
                        'value' => $campaignIds,
                    ],
                ], JSON_THROW_ON_ERROR),
                'limit' => 500,
            ],
        );

        return collect($response['data'] ?? [])
            ->map(fn (array $ad): array => [
                'campaign_id' => (string) ($ad['campaign_id'] ?? ''),
                'campaign_name' => $campaignNamesById[(string) ($ad['campaign_id'] ?? '')] ?? '',
                'creative_id' => $this->normalizeMetaId(data_get($ad, 'creative.id')),
            ])
            ->filter(fn (array $ad): bool => $ad['campaign_id'] !== '' && $ad['campaign_name'] !== '')
            ->values();
    }

    protected function normalizeMetaId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
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
