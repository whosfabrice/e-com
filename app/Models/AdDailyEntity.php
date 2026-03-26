<?php

namespace App\Models;

use App\Enums\AdvertisingPlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdDailyEntity extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'platform',
        'date',
        'ad_id',
        'ad_name',
        'campaign_id',
        'campaign_name',
        'creative_id',
        'thumbnail_url',
    ];

    protected function casts(): array
    {
        return [
            'platform' => AdvertisingPlatform::class,
            'date' => 'date',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(AdDailyMetric::class);
    }
}
