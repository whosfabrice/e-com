<?php

namespace App\Models;

use App\Enums\AdvertisingPlatform;
use App\Enums\TargetMetric;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Target extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'platform',
        'metric',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'platform' => AdvertisingPlatform::class,
            'metric' => TargetMetric::class,
            'value' => 'float',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
