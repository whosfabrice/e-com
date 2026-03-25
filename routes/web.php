<?php

use App\Enums\AdvertisingPlatform;
use App\Enums\TargetMetric;
use App\Jobs\DuplicateMetaAdToCampaign;
use App\Http\Controllers\Slack\InteractionController;
use App\Models\Brand;
use App\Models\Target;
use App\Services\Meta\MetaWinnerAdService;
use App\Services\Slack\SlackReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('index');
})->middleware('auth.basic')->name('dashboard');

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

Route::post('/brands/{brand}/targets', function (Request $request, Brand $brand) {
    $validated = $request->validate([
        'platform' => ['required', 'string'],
        'metric' => ['required', 'string'],
        'value' => ['required', 'numeric', 'min:0'],
    ]);

    $platform = AdvertisingPlatform::tryFrom($validated['platform']);
    $targetMetric = TargetMetric::tryFrom($validated['metric']);

    abort_unless($platform !== null, 422, 'Please select a valid source.');
    abort_unless($targetMetric !== null, 422, 'Please select a valid target.');

    $brand->targets()->updateOrCreate(
        [
            'platform' => $platform->value,
            'metric' => $targetMetric->value,
        ],
        [
            'value' => $targetMetric === TargetMetric::Purchases
                ? (int) $validated['value']
                : (float) $validated['value'],
        ],
    );

    Cache::forget(sprintf('brands.%s.winner_ads', $brand->id));

    return redirect()
        ->route('brand', $brand)
        ->with('status', 'Updated target settings.');
})->name('brand.targets.update');

Route::patch('/brands/{brand}/targets/{target}', function (Request $request, Brand $brand, Target $target) {
    abort_unless($target->brand_id === $brand->id, 404);

    $validated = $request->validate([
        'platform' => ['required', 'string'],
        'metric' => ['required', 'string'],
        'value' => ['required', 'numeric', 'min:0'],
    ]);

    $platform = AdvertisingPlatform::tryFrom($validated['platform']);
    $targetMetric = TargetMetric::tryFrom($validated['metric']);

    abort_unless($platform !== null, 422, 'Please select a valid source.');
    abort_unless($targetMetric !== null, 422, 'Please select a valid target.');

    $target->update([
        'platform' => $platform->value,
        'metric' => $targetMetric->value,
        'value' => $targetMetric === TargetMetric::Purchases
            ? (int) $validated['value']
            : (float) $validated['value'],
    ]);

    Cache::forget(sprintf('brands.%s.winner_ads', $brand->id));

    return redirect()
        ->route('brand', $brand)
        ->with('status', 'Updated target.');
})->name('brand.targets.patch');

Route::delete('/brands/{brand}/targets/{target}', function (Brand $brand, Target $target) {
    abort_unless($target->brand_id === $brand->id, 404);

    $target->delete();

    Cache::forget(sprintf('brands.%s.winner_ads', $brand->id));

    return redirect()
        ->route('brand', $brand)
        ->with('status', 'Deleted target.');
})->name('brand.targets.delete');

Route::get('/brands/{brand}/settings', function (Brand $brand) {
    $brand->load(['targets']);

    return view('brand-settings', compact('brand'));
})->middleware('auth.basic')->name('brand.settings');

Route::patch('/brands/{brand}/settings', function (Request $request, Brand $brand) {
    $validated = $request->validate([
        'meta_ad_account_id' => ['required', 'string'],
        'slack_channel_id' => ['required', 'string'],
    ]);

    $brand->update([
        'meta_ad_account_id' => $validated['meta_ad_account_id'],
        'slack_channel_id' => $validated['slack_channel_id'],
    ]);

    Cache::forget(sprintf('brands.%s.winner_ads', $brand->id));

    return redirect()
        ->route('brand.settings', $brand)
        ->with('status', 'Updated brand settings.');
})->name('brand.settings.patch');

Route::get('/brands/{brand}', function (
    Request $request,
    Brand $brand,
    MetaWinnerAdService $metaWinnerAdService,
    SlackReportBuilder $slackReportBuilder,
) {
    $brand->load(['targets']);
    $winnerAds = collect();
    $fetchedAds = collect();
    $scalingCampaigns = collect();
    $strategyInsights = [];
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
                'fetched_ads' => $reportData['fetched_ads']->values()->all(),
                'scaling_campaigns' => $reportData['scaling_campaigns']->values()->all(),
                'cached_at' => now()->toIso8601String(),
                'expires_at' => $cacheTtl->toIso8601String(),
            ];
        });

        $winnerAds = collect($cachedWinnerAds['winner_ads'] ?? []);
        $fetchedAds = collect($cachedWinnerAds['fetched_ads'] ?? []);
        $scalingCampaigns = collect($cachedWinnerAds['scaling_campaigns'] ?? []);
        $strategyInsights = $slackReportBuilder->creativeStrategyInsights($fetchedAds);
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
        'fetchedAds',
        'scalingCampaigns',
        'strategyInsights',
        'winnerAds',
        'winnerAdsCachedAt',
        'winnerAdsError',
        'winnerAdsExpiresAt',
    ));
})/*->middleware('auth.basic')*/->name('brand');

Route::post('/slack/interactions', InteractionController::class)->name('slack.interactions');
