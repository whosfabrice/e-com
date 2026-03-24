<?php

namespace App\Services\Meta;

use App\Models\Brand;
use RuntimeException;

class MetaAdDuplicator
{
    public function __construct(protected MetaGraphClient $metaGraphClient)
    {
    }

    public function duplicateToCampaign(Brand $brand, string $adId, string $campaignId): array
    {
        $targetAdSet = $this->getTargetAdSet($brand, $campaignId);
        $sourceAd = $this->metaGraphClient->get($adId, [
            'fields' => 'name,creative{id}',
        ]);

        $creativeId = data_get($sourceAd, 'creative.id');

        if (! is_string($creativeId) || $creativeId === '') {
            throw new RuntimeException(sprintf('Source ad %s has no reusable creative.', $adId));
        }

        return $this->metaGraphClient->post(
            sprintf('act_%s/ads', $brand->meta_ad_account_id),
            [
                'name' => sprintf('%s | Scaled', $sourceAd['name'] ?? "Ad {$adId}"),
                'adset_id' => $targetAdSet['id'],
                'creative' => json_encode(['creative_id' => $creativeId], JSON_THROW_ON_ERROR),
                'status' => 'PAUSED',
            ],
        );
    }

    protected function getTargetAdSet(Brand $brand, string $campaignId): array
    {
        $response = $this->metaGraphClient->get(
            sprintf('act_%s/adsets', $brand->meta_ad_account_id),
            [
                'fields' => 'id,name,effective_status',
                'filtering' => json_encode([
                    [
                        'field' => 'campaign.id',
                        'operator' => 'EQUAL',
                        'value' => $campaignId,
                    ],
                    [
                        'field' => 'effective_status',
                        'operator' => 'IN',
                        'value' => ['ACTIVE'],
                    ],
                ], JSON_THROW_ON_ERROR),
                'limit' => 1,
            ],
        );

        $adSet = $response['data'][0] ?? null;

        if (! is_array($adSet) || ! isset($adSet['id'])) {
            throw new RuntimeException(sprintf('No active ad set found in campaign %s.', $campaignId));
        }

        return $adSet;
    }
}
