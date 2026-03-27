<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\StoredAdReportService;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendMediaBuyingReport extends Command
{
    protected $signature = 'report:send-media-buying {brand? : Optional brand handle}';

    protected $description = 'Send the Slack media buying report from stored DB-backed report data.';

    public function handle(
        StoredAdReportService $storedAdReportService,
        SlackReportBuilder $slackReportBuilder,
        SlackApiClient $slackApiClient,
    ): int
    {
        $brandHandle = $this->argument('brand');

        $brands = $brandHandle
            ? Brand::query()->where('handle', $brandHandle)->get()
            : Brand::query()->get();

        foreach ($brands as $brand) {
            try {
                if (! is_string($brand->slack_channel_id) || $brand->slack_channel_id === '') {
                    $this->warn(sprintf('Skipped %s: missing slack_channel_id.', $brand->name));

                    continue;
                }

                $reportData = $storedAdReportService->reportDataForBrand($brand, 7);
                $winnerAds = collect($reportData['winner_ads'] ?? []);
                $fetchedAds = collect($reportData['fetched_ads'] ?? []);
                $scalingCampaigns = collect($reportData['scaling_campaigns'] ?? []);

                $slackApiClient->postMessage(
                    $brand->slack_channel_id,
                    $slackReportBuilder->build($brand, $winnerAds, $fetchedAds, $scalingCampaigns),
                );

                if ($winnerAds->isEmpty()) {
                    $this->info(sprintf('Sent empty-state report for %s.', $brand->name));
                    continue;
                }

                $this->info(sprintf(
                    'Sent report for %s with %d winner ad%s.',
                    $brand->name,
                    $winnerAds->count(),
                    $winnerAds->count() === 1 ? '' : 's',
                ));
            } catch (\Throwable $throwable) {
                Log::error('Scheduled media buying report failed.', [
                    'brand_id' => $brand->id,
                    'brand_name' => $brand->name,
                    'brand_handle' => $brand->handle,
                    'message' => $throwable->getMessage(),
                ]);
                $this->error(sprintf('Failed for %s: %s', $brand->name, $throwable->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
