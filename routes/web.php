<?php

use App\Http\Controllers\Slack\InteractionController;
use App\Models\Brand;
use App\Services\Meta\MetaWinnerAdService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('index');
})->middleware('auth.basic')->name('dashboard');

Route::get('/brands/{brand}', function (Request $request, Brand $brand, MetaWinnerAdService $metaWinnerAdService) {
    $brand->load(['campaigns', 'targets']);
    $winnerAds = collect();
    $winnerAdsError = null;
    $winnerAdsCachedAt = null;
    $winnerAdsExpiresAt = null;

    try {
        $cacheTtl = now()->addHour();
        $cacheKey = sprintf('brands.%s.winner_ads', $brand->id);

        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        $cachedWinnerAds = Cache::remember($cacheKey, $cacheTtl, function () use ($brand, $cacheTtl, $metaWinnerAdService): array {
            return [
                'winner_ads' => $metaWinnerAdService->forBrand($brand)->values()->all(),
                'cached_at' => now()->toIso8601String(),
                'expires_at' => $cacheTtl->toIso8601String(),
            ];
        });

        $winnerAds = collect($cachedWinnerAds['winner_ads'] ?? []);
        $winnerAdsCachedAt = isset($cachedWinnerAds['cached_at'])
            ? Carbon::parse($cachedWinnerAds['cached_at'])
            : null;
        $winnerAdsExpiresAt = isset($cachedWinnerAds['expires_at'])
            ? Carbon::parse($cachedWinnerAds['expires_at'])
            : null;
    } catch (\Throwable $throwable) {
        $winnerAdsError = $throwable->getMessage();
    }

    return view('brand', compact(
        'brand',
        'winnerAds',
        'winnerAdsCachedAt',
        'winnerAdsError',
        'winnerAdsExpiresAt',
    ));
})->middleware('auth.basic')->name('brand');

Route::post('/slack/interactions', InteractionController::class)->name('slack.interactions');
