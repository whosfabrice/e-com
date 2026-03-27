<?php

namespace App\Services\Meta;

use App\Models\Brand;
use App\Models\MetaPhase4Creative;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MetaPhase4CreativeSyncService
{
    public function __construct(
        protected MetaGraphClient $metaGraphClient,
    )
    {
    }

    public function sync(Brand $brand): array
    {
        $campaigns = $this->fetchPhase4Campaigns($brand);
        $ads = $this->fetchPhase4Ads($brand, $campaigns);
        $now = now();

        $rows = $ads
            ->filter(fn (array $ad): bool => $ad['campaign_id'] !== '' && $ad['creative_id'] !== '')
            ->map(fn (array $ad): array => [
                'brand_id' => $brand->id,
                'campaign_id' => $ad['campaign_id'],
                'campaign_name' => $ad['campaign_name'],
                'ad_id' => $ad['ad_id'],
                'ad_name' => $ad['ad_name'],
                'creative_id' => $ad['creative_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values();

        DB::transaction(function () use ($brand, $rows): void {
            MetaPhase4Creative::query()
                ->where('brand_id', $brand->id)
                ->delete();

            if ($rows->isNotEmpty()) {
                MetaPhase4Creative::query()->insert($rows->all());
            }
        });

        return [
            'campaign_count' => $campaigns->count(),
            'creative_count' => $rows->count(),
        ];
    }

    protected function fetchPhase4Campaigns(Brand $brand): Collection
    {
        $path = sprintf('act_%s/campaigns', $brand->meta_ad_account_id);
        $query = [
            'fields' => 'id,name,effective_status',
            'limit' => 500,
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
            ->filter(fn (array $campaign): bool => str_starts_with((string) ($campaign['name'] ?? ''), 'Coredrive | Phase 4'))
            ->map(fn (array $campaign): array => [
                'campaign_id' => (string) ($campaign['id'] ?? ''),
                'campaign_name' => (string) ($campaign['name'] ?? ''),
            ])
            ->filter(fn (array $campaign): bool => $campaign['campaign_id'] !== '' && $campaign['campaign_name'] !== '')
            ->sortBy('campaign_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    protected function fetchPhase4Ads(Brand $brand, Collection $campaigns): Collection
    {
        if ($campaigns->isEmpty()) {
            return collect();
        }

        $campaignNamesById = $campaigns
            ->mapWithKeys(fn (array $campaign): array => [
                $campaign['campaign_id'] => $campaign['campaign_name'],
            ])
            ->all();

        return $campaigns
            ->pluck('campaign_id')
            ->chunk(50)
            ->flatMap(function (Collection $campaignIdChunk) use ($brand, $campaignNamesById): Collection {
                $path = sprintf('act_%s/ads', $brand->meta_ad_account_id);
                $query = [
                    'fields' => 'id,name,campaign_id,creative{id}',
                    'filtering' => json_encode([
                        [
                            'field' => 'campaign.id',
                            'operator' => 'IN',
                            'value' => $campaignIdChunk->values()->all(),
                        ],
                    ], JSON_THROW_ON_ERROR),
                    'limit' => 500,
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
                        'ad_id' => (string) ($ad['id'] ?? ''),
                        'ad_name' => (string) ($ad['name'] ?? ''),
                        'campaign_id' => (string) ($ad['campaign_id'] ?? ''),
                        'campaign_name' => $campaignNamesById[(string) ($ad['campaign_id'] ?? '')] ?? '',
                        'creative_id' => $this->normalizeMetaId(data_get($ad, 'creative.id')),
                    ])
                    ->filter(fn (array $ad): bool => $ad['ad_id'] !== '' && $ad['campaign_id'] !== '');
            })
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
}
