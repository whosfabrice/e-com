<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Services\Meta\MetaAdDuplicator;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackReportBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DuplicateMetaAdToCampaign implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $brandId,
        public string $adId,
        public string $campaignId,
        public string $adName,
        public string $campaignName,
        public ?string $channelId = null,
        public ?string $messageTs = null,
    ) {
    }

    public function handle(
        MetaAdDuplicator $metaAdDuplicator,
        SlackApiClient $slackApiClient,
        SlackReportBuilder $slackReportBuilder,
    ): void
    {
        $brand = Brand::query()->findOrFail($this->brandId);

        Log::info('Queued ad duplication started.', [
            'brand_id' => $this->brandId,
            'campaign_id' => $this->campaignId,
            'ad_id' => $this->adId,
        ]);

        $result = $metaAdDuplicator->duplicateToCampaign(
            $brand,
            $this->adId,
            $this->campaignId,
        );

        $createdAdId = is_string($result['id'] ?? null) ? $result['id'] : null;

        $this->updateReportMessage(
            $slackApiClient,
            $slackReportBuilder,
            sprintf(
                '✅ Added to <%s|%s>. %s',
                $this->campaignUrl($brand),
                $this->campaignName,
                $createdAdId !== null
                    ? sprintf('<%s|Open duplicated ad>', $this->adUrl($brand, $createdAdId))
                    : sprintf('<%s|Open source ad>', $this->adUrl($brand, $this->adId)),
            ),
        );

        Log::info('Queued ad duplication finished.', [
            'brand_id' => $this->brandId,
            'campaign_id' => $this->campaignId,
            'ad_id' => $this->adId,
        ]);
    }

    public function failed(Throwable $throwable): void
    {
        $brand = Brand::query()->find($this->brandId);

        if ($brand === null || $this->channelId === null || $this->messageTs === null) {
            return;
        }

        $slackApiClient = app(SlackApiClient::class);
        $slackReportBuilder = app(SlackReportBuilder::class);

        $this->updateReportMessage(
            $slackApiClient,
            $slackReportBuilder,
            sprintf(
                '❌ Failed for <%s|%s>: %s',
                $this->campaignUrl($brand),
                $this->campaignName,
                $throwable->getMessage(),
            ),
        );
    }

    protected function updateReportMessage(
        SlackApiClient $slackApiClient,
        SlackReportBuilder $slackReportBuilder,
        string $statusText,
    ): void
    {
        if ($this->channelId === null || $this->messageTs === null) {
            return;
        }

        $message = $slackApiClient->fetchMessage($this->channelId, $this->messageTs);

        $slackApiClient->updateMessage(
            $this->channelId,
            $this->messageTs,
            $slackReportBuilder->withAdStatus($message, $this->adId, $statusText),
        );
    }

    protected function campaignUrl(Brand $brand): string
    {
        return sprintf(
            'https://www.facebook.com/adsmanager/manage/campaigns?act=%s&selected_campaign_ids=%s',
            $brand->meta_ad_account_id,
            $this->campaignId,
        );
    }

    protected function adDisplayName(): string
    {
        return $this->adName !== '' ? $this->adName : "Ad {$this->adId}";
    }

    protected function adUrl(Brand $brand, string $adId): string
    {
        return sprintf(
            'https://www.facebook.com/adsmanager/manage/ads?act=%s&selected_ad_ids=%s',
            $brand->meta_ad_account_id,
            $adId,
        );
    }
}
