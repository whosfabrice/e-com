<?php

namespace App\Services;

use App\Models\Brand;
use App\Services\Meta\MetaWinnerAdService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class BrandReportCache
{
    protected const KEY_VERSION = 'v2';

    public function warmingKeyForBrand(Brand $brand, int $days = 7): string
    {
        return sprintf('brands.%s.winner_ads.%s.%s.warming', $brand->id, $days, self::KEY_VERSION);
    }

    public function keyForBrand(Brand $brand, int $days = 7): string
    {
        return sprintf('brands.%s.winner_ads.%s.%s', $brand->id, $days, self::KEY_VERSION);
    }

    public function ttl(): Carbon
    {
        return now()->addHour();
    }

    public function get(Brand $brand, int $days = 7, bool $refresh = false): array
    {
        $cacheKey = $this->keyForBrand($brand, $days);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $payload = Cache::remember(
            $cacheKey,
            $this->ttl(),
            fn (): array => $this->buildPayload($brand, $days),
        );

        if (! array_key_exists('daily_totals', $payload)) {
            Cache::forget($cacheKey);

            $payload = Cache::remember(
                $cacheKey,
                $this->ttl(),
                fn (): array => $this->buildPayload($brand, $days),
            );
        }

        return $payload;
    }

    public function cached(Brand $brand, int $days = 7): ?array
    {
        $payload = Cache::get($this->keyForBrand($brand, $days));

        if (! is_array($payload) || ! array_key_exists('daily_totals', $payload)) {
            return null;
        }

        return $payload;
    }

    public function refresh(Brand $brand, int $days = 7): array
    {
        return $this->get($brand, $days, true);
    }

    public function forget(Brand $brand): void
    {
        foreach ([7, 14, 30] as $days) {
            Cache::forget($this->keyForBrand($brand, $days));
            Cache::forget($this->warmingKeyForBrand($brand, $days));
        }
    }

    public function markWarming(Brand $brand, int $days = 7, int $seconds = 300): bool
    {
        return Cache::add($this->warmingKeyForBrand($brand, $days), true, now()->addSeconds($seconds));
    }

    public function clearWarming(Brand $brand, int $days = 7): void
    {
        Cache::forget($this->warmingKeyForBrand($brand, $days));
    }

    public function isWarming(Brand $brand, int $days = 7): bool
    {
        return Cache::has($this->warmingKeyForBrand($brand, $days));
    }

    protected function buildPayload(Brand $brand, int $days = 7): array
    {
        $cacheTtl = $this->ttl();
        $reportData = app(MetaWinnerAdService::class)->reportDataForBrand($brand, $days);

        return [
            'winner_ads' => $reportData['winner_ads']->values()->all(),
            'fetched_ads' => $reportData['fetched_ads']->values()->all(),
            'scaling_campaigns' => $reportData['scaling_campaigns']->values()->all(),
            'daily_totals' => $reportData['daily_totals']->values()->all(),
            'timeframe_days' => $days,
            'cached_at' => now()->toIso8601String(),
            'expires_at' => $cacheTtl->toIso8601String(),
        ];
    }
}
