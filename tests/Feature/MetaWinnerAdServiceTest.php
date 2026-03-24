<?php

namespace Tests\Feature;

use App\Enums\AdvertisingPlatform;
use App\Enums\CampaignPhase;
use App\Enums\TargetMetric;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Target;
use App\Services\Meta\MetaWinnerAdService;
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaWinnerAdServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fetches_winner_ads_for_a_brand(): void
    {
        config()->set('services.meta.graph_api_access_token', 'test-token');

        $brand = Brand::factory()->create([
            'meta_ad_account_id' => '1387980248144821',
        ]);

        Campaign::factory()->for($brand)->create([
            'campaign_id' => '6880704518086',
            'advertising_platform' => AdvertisingPlatform::Meta,
            'phase' => CampaignPhase::Phase2,
        ]);

        Campaign::factory()->for($brand)->create([
            'campaign_id' => '52583321034890',
            'advertising_platform' => AdvertisingPlatform::Meta,
            'phase' => CampaignPhase::Phase4,
        ]);

        Target::factory()->for($brand)->create([
            'platform' => AdvertisingPlatform::Meta,
            'metric' => TargetMetric::Cpa,
            'value' => 25,
        ]);

        Target::factory()->for($brand)->create([
            'platform' => AdvertisingPlatform::Meta,
            'metric' => TargetMetric::Purchases,
            'value' => 3,
        ]);

        Http::fake([
            'https://graph.facebook.com/v23.0/act_1387980248144821/insights*' => Http::response([
                'data' => [
                    [
                        'campaign_id' => '6880704518086',
                        'campaign_name' => 'Coredrive | Phase 2 (Creative Testing)',
                        'ad_id' => '111111',
                        'ad_name' => 'Too Expensive Ad',
                        'spend' => '90',
                        'actions' => [
                            ['action_type' => 'purchase', 'value' => '3'],
                        ],
                    ],
                    [
                        'campaign_id' => '6880704518086',
                        'campaign_name' => 'Coredrive | Phase 2 (Creative Testing)',
                        'ad_id' => '123456',
                        'ad_name' => 'Lower Spend Winner Ad',
                        'spend' => '72',
                        'actions' => [
                            ['action_type' => 'purchase', 'value' => '3'],
                        ],
                    ],
                    [
                        'campaign_id' => '6880704518086',
                        'campaign_name' => 'Coredrive | Phase 2 (Creative Testing)',
                        'ad_id' => '222222',
                        'ad_name' => 'Higher Spend Winner Ad',
                        'spend' => '75',
                        'actions' => [
                            ['action_type' => 'purchase', 'value' => '3'],
                        ],
                    ],
                    [
                        'campaign_id' => '6880704518086',
                        'campaign_name' => 'Coredrive | Phase 2 (Creative Testing)',
                        'ad_id' => '999999',
                        'ad_name' => 'Not Enough Purchases',
                        'spend' => '120',
                        'actions' => [
                            ['action_type' => 'purchase', 'value' => '1'],
                        ],
                    ],
                ],
            ]),
            'https://graph.facebook.com/v23.0/*' => Http::response([
                '123456' => [
                    'creative' => [
                        'thumbnail_url' => 'https://example.com/thumb.jpg',
                    ],
                ],
                '222222' => [
                    'creative' => [
                        'thumbnail_url' => 'https://example.com/second-thumb.jpg',
                    ],
                ],
            ]),
        ]);

        $winnerAds = app(MetaWinnerAdService::class)->forBrand($brand);

        $this->assertCount(2, $winnerAds);
        $this->assertSame('222222', $winnerAds[0]['ad_id']);
        $this->assertSame('Higher Spend Winner Ad', $winnerAds[0]['ad_name']);
        $this->assertSame(25.0, $winnerAds[0]['cpa']);
        $this->assertSame('https://example.com/second-thumb.jpg', $winnerAds[0]['thumbnail_url']);
        $this->assertSame('123456', $winnerAds[1]['ad_id']);
        $this->assertSame('Lower Spend Winner Ad', $winnerAds[1]['ad_name']);
        $this->assertSame(24.0, $winnerAds[1]['cpa']);
        $this->assertSame('https://example.com/thumb.jpg', $winnerAds[1]['thumbnail_url']);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/insights')) {
                return true;
            }

            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $filtering = json_decode($query['filtering'] ?? '[]', true);

            return ($query['level'] ?? null) === 'ad'
                && ($query['date_preset'] ?? null) === 'last_7d'
                && ($query['limit'] ?? null) === '100'
                && ($filtering[0]['value'] ?? null) === ['6880704518086']
                && ($filtering[1]['value'] ?? null) === 75;
        });
    }

    public function test_it_returns_an_empty_collection_when_testing_campaigns_are_missing(): void
    {
        config()->set('services.meta.graph_api_access_token', 'test-token');

        $brand = Brand::factory()->create();

        Target::factory()->for($brand)->create([
            'platform' => AdvertisingPlatform::Meta,
            'metric' => TargetMetric::Cpa,
            'value' => 25,
        ]);

        Target::factory()->for($brand)->create([
            'platform' => AdvertisingPlatform::Meta,
            'metric' => TargetMetric::Purchases,
            'value' => 3,
        ]);

        Http::fake();

        $winnerAds = app(MetaWinnerAdService::class)->forBrand($brand);

        $this->assertTrue($winnerAds->isEmpty());
        Http::assertNothingSent();
    }
}
