<?php

namespace App\Services\Slack;

use App\Enums\AdvertisingPlatform;
use App\Enums\CampaignPhase;
use App\Models\Brand;
use Illuminate\Support\Str;

class SlackModalBuilder
{
    public function buildScaleCampaignModal(Brand $brand, string $adId): array
    {
        return [
            'type' => 'modal',
            'callback_id' => 'submit_scale_campaign',
            'private_metadata' => json_encode([
                'brand_id' => $brand->id,
                'ad_id' => $adId,
            ], JSON_THROW_ON_ERROR),
            'title' => [
                'type' => 'plain_text',
                'text' => 'Scale Ad',
            ],
            'submit' => [
                'type' => 'plain_text',
                'text' => 'Scale',
            ],
            'close' => [
                'type' => 'plain_text',
                'text' => 'Cancel',
            ],
            'blocks' => [
                [
                    'type' => 'input',
                    'block_id' => 'scale_campaign',
                    'label' => [
                        'type' => 'plain_text',
                        'text' => 'Select scaling campaign',
                    ],
                    'element' => [
                        'type' => 'static_select',
                        'action_id' => 'campaign_id',
                        'placeholder' => [
                            'type' => 'plain_text',
                            'text' => 'Choose a campaign',
                        ],
                        'options' => $brand->campaigns()
                            ->where('advertising_platform', AdvertisingPlatform::Meta->value)
                            ->where('phase', CampaignPhase::Phase4->value)
                            ->orderBy('name')
                            ->get()
                            ->take(100)
                            ->map(fn ($campaign): array => [
                                'text' => [
                                    'type' => 'plain_text',
                                    'emoji' => true,
                                    'text' => $this->slackPlainText($campaign->name, 75),
                                ],
                                'value' => $campaign->campaign_id,
                            ])
                            ->values()
                            ->all(),
                    ],
                ],
            ],
        ];
    }

    protected function slackPlainText(string $text, int $limit): string
    {
        return Str::limit($text, $limit, '...');
    }
}
