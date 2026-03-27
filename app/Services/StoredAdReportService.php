<?php

namespace App\Services;

use App\Enums\AdvertisingPlatform;
use App\Enums\TargetMetric;
use App\Models\AdDailyEntity;
use App\Models\Brand;
use App\Models\MetaPhase4Creative;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StoredAdReportService
{
    public function reportDataForBrand(Brand $brand, int $days = 7): array
    {
        $days = in_array($days, [7, 14, 30], true) ? $days : 7;
        $until = Carbon::now('Europe/Berlin')->subDay()->toDateString();
        $since = Carbon::now('Europe/Berlin')->subDays($days)->toDateString();

        $entities = AdDailyEntity::query()
            ->where('brand_id', $brand->id)
            ->where('platform', AdvertisingPlatform::Meta->value)
            ->whereBetween('date', [$since, $until])
            ->with(['metrics' => fn ($query) => $query
                ->where('source', 'meta')
                ->whereIn('metric', ['spend', 'purchases'])])
            ->get();
        $storedPhase4Creatives = $this->storedPhase4Creatives($brand);

        $dailyRows = $entities
            ->map(fn (AdDailyEntity $entity): array => [
                'date' => $entity->date->toDateString(),
                'ad_id' => $entity->ad_id,
                'ad_name' => $entity->ad_name,
                'campaign_id' => $entity->campaign_id ?? '',
                'campaign_name' => $entity->campaign_name ?? '',
                'creative_id' => $entity->creative_id,
                'thumbnail_url' => $entity->thumbnail_url,
                'spend' => $this->metricValue($entity, 'spend'),
                'purchases' => (int) $this->metricValue($entity, 'purchases'),
            ])
            ->values();

        $dailyTotals = $this->buildDailyTotals($dailyRows, $days);
        $fetchedAds = $this->buildFetchedAds($brand, $dailyRows);
        $scalingCampaigns = $this->buildScalingCampaigns($storedPhase4Creatives);
        $winnerAds = $this->buildWinnerAds($brand, $fetchedAds, $storedPhase4Creatives);
        $scaledAdNames = $storedPhase4Creatives
            ->pluck('ad_name')
            ->map(fn (mixed $adName): string => $this->normalizeAdName((string) $adName))
            ->filter(fn (string $adName): bool => $adName !== '')
            ->unique()
            ->values()
            ->all();
        $coverage = $this->buildCoverage($brand);

        return [
            'winner_ads' => $winnerAds,
            'fetched_ads' => $fetchedAds,
            'scaling_campaigns' => $scalingCampaigns,
            'scaled_ad_names' => $scaledAdNames,
            'daily_totals' => $dailyTotals,
            'coverage' => $coverage,
        ];
    }

    protected function buildFetchedAds(Brand $brand, Collection $dailyRows): Collection
    {
        return $dailyRows
            ->groupBy('ad_id')
            ->map(function (Collection $group) use ($brand): array {
                $first = $group->first();
                $spend = round($group->sum('spend'), 2);
                $purchases = (int) $group->sum('purchases');
                $adId = (string) ($first['ad_id'] ?? '');

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
                    'creative_id' => $group->pluck('creative_id')->first(fn (mixed $creativeId): bool => is_string($creativeId) && $creativeId !== ''),
                    'thumbnail_url' => $group->pluck('thumbnail_url')->first(fn (mixed $thumbnailUrl): bool => is_string($thumbnailUrl) && $thumbnailUrl !== ''),
                    'spend' => $spend,
                    'purchases' => $purchases,
                    'cpa' => $purchases > 0 ? round($spend / $purchases, 2) : 0.0,
                ];
            })
            ->values();
    }

    protected function buildDailyTotals(Collection $dailyRows, int $days): Collection
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

        return collect(range($days, 1))
            ->map(function (int $daysAgo) use ($groupedByDate): array {
                $date = Carbon::now('Europe/Berlin')->subDays($daysAgo)->toDateString();
                $totals = $groupedByDate->get($date, [
                    'spend' => 0.0,
                    'purchases' => 0,
                    'cpa' => 0.0,
                ]);

                return [
                    'date' => $date,
                    'spend' => (float) $totals['spend'],
                    'purchases' => (int) $totals['purchases'],
                    'cpa' => (float) $totals['cpa'],
                ];
            })
            ->values();
    }

    protected function buildScalingCampaigns(Collection $dailyRows): Collection
    {
        return $dailyRows
            ->filter(fn (array $row): bool => str_starts_with($row['campaign_name'], 'Coredrive | Phase 4'))
            ->map(fn (array $row): array => [
                'campaign_id' => $row['campaign_id'],
                'campaign_name' => $row['campaign_name'],
            ])
            ->unique(fn (array $campaign): string => $campaign['campaign_id'])
            ->sortBy('campaign_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    protected function buildWinnerAds(Brand $brand, Collection $fetchedAds, Collection $storedPhase4Creatives): Collection
    {
        $targetCpa = $this->targetValueForBrand($brand, TargetMetric::Cpa);
        $targetPurchases = (int) $this->targetValueForBrand($brand, TargetMetric::Purchases);

        if ($targetCpa === null || $targetPurchases <= 0) {
            return collect();
        }

        $scaledAdNames = $storedPhase4Creatives
            ->pluck('ad_name')
            ->map(fn (mixed $adName): string => $this->normalizeAdName((string) $adName))
            ->filter(fn (string $adName): bool => $adName !== '')
            ->unique()
            ->values()
            ->all();

        return $fetchedAds
            ->filter(fn (array $ad): bool => str_starts_with($ad['campaign_name'], 'Coredrive | Phase 2'))
            ->filter(fn (array $ad): bool => $ad['purchases'] >= $targetPurchases)
            ->filter(fn (array $ad): bool => $ad['cpa'] <= $targetCpa)
            ->reject(fn (array $ad): bool => in_array($this->normalizeAdName((string) ($ad['ad_name'] ?? '')), $scaledAdNames, true))
            ->sortByDesc('spend')
            ->values();
    }

    protected function storedPhase4Creatives(Brand $brand): Collection
    {
        return MetaPhase4Creative::query()
            ->where('brand_id', $brand->id)
            ->get(['campaign_id', 'campaign_name', 'ad_name', 'creative_id'])
            ->map(fn (MetaPhase4Creative $creative): array => [
                'campaign_id' => $creative->campaign_id,
                'campaign_name' => $creative->campaign_name,
                'ad_name' => $creative->ad_name ?? '',
                'creative_id' => $creative->creative_id,
            ])
            ->values();
    }

    protected function normalizeAdName(string $adName): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($adName)) ?? '');
    }

    protected function metricValue(AdDailyEntity $entity, string $metric): float
    {
        return (float) ($entity->metrics->firstWhere('metric', $metric)?->value ?? 0);
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

    protected function buildCoverage(Brand $brand): ?array
    {
        $dates = AdDailyEntity::query()
            ->where('brand_id', $brand->id)
            ->where('platform', AdvertisingPlatform::Meta->value)
            ->orderBy('date')
            ->pluck('date')
            ->map(fn (mixed $date): string => Carbon::parse((string) $date, 'Europe/Berlin')->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return null;
        }

        $from = Carbon::parse((string) $dates->first(), 'Europe/Berlin')->startOfDay();
        $to = Carbon::parse((string) $dates->last(), 'Europe/Berlin')->startOfDay();
        $availableDates = $dates->flip();
        $missingDates = collect();
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $date = $cursor->toDateString();

            if (! $availableDates->has($date)) {
                $missingDates->push($date);
            }

            $cursor->addDay();
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'missing_ranges' => $this->compressDateRanges($missingDates),
        ];
    }

    protected function compressDateRanges(Collection $missingDates): array
    {
        if ($missingDates->isEmpty()) {
            return [];
        }

        $ranges = [];
        $rangeStart = null;
        $rangeEnd = null;

        foreach ($missingDates as $date) {
            $current = Carbon::parse((string) $date, 'Europe/Berlin')->startOfDay();

            if ($rangeStart === null) {
                $rangeStart = $current->copy();
                $rangeEnd = $current->copy();
                continue;
            }

            if ($current->equalTo($rangeEnd->copy()->addDay())) {
                $rangeEnd = $current->copy();
                continue;
            }

            $ranges[] = $rangeStart->equalTo($rangeEnd)
                ? $rangeStart->toDateString()
                : $rangeStart->toDateString().' to '.$rangeEnd->toDateString();

            $rangeStart = $current->copy();
            $rangeEnd = $current->copy();
        }

        if ($rangeStart !== null && $rangeEnd !== null) {
            $ranges[] = $rangeStart->equalTo($rangeEnd)
                ? $rangeStart->toDateString()
                : $rangeStart->toDateString().' to '.$rangeEnd->toDateString();
        }

        return $ranges;
    }
}
