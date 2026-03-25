<?php

namespace App\Services\Slack;

use App\Models\Brand;
use Illuminate\Support\Carbon;
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
                    'text' => "{$brand->name} Daily Report - ".Carbon::now('Europe/Berlin')->toDateString(),
                ],
            ],
            [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => 'Based on Meta ad performance from the last 7 days.',
                    ],
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
                        ? '✓ All winning creative tests have been added to a scaling campaign.'
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

        $dimensions = $this->creativeStrategyInsights($ads);

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
            [
                'type' => 'table',
                'column_settings' => [
                    ['is_wrapped' => true],
                    ['align' => 'right'],
                    ['align' => 'right'],
                    ['align' => 'right'],
                ],
                'rows' => $this->buildCreativeStrategyTableRows($dimensions),
            ],
        ];

        return $blocks;
    }

    public function creativeStrategyInsights(Collection $ads): array
    {
        if ($ads->isEmpty()) {
            return [];
        }

        $dimensions = [
            ['title' => 'Languages', 'label' => 'Language', 'key' => 'language', 'sort' => 'spend_desc'],
            ['title' => 'Consumer Awareness', 'label' => 'Awareness Level', 'key' => 'awareness', 'sort' => 'label_desc'],
            ['title' => 'Media Types', 'label' => 'Type', 'key' => 'media_type', 'sort' => 'spend_desc'],
            ['title' => 'Products', 'label' => 'Product Name', 'key' => 'product', 'sort' => 'spend_desc'],
            ['title' => 'Offers', 'label' => 'Offer Name', 'key' => 'offer', 'sort' => 'spend_desc'],
            ['title' => 'Angles', 'label' => 'Angle Name', 'key' => 'angle', 'sort' => 'spend_desc'],
            ['title' => 'Concepts', 'label' => 'Concept Name', 'key' => 'concept', 'sort' => 'spend_desc'],
        ];

        $parsedAds = $ads->map(fn (array $ad): array => [
            ...$ad,
            ...$this->parseAdNameDimensions($ad['ad_name']),
        ]);

        return collect($dimensions)
            ->map(function (array $dimension) use ($parsedAds): array {
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
                    ->values()
                    ->all();

                return [
                    'title' => $dimension['title'],
                    'label' => $dimension['label'],
                    'rows' => $rows,
                ];
            })
            ->filter(fn (array $dimension): bool => $dimension['rows'] !== [])
            ->values()
            ->all();
    }

    protected function buildCreativeStrategyTableRows(array $dimensions): array
    {
        $rows = [];

        foreach ($dimensions as $dimension) {
            if (count($rows) >= 100) {
                break;
            }

            $rows[] = [
                $this->richTableCell($dimension['label'], true),
                $this->richTableCell('Spend', true),
                $this->richTableCell('Purchases', true),
                $this->richTableCell('CPA', true),
            ];

            foreach ($dimension['rows'] as $row) {
                if (count($rows) >= 100) {
                    break 2;
                }

                $rows[] = [
                    $this->rawTableCell($row['value']),
                    $this->rawTableCell($this->formatEuro($row['spend'])),
                    $this->rawTableCell((string) $row['purchases']),
                    $this->rawTableCell($this->formatEuro($row['cpa'])),
                ];
            }
        }

        return $rows;
    }

    protected function rawTableCell(string $text): array
    {
        return [
            'type' => 'raw_text',
            'text' => $text,
        ];
    }

    protected function richTableCell(string $text, bool $bold = false): array
    {
        $element = [
            'type' => 'text',
            'text' => $text,
        ];

        if ($bold) {
            $element['style'] = ['bold' => true];
        }

        return [
            'type' => 'rich_text',
            'elements' => [
                [
                    'type' => 'rich_text_section',
                    'elements' => [$element],
                ],
            ],
        ];
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
