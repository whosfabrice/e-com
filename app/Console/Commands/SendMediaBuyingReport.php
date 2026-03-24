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
            try {
                if (! is_string($brand->slack_channel_id) || $brand->slack_channel_id === '') {
                    $this->warn(sprintf('Skipped %s: missing slack_channel_id.', $brand->name));

                    continue;
                }

                $winnerAds = $metaWinnerAdService->forBrand($brand);

                $slackApiClient->postMessage(
                    $brand->slack_channel_id,
                    $slackReportBuilder->build($brand, $winnerAds),
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
                $this->error(sprintf('Failed for %s: %s', $brand->name, $throwable->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
