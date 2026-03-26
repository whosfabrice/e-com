<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdDailyMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'ad_daily_entity_id',
        'source',
        'metric',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(AdDailyEntity::class, 'ad_daily_entity_id');
    }
}
