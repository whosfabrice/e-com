<?php

namespace App\Services\Slack;

use App\Models\Brand;
use Illuminate\Support\Collection;

class SlackReportBuilder
{
    public function build(
        Brand $brand,
        Collection $winnerAds,
        ?Collection $fetchedAds = null,
        ?Collection $scalingCampaigns = null,
    ): array
    {
        $fetchedAds ??= collect();
        $scalingCampaigns ??= collect();

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'emoji' => true,
                    'text' => "{$brand->name} Daily Report",
                ],
            ],
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'emoji' => true,
                    'text' => 'Media Buying',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $winnerAds->isEmpty()
                        ? "No winning creatives in testing that haven't been added to a scaling campaign."
                        : 'Identified '.$winnerAds->count().' creative testing winner'.($winnerAds->count() === 1 ? '' : 's').' that should be added to a scaling campaign.',
                ],
            ],
        ];

        $campaignOptions = $scalingCampaigns
            ->take(100)
            ->map(fn (array $campaign): array => [
                'text' => [
                    'type' => 'plain_text',
                    'emoji' => true,
                    'text' => $this->truncateSlackOptionText(
                        $this->dropdownCampaignName($campaign['campaign_name']),
                    ),
                ],
                'value' => $campaign['campaign_id'],
            ])
            ->values()
            ->all();

        foreach ($winnerAds as $winnerAd) {
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
            $blocks[] = $campaignOptions === []
                ? [
                    'type' => 'context',
                    'block_id' => 'winner_'.$winnerAd['ad_id'],
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => 'No scaling campaigns found.',
                        ],
                    ],
                ]
                : [
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

        $blocks = [
            ...$blocks,
            ...$this->creativeStrategyInsightBlocks($fetchedAds),
        ];

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

    protected function truncateSlackOptionText(string $text, int $limit = 75): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 3).'...';
    }

    protected function creativeStrategyInsightBlocks(Collection $ads): array
    {
        if ($ads->isEmpty()) {
            return [];
        }

        $dimensions = [
            ['title' => 'Languages', 'label' => 'Language', 'key' => 'language', 'sort' => 'spend_desc'],
            ['title' => 'Consumer Awareness', 'label' => 'Consumer Awareness', 'key' => 'awareness', 'sort' => 'label_desc'],
            ['title' => 'Media Types', 'label' => 'Media Type', 'key' => 'media_type', 'sort' => 'spend_desc'],
            ['title' => 'Products', 'label' => 'Product', 'key' => 'product', 'sort' => 'spend_desc'],
            ['title' => 'Offers', 'label' => 'Offer', 'key' => 'offer', 'sort' => 'spend_desc'],
            ['title' => 'Angles', 'label' => 'Angle', 'key' => 'angle', 'sort' => 'spend_desc'],
            ['title' => 'Concepts', 'label' => 'Concept', 'key' => 'concept', 'sort' => 'spend_desc'],
        ];

        $parsedAds = $ads->map(fn (array $ad): array => [
            ...$ad,
            ...$this->parseAdNameDimensions($ad['ad_name']),
        ]);

        $blocks = [
            ['type' => 'divider'],
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'emoji' => true,
                    'text' => 'Creative Strategy',
                ],
            ],
        ];

        foreach ($dimensions as $dimension) {
            $rows = $parsedAds
                ->groupBy($dimension['key'])
                ->map(function (Collection $group, string $value): array {
                    $spend = round($group->sum('spend'), 2);
                    $purchases = (int) $group->sum('purchases');
                    $cpa = $purchases > 0 ? round($spend / $purchases, 2) : 0.0;

                    return [
                        'value' => $value,
                        'spend' => $spend,
                        'purchases' => $purchases,
                        'cpa' => $cpa,
                    ];
                })
                ->pipe(fn (Collection $rows): Collection => $this->sortInsightRows($rows, $dimension['sort']))
                ->values();

            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*'.$dimension['title']."*\n```".$this->buildInsightTable($rows, $dimension['label'])."```",
                ],
            ];
        }

        return $blocks;
    }

    protected function parseAdNameDimensions(string $adName): array
    {
        $parts = explode(' - ', $adName, 8);

        if (! isset($parts[0]) || ! preg_match('/^[A-Z]{2}$/', trim($parts[0]))) {
            array_unshift($parts, 'DE');
        }

        [$language, $awareness, $mediaType, $product, $offer, $angle, $concept] = array_pad($parts, 7, 'Unknown');

        return [
            'language' => $language ?: 'Unknown',
            'awareness' => $awareness ?: 'Unknown',
            'media_type' => $mediaType ?: 'Unknown',
            'product' => $product ?: 'Unknown',
            'offer' => $offer ?: 'Unknown',
            'angle' => $angle ?: 'Unknown',
            'concept' => $concept ?: 'Unknown',
        ];
    }

    protected function sortInsightRows(Collection $rows, string $sort): Collection
    {
        return match ($sort) {
            'label_asc' => $rows->sortBy('value', SORT_NATURAL | SORT_FLAG_CASE),
            'label_desc' => $rows->sortByDesc('value', SORT_NATURAL | SORT_FLAG_CASE),
            default => $rows->sortByDesc('spend'),
        };
    }

    protected function buildInsightTable(Collection $rows, string $valueHeader): string
    {
        if ($rows->isEmpty()) {
            return $valueHeader.'   Spend   Purchases   CPA';
        }

        $normalizedRows = $rows->map(fn (array $row): array => [
            'value' => $this->truncateTableCell($row['value'], 28),
            'spend' => $this->formatEuro($row['spend']),
            'purchases' => (string) $row['purchases'],
            'cpa' => $this->formatEuro($row['cpa']),
        ])->values();

        $valueWidth = max(
            mb_strlen($valueHeader),
            $normalizedRows->max(fn (array $row): int => mb_strlen($row['value'])),
        );
        $spendWidth = max(
            mb_strlen('Spend'),
            $normalizedRows->max(fn (array $row): int => mb_strlen($row['spend'])),
        );
        $purchaseWidth = max(
            mb_strlen('Purchases'),
            $normalizedRows->max(fn (array $row): int => mb_strlen($row['purchases'])),
        );
        $cpaWidth = max(
            mb_strlen('CPA'),
            $normalizedRows->max(fn (array $row): int => mb_strlen($row['cpa'])),
        );

        $header = $this->padRight($valueHeader, $valueWidth).'  '
            .$this->padRight('Spend', $spendWidth).'  '
            .$this->padRight('Purchases', $purchaseWidth).'  '
            .$this->padRight('CPA', $cpaWidth);

        $lines = $normalizedRows->map(
            fn (array $row): string => $this->padRight($row['value'], $valueWidth).'  '
                .$this->padRight($row['spend'], $spendWidth).'  '
                .$this->padRight($row['purchases'], $purchaseWidth).'  '
                .$this->padRight($row['cpa'], $cpaWidth)
        );

        return $header."\n".$lines->implode("\n");
    }

    protected function padRight(string $value, int $width): string
    {
        return $value.str_repeat(' ', max(0, $width - mb_strlen($value)));
    }

    protected function truncateTableCell(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 3).'...';
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
