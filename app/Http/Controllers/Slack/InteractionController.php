<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Jobs\DuplicateMetaAdToCampaign;
use App\Models\Brand;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackInteractionPayload;
use App\Services\Slack\SlackReportBuilder;
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
        SlackReportBuilder $slackReportBuilder,
        SlackSignatureVerifier $slackSignatureVerifier,
    ): JsonResponse {
        if (! $slackSignatureVerifier->isValid($request)) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $slackInteractionPayload->fromRequest($request);

            if ($slackInteractionPayload->isCampaignSelectAction($payload)) {
                return response()->json(['ok' => true]);
            }

            if ($slackInteractionPayload->isAddToCampaignAction($payload)) {
                try {
                    $brand = Brand::query()->findOrFail($slackInteractionPayload->brandId($payload));
                    $adId = $slackInteractionPayload->adId($payload);
                    $adName = $slackInteractionPayload->adName($payload);
                    $campaignId = $slackInteractionPayload->selectedCampaignId($payload);
                    $campaignName = $slackInteractionPayload->selectedCampaignName($payload);
                    $channelId = $slackInteractionPayload->channelId($payload);
                    $messageTs = $slackInteractionPayload->threadTs($payload);

                    if ($campaignId === null || $campaignName === null) {
                        return response()->json([
                            'response_action' => 'errors',
                            'errors' => [
                                'scale_campaign' => 'Please select a scaling campaign.',
                            ],
                        ]);
                    }

                    if ($channelId !== null && $messageTs !== null) {
                        $message = $slackApiClient->fetchMessage($channelId, $messageTs);

                        $slackApiClient->updateMessage(
                            $channelId,
                            $messageTs,
                            $slackReportBuilder->withAdStatus(
                                $message,
                                $adId,
                                sprintf(
                                    ':hourglass_flowing_sand: Duplicating into %s',
                                    $campaignName,
                                ),
                            ),
                        );
                    }

                    DuplicateMetaAdToCampaign::dispatch(
                        $brand->id,
                        $adId,
                        $campaignId,
                        $adName,
                        $campaignName,
                        $channelId,
                        $messageTs,
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
