<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Jobs\DuplicateMetaAdToCampaign;
use App\Models\Brand;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackInteractionPayload;
use App\Services\Slack\SlackModalBuilder;
use App\Services\Slack\SlackSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InteractionController extends Controller
{
    public function __invoke(
        Request $request,
        SlackApiClient $slackApiClient,
        SlackInteractionPayload $slackInteractionPayload,
        SlackModalBuilder $slackModalBuilder,
        SlackSignatureVerifier $slackSignatureVerifier,
    ): JsonResponse {
        if (! $slackSignatureVerifier->isValid($request)) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $slackInteractionPayload->fromRequest($request);

            if ($slackInteractionPayload->isOpenScaleModalAction($payload)) {
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
                try {
                    $brand = Brand::query()->findOrFail($slackInteractionPayload->brandId($payload));
                    $adId = $slackInteractionPayload->adId($payload);
                    $campaignId = $slackInteractionPayload->selectedCampaignId($payload);

                    if ($campaignId === null) {
                        return response()->json([
                            'response_action' => 'errors',
                            'errors' => [
                                'scale_campaign' => 'Please select a scaling campaign.',
                            ],
                        ]);
                    }

                    DuplicateMetaAdToCampaign::dispatch(
                        $brand->id,
                        $adId,
                        $campaignId,
                    );

                    return response()->json([
                        'response_action' => 'clear',
                    ]);
                } catch (\Throwable $throwable) {
                    return response()->json([
                        'response_action' => 'errors',
                        'errors' => [
                            'scale_campaign' => $throwable->getMessage(),
                        ],
                    ]);
                }
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $throwable) {
            throw $throwable;
        }
    }
}
