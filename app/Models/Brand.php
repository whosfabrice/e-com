<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'handle',
        'meta_ad_account_id',
        'slack_channel_id',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(Target::class);
    }

    public function getRouteKeyName(): string
    {
        return 'handle';
    }
}
