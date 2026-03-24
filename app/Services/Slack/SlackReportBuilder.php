<?php

namespace App\Services\Slack;

use App\Enums\AdvertisingPlatform;
use App\Enums\CampaignPhase;
use App\Enums\TargetMetric;
use App\Models\Brand;
use Illuminate\Support\Collection;

class SlackReportBuilder
{
    public function build(Brand $brand, Collection $winnerAds): array
    {
        $testingCampaignNames = $brand->campaigns()
            ->where('advertising_platform', AdvertisingPlatform::Meta->value)
            ->where('phase', CampaignPhase::Phase2->value)
            ->pluck('name')
            ->implode(', ');

        $targetCpa = $brand->targets()
            ->where('platform', AdvertisingPlatform::Meta->value)
            ->where('metric', TargetMetric::Cpa->value)
            ->value('value');

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'emoji' => true,
                    'text' => sprintf('%s Media Buying Summary', $brand->name),
                ],
            ],
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'emoji' => true,
                    'text' => 'Creative Testing Winners',
                ],
            ],
                        [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "Identified {$winnerAds->count()} winner creative".($winnerAds->count() === 1 ? '' : 's').' in testing, that have not been added to a scaling campaign yet.',
                    ],
                ],
            ],
        ];

        foreach ($winnerAds as $winnerAd) {
            $campaignOptions = $brand->campaigns()
                ->where('advertising_platform', AdvertisingPlatform::Meta->value)
                ->where('phase', CampaignPhase::Phase4->value)
                ->orderBy('name')
                ->take(100)
                ->get()
                ->map(fn ($campaign): array => [
                    'text' => [
                        'type' => 'plain_text',
                        'emoji' => true,
                        'text' => $this->dropdownCampaignName($campaign->name),
                    ],
                    'value' => $campaign->campaign_id,
                ])
                ->values()
                ->all();

            $blocks[] = ['type' => 'divider'];
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => implode("\n", [
                        sprintf('*<%s|%s>*', $winnerAd['ad_link'], $winnerAd['ad_name']),
                        sprintf('• Spend: %s', $this->formatEuro((float) $winnerAd['spend'])),
                        sprintf('• Purchases: %d', (int) $winnerAd['purchases']),
                        sprintf('• CPA: %s', $this->formatEuro((float) $winnerAd['cpa'])),
                    ]),
                ],
                'accessory' => [
                    'type' => 'image',
                    'image_url' => $winnerAd['thumbnail_url'] ?: 'https://api.slack.com/img/blocks/bkb_template_images/notifications.png',
                    'alt_text' => $winnerAd['ad_name'] ?: 'ad thumbnail',
                ],
            ];
            $blocks[] = [
                'type' => 'actions',
                'block_id' => 'winner_'.$winnerAd['ad_id'],
                'elements' => [
                    [
                        'type' => 'static_select',
                        'action_id' => 'campaign_select',
                        'placeholder' => [
                            'type' => 'plain_text',
                            'text' => 'Choose a campaign',
                        ],
                        'options' => $campaignOptions,
                        'initial_option' => $campaignOptions[0] ?? null,
                    ],
                    [
                        'type' => 'button',
                        'action_id' => 'add_to_campaign',
                        'text' => [
                            'type' => 'plain_text',
                            'emoji' => true,
                            'text' => 'Add',
                        ],
                        'value' => json_encode([
                            'brand_id' => $brand->id,
                            'ad_id' => $winnerAd['ad_id'],
                            'ad_name' => $winnerAd['ad_name'],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ];
        }

        return [
            'text' => sprintf('%s Media Buyer', $brand->name),
            'blocks' => $blocks,
        ];
    }

    protected function formatEuro(float $value): string
    {
        return number_format($value, 2, ',', '.').'€';
    }

    protected function dropdownCampaignName(string $name): string
    {
        return preg_replace('/^Coredrive\s+\|\s+/u', '', $name) ?: $name;
    }

    public function withAdStatus(array $message, string $adId, string $statusText): array
    {
        $blocks = collect($message['blocks'] ?? [])
            ->map(function (array $block) use ($adId, $statusText): array {
                if (($block['block_id'] ?? null) !== 'winner_'.$adId) {
                    return $block;
                }

                return [
                    'type' => 'context',
                    'block_id' => 'winner_'.$adId,
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => $statusText,
                        ],
                    ],
                ];
            })
            ->values()
            ->all();

        return [
            'text' => (string) ($message['text'] ?? 'Media Buying Summary'),
            'blocks' => $blocks,
        ];
    }
}
