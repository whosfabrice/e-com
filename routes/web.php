<?php

use App\Jobs\DuplicateMetaAdToCampaign;
use App\Http\Controllers\Slack\InteractionController;
use App\Models\Brand;
use App\Services\Meta\MetaWinnerAdService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('index');
})/*->middleware('auth.basic')*/->name('dashboard');

Route::post('/brands/{brand}/duplicate-ad', function (Request $request, Brand $brand) {
    $validated = $request->validate([
        'ad_id' => ['required', 'string'],
        'ad_name' => ['required', 'string'],
        'campaign' => ['required', 'string'],
    ]);

    $campaign = json_decode($validated['campaign'], true);

    abort_unless(
        is_array($campaign)
        && is_string($campaign['id'] ?? null)
        && $campaign['id'] !== ''
        && is_string($campaign['name'] ?? null)
        && $campaign['name'] !== '',
        422,
        'Please select a valid campaign.',
    );

    DuplicateMetaAdToCampaign::dispatch(
        $brand->id,
        $validated['ad_id'],
        $campaign['id'],
        $validated['ad_name'],
        $campaign['name'],
    );

    return redirect()
        ->route('brand', $brand)
        ->with('status', "Queued duplication of {$validated['ad_name']} into {$campaign['name']}.");
})->name('brand.duplicate-ad');

Route::get('/brands/{brand}', function (Request $request, Brand $brand, MetaWinnerAdService $metaWinnerAdService) {
    $brand->load(['targets']);
    $winnerAds = collect();
    $scalingCampaigns = collect();
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
            $reportData = $metaWinnerAdService->reportDataForBrand($brand);

            return [
                'winner_ads' => $reportData['winner_ads']->values()->all(),
                'scaling_campaigns' => $reportData['scaling_campaigns']->values()->all(),
                'cached_at' => now()->toIso8601String(),
                'expires_at' => $cacheTtl->toIso8601String(),
            ];
        });

        $winnerAds = collect($cachedWinnerAds['winner_ads'] ?? []);
        $scalingCampaigns = collect($cachedWinnerAds['scaling_campaigns'] ?? []);
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
        'scalingCampaigns',
        'winnerAds',
        'winnerAdsCachedAt',
        'winnerAdsError',
        'winnerAdsExpiresAt',
    ));
})/*->middleware('auth.basic')*/->name('brand');

Route::post('/slack/interactions', InteractionController::class)->name('slack.interactions');
