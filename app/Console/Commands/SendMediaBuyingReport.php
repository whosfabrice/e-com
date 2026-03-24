<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Meta\MetaWinnerAdService;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackReportBuilder;
use Illuminate\Console\Command;

class SendMediaBuyingReport extends Command
{
    protected $signature = 'report:send-media-buying {brand? : Optional brand handle}';

    protected $description = 'Fetch winner ads from Meta and send the Slack report.';

    public function handle(
        MetaWinnerAdService $metaWinnerAdService,
        SlackReportBuilder $slackReportBuilder,
        SlackApiClient $slackApiClient,
    ): int
    {
        $brandHandle = $this->argument('brand');

        $brands = $brandHandle
            ? Brand::query()->where('handle', $brandHandle)->get()
            : Brand::query()->get();

        foreach ($brands as $brand) {
            $winnerAds = $metaWinnerAdService->forBrand($brand);

            if ($winnerAds->isEmpty()) {
                $this->info(sprintf('No winner ads found for %s.', $brand->name));
                continue;
            }

            $slackApiClient->postMessage(
                $brand->slack_channel_id,
                $slackReportBuilder->build($brand, $winnerAds),
            );

            $this->info(sprintf(
                'Sent report for %s with %d winner ad%s.',
                $brand->name,
                $winnerAds->count(),
                $winnerAds->count() === 1 ? '' : 's',
            ));
        }

        return self::SUCCESS;
    }
}
