<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Meta\MetaAdDuplicator;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackInteractionPayload;
use App\Services\Slack\SlackModalBuilder;
use App\Services\Slack\SlackSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class InteractionController extends Controller
{
    public function __invoke(
        Request $request,
        SlackApiClient $slackApiClient,
        SlackInteractionPayload $slackInteractionPayload,
        SlackModalBuilder $slackModalBuilder,
        SlackSignatureVerifier $slackSignatureVerifier,
        MetaAdDuplicator $metaAdDuplicator,
    ): JsonResponse {
        Log::info('Slack interaction received.', [
            'headers' => $request->headers->all(),
            'raw_body' => $request->getContent(),
        ]);

        if (! $slackSignatureVerifier->isValid($request)) {
            Log::warning('Slack interaction rejected due to invalid signature.');

            abort(Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $slackInteractionPayload->fromRequest($request);

            Log::info('Slack interaction payload parsed.', [
                'payload' => $payload,
            ]);

            if ($slackInteractionPayload->isOpenScaleModalAction($payload)) {
                Log::info('Opening scale modal for Slack interaction.');

                $brand = Brand::query()->findOrFail($slackInteractionPayload->brandId($payload));

                $slackApiClient->openView(
                    $slackInteractionPayload->triggerId($payload),
                    $slackModalBuilder->buildScaleCampaignModal(
                        $brand,
                        $slackInteractionPayload->adId($payload),
                    ),
                );

                return response()->json(['ok' => true]);
            }

            if ($slackInteractionPayload->isScaleModalSubmission($payload)) {
                Log::info('Handling scale modal submission.');

                try {
                    $brand = Brand::query()->findOrFail($slackInteractionPayload->brandId($payload));
                    $campaignId = $slackInteractionPayload->selectedCampaignId($payload);

                    if ($campaignId === null) {
                        Log::warning('Scale modal submission missing campaign selection.');

                        return response()->json([
                            'response_action' => 'errors',
                            'errors' => [
                                'scale_campaign' => 'Please select a scaling campaign.',
                            ],
                        ]);
                    }

                    $metaAdDuplicator->duplicateToCampaign(
                        $brand,
                        $slackInteractionPayload->adId($payload),
                        $campaignId,
                    );

                    Log::info('Ad duplication completed successfully.', [
                        'brand_id' => $brand->id,
                        'campaign_id' => $campaignId,
                        'ad_id' => $slackInteractionPayload->adId($payload),
                    ]);

                    return response()->json([
                        'response_action' => 'clear',
                    ]);
                } catch (\Throwable $throwable) {
                    Log::error('Scale modal submission failed.', [
                        'message' => $throwable->getMessage(),
                        'trace' => $throwable->getTraceAsString(),
                    ]);

                    return response()->json([
                        'response_action' => 'errors',
                        'errors' => [
                            'scale_campaign' => $throwable->getMessage(),
                        ],
                    ]);
                }
            }

            Log::info('Slack interaction did not match a known handler.');

            return response()->json(['ok' => true]);
        } catch (\Throwable $throwable) {
            Log::error('Slack interaction crashed before completion.', [
                'message' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            throw $throwable;
        }
    }
}
