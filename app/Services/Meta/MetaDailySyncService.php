<?php

namespace App\Services\Meta;

use App\Enums\AdvertisingPlatform;
use App\Models\AdDailyEntity;
use App\Models\AdDailyMetric;
use App\Models\Brand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MetaDailySyncService
{
    public function __construct(
        protected MetaGraphClient $metaGraphClient,
    )
    {
    }

    public function sync(Brand $brand, Carbon|string $since, Carbon|string $until): array
    {
        $sinceDate = $since instanceof Carbon ? $since->toDateString() : Carbon::parse($since, 'Europe/Berlin')->toDateString();
        $untilDate = $until instanceof Carbon ? $until->toDateString() : Carbon::parse($until, 'Europe/Berlin')->toDateString();
        $platform = AdvertisingPlatform::Meta->value;
        $now = now();

        $dailyRows = $this->fetchDailyRows($brand, $sinceDate, $untilDate);
        $adDetails = $this->fetchAdDetails($dailyRows->pluck('ad_id')->unique()->values()->all());

        $entityRows = $dailyRows
            ->map(function (array $row) use ($adDetails, $brand, $now, $platform): array {
                return [
                    'brand_id' => $brand->id,
                    'platform' => $platform,
                    'date' => $row['date'],
                    'ad_id' => $row['ad_id'],
                    'ad_name' => $row['ad_name'],
                    'campaign_id' => $row['campaign_id'] !== '' ? $row['campaign_id'] : null,
                    'campaign_name' => $row['campaign_name'] !== '' ? $row['campaign_name'] : null,
                    'creative_id' => $adDetails[$row['ad_id']]['creative_id'] ?? null,
                    'thumbnail_url' => $adDetails[$row['ad_id']]['thumbnail_url'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->values();

        DB::transaction(function () use ($brand, $entityRows, $platform, $sinceDate, $untilDate, $dailyRows, $now): void {
            AdDailyEntity::query()
                ->where('brand_id', $brand->id)
                ->where('platform', $platform)
                ->whereBetween('date', [$sinceDate, $untilDate])
                ->delete();

            if ($entityRows->isEmpty()) {
                return;
            }

            AdDailyEntity::query()->insert($entityRows->all());

            $entityIds = AdDailyEntity::query()
                ->where('brand_id', $brand->id)
                ->where('platform', $platform)
                ->whereBetween('date', [$sinceDate, $untilDate])
                ->get(['id', 'date', 'ad_id'])
                ->mapWithKeys(fn (AdDailyEntity $entity): array => [
                    $this->entityKey($entity->date->toDateString(), $entity->ad_id) => $entity->id,
                ]);

            $metricRows = $dailyRows
                ->flatMap(function (array $row) use ($entityIds, $now): array {
                    $entityId = $entityIds[$this->entityKey($row['date'], $row['ad_id'])] ?? null;

                    if (! is_int($entityId)) {
                        return [];
                    }

                    return [
                        [
                            'ad_daily_entity_id' => $entityId,
                            'source' => 'meta',
                            'metric' => 'spend',
                            'value' => $row['spend'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        [
                            'ad_daily_entity_id' => $entityId,
                            'source' => 'meta',
                            'metric' => 'purchases',
                            'value' => $row['purchases'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    ];
                })
                ->values();

            if ($metricRows->isNotEmpty()) {
                AdDailyMetric::query()->insert($metricRows->all());
            }
        });

        return [
            'since' => $sinceDate,
            'until' => $untilDate,
            'entity_count' => $entityRows->count(),
            'metric_count' => $entityRows->count() * 2,
        ];
    }

    protected function fetchDailyRows(Brand $brand, string $since, string $until): Collection
    {
        $path = sprintf('act_%s/insights', $brand->meta_ad_account_id);
        $query = [
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
            'time_range' => json_encode([
                'since' => $since,
                'until' => $until,
            ], JSON_THROW_ON_ERROR),
        ];

        $data = collect();
        $after = null;

        do {
            if (is_string($after) && $after !== '') {
                $query['after'] = $after;
            } else {
                unset($query['after']);
            }

            $response = $this->metaGraphClient->get($path, $query);
            $data = $data->concat($response['data'] ?? []);
            $after = data_get($response, 'paging.cursors.after');
        } while (is_string($after) && $after !== '');

        return $data
            ->map(fn (array $ad): array => [
                'ad_id' => (string) ($ad['ad_id'] ?? ''),
                'ad_name' => (string) ($ad['ad_name'] ?? ''),
                'campaign_id' => (string) ($ad['campaign_id'] ?? ''),
                'campaign_name' => (string) ($ad['campaign_name'] ?? ''),
                'date' => (string) ($ad['date_start'] ?? ''),
                'spend' => (float) ($ad['spend'] ?? 0),
                'purchases' => $this->countPurchases($ad['actions'] ?? []),
            ])
            ->reject(fn (array $row): bool => $row['ad_id'] === '' || $row['date'] === '')
            ->values();
    }

    protected function fetchAdDetails(array $adIds): array
    {
        if ($adIds === []) {
            return [];
        }

        return collect($adIds)
            ->chunk(50)
            ->reduce(function (array $carry, Collection $chunk): array {
                $response = $this->metaGraphClient->get('', [
                    'ids' => implode(',', $chunk->all()),
                    'fields' => 'creative{id,thumbnail_url}',
                ]);

                return [
                    ...$carry,
                    ...collect($response)
                        ->mapWithKeys(fn (array $ad, string $adId): array => [
                            $adId => [
                                'creative_id' => $this->normalizeMetaId(data_get($ad, 'creative.id')),
                                'thumbnail_url' => data_get($ad, 'creative.thumbnail_url'),
                            ],
                        ])
                        ->all(),
                ];
            }, []);
    }

    protected function entityKey(string $date, string $adId): string
    {
        return $date.'|'.$adId;
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

    protected function countPurchases(array $actions): int
    {
        $purchaseAction = collect($actions)->firstWhere('action_type', 'purchase');

        return (int) ($purchaseAction['value'] ?? 0);
    }
}
