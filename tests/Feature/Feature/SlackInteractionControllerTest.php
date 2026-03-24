<?php

namespace Tests\Feature\Feature;

use App\Enums\AdvertisingPlatform;
use App\Enums\CampaignPhase;
use App\Models\Brand;
use App\Models\Campaign;
use App\Services\Meta\MetaAdDuplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlackInteractionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_opens_a_scale_modal_for_a_valid_slack_action(): void
    {
        config()->set('services.slack.signing_secret', 'signing-secret');
        config()->set('services.slack.bot_token', 'slack-bot-token');

        $brand = Brand::factory()->create();

        Campaign::factory()->for($brand)->create([
            'advertising_platform' => AdvertisingPlatform::Meta,
            'phase' => CampaignPhase::Phase4,
            'campaign_id' => '52583321034890',
            'name' => 'Coredrive | Phase 4 (Scaling)',
        ]);

        Http::fake([
            'https://slack.com/api/views.open' => Http::response([
                'ok' => true,
            ]),
        ]);

        $payload = [
            'type' => 'block_actions',
            'trigger_id' => 'trigger-123',
            'actions' => [
                [
                    'action_id' => 'open_scale_modal',
                    'value' => json_encode([
                        'brand_id' => $brand->id,
                        'ad_id' => '123456',
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ];

        $response = $this->postSlackInteraction($payload);

        $response->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://slack.com/api/views.open';
        });
    }

    public function test_it_duplicates_the_ad_on_modal_submission(): void
    {
        config()->set('services.slack.signing_secret', 'signing-secret');

        $brand = Brand::factory()->create([
            'meta_ad_account_id' => '1387980248144821',
        ]);

        $this->mock(MetaAdDuplicator::class, function ($mock) use ($brand): void {
            $mock->shouldReceive('duplicateToCampaign')
                ->once()
                ->with($brand, '123456', '52583321034890')
                ->andReturn(['id' => 'new-ad-id']);
        });

        $payload = [
            'type' => 'view_submission',
            'view' => [
                'callback_id' => 'submit_scale_campaign',
                'private_metadata' => json_encode([
                    'brand_id' => $brand->id,
                    'ad_id' => '123456',
                ], JSON_THROW_ON_ERROR),
                'state' => [
                    'values' => [
                        'scale_campaign' => [
                            'campaign_id' => [
                                'selected_option' => [
                                    'value' => '52583321034890',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postSlackInteraction($payload);

        $response->assertOk()->assertJson([
            'response_action' => 'clear',
        ]);
    }

    protected function postSlackInteraction(array $payload)
    {
        $body = http_build_query([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $timestamp = (string) now()->timestamp;
        $signature = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, 'signing-secret');

        return $this->call(
            'POST',
            '/slack/interactions',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
                'HTTP_X_SLACK_SIGNATURE' => $signature,
            ],
            $body,
        );
    }
}
