<?php

namespace App\Models;

use App\Enums\AdvertisingPlatform;
use App\Enums\CampaignPhase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'campaign_id',
        'brand_id',
        'advertising_platform',
        'phase',
    ];

    protected function casts(): array
    {
        return [
            'advertising_platform' => AdvertisingPlatform::class,
            'phase' => CampaignPhase::class,
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function metaAdsManagerUrl(): string
    {
        return sprintf(
            'https://www.facebook.com/adsmanager/manage/campaigns?act=%s&selected_campaign_ids=%s',
            $this->brand->meta_ad_account_id,
            $this->campaign_id,
        );
    }
}
