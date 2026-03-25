<?php

namespace App\Services;

use App\Models\Brand;
use App\Services\Meta\MetaWinnerAdService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class BrandReportCache
{
    public function keyForBrand(Brand $brand): string
    {
        return sprintf('brands.%s.winner_ads', $brand->id);
    }

    public function ttl(): Carbon
    {
        return now()->addHour();
    }

    public function get(Brand $brand, bool $refresh = false): array
    {
        $cacheKey = $this->keyForBrand($brand);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $payload = Cache::remember(
            $cacheKey,
            $this->ttl(),
            fn (): array => $this->buildPayload($brand),
        );

        if (! array_key_exists('daily_totals', $payload)) {
            Cache::forget($cacheKey);

            $payload = Cache::remember(
                $cacheKey,
                $this->ttl(),
                fn (): array => $this->buildPayload($brand),
            );
        }

        return $payload;
    }

    public function refresh(Brand $brand): array
    {
        return $this->get($brand, true);
    }

    public function forget(Brand $brand): void
    {
        Cache::forget($this->keyForBrand($brand));
    }

    protected function buildPayload(Brand $brand): array
    {
        $cacheTtl = $this->ttl();
        $reportData = app(MetaWinnerAdService::class)->reportDataForBrand($brand);

        return [
            'winner_ads' => $reportData['winner_ads']->values()->all(),
            'fetched_ads' => $reportData['fetched_ads']->values()->all(),
            'scaling_campaigns' => $reportData['scaling_campaigns']->values()->all(),
            'daily_totals' => $reportData['daily_totals']->values()->all(),
            'cached_at' => now()->toIso8601String(),
            'expires_at' => $cacheTtl->toIso8601String(),
        ];
    }
}
