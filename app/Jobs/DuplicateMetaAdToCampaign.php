<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Services\Meta\MetaAdDuplicator;
use App\Services\Slack\SlackApiClient;
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
        public ?string $threadTs = null,
        public ?string $commentTs = null,
    ) {
    }

    public function handle(
        MetaAdDuplicator $metaAdDuplicator,
        SlackApiClient $slackApiClient,
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

        $this->updateThreadReply(
            $slackApiClient,
            sprintf(
                'Added <%s|%s> to <%s|%s>.%s',
                $this->adUrl($brand, $createdAdId ?? $this->adId),
                $this->adDisplayName(),
                $this->campaignUrl($brand),
                $this->campaignName,
                $createdAdId !== null
                    ? sprintf(' <https://www.facebook.com/adsmanager/manage/ads?act=%s&selected_ad_ids=%s|Open duplicated ad>.', $brand->meta_ad_account_id, $createdAdId)
                    : '',
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

        if ($brand === null || $this->channelId === null || $this->commentTs === null) {
            return;
        }

        app(SlackApiClient::class)->updateMessage($this->channelId, $this->commentTs, [
            'text' => sprintf(
                'Failed to add <%s|%s> to <%s|%s>: %s',
                $this->adUrl($brand, $this->adId),
                $this->adDisplayName(),
                $this->campaignUrl($brand),
                $this->campaignName,
                $throwable->getMessage(),
            ),
        ]);
    }

    protected function updateThreadReply(SlackApiClient $slackApiClient, string $text): void
    {
        if ($this->channelId === null || $this->commentTs === null) {
            return;
        }

        $slackApiClient->updateMessage($this->channelId, $this->commentTs, [
            'text' => $text,
        ]);
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
