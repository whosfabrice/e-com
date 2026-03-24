<?php

namespace Tests\Feature\Feature;

use App\Enums\AdvertisingPlatform;
use App\Enums\CampaignPhase;
use App\Enums\TargetMetric;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Target;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendMediaBuyingReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_the_slack_report_for_a_brand(): void
    {
        config()->set('services.meta.graph_api_access_token', 'meta-token');

        $brand = Brand::factory()->create([
            'meta_ad_account_id' => '1387980248144821',
            'slack_channel_webhook_url' => 'https://hooks.slack.com/services/test',
            'name' => 'NAILD',
            'handle' => 'naild',
        ]);

        Campaign::factory()->for($brand)->create([
            'campaign_id' => '6880704518086',
            'advertising_platform' => AdvertisingPlatform::Meta,
            'phase' => CampaignPhase::Phase2,
            'name' => 'Coredrive | Phase 2 (Creative Testing)',
        ]);

        Campaign::factory()->for($brand)->create([
            'campaign_id' => '52583321034890',
            'advertising_platform' => AdvertisingPlatform::Meta,
            'phase' => CampaignPhase::Phase4,
            'name' => 'Coredrive | Phase 4 (Scaling)',
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
                        'ad_id' => '123456',
                        'ad_name' => 'Winner Ad',
                        'spend' => '72',
                        'actions' => [
                            ['action_type' => 'purchase', 'value' => '3'],
                        ],
                    ],
                ],
            ]),
            'https://graph.facebook.com/v23.0/?*' => Http::response([
                '123456' => [
                    'creative' => [
                        'thumbnail_url' => 'https://example.com/thumb.jpg',
                    ],
                ],
            ]),
            'https://hooks.slack.com/services/test' => Http::response('ok'),
        ]);

        $this->artisan('report:send-media-buying naild')
            ->expectsOutput('Sent report for NAILD with 1 winner ad.')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://hooks.slack.com/services/test') {
                return false;
            }

            $payload = $request->data();
            $blocks = $payload['blocks'] ?? [];

            return ($payload['text'] ?? null) === 'NAILD Media Buyer'
                && collect($blocks)->contains(function (array $block): bool {
                    return ($block['type'] ?? null) === 'actions'
                        && (($block['elements'][0]['action_id'] ?? null) === 'open_scale_modal');
                });
        });
    }
}
