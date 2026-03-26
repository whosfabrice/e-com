<?php

use App\Enums\AdvertisingPlatform;
use App\Enums\TargetMetric;
use App\Jobs\DuplicateMetaAdToCampaign;
use App\Http\Controllers\Slack\InteractionController;
use App\Jobs\WarmBrandReportCache as WarmBrandReportCacheJob;
use App\Models\Brand;
use App\Models\Target;
use App\Services\BrandReportCache;
use App\Services\Slack\SlackReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

Route::post('/brands/{brand}/targets', function (Request $request, Brand $brand, BrandReportCache $brandReportCache) {
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

    $brandReportCache->forget($brand);

    return redirect()
        ->route('brand', $brand)
        ->with('status', 'Updated target settings.');
})->name('brand.targets.update');

Route::patch('/brands/{brand}/targets/{target}', function (Request $request, Brand $brand, Target $target, BrandReportCache $brandReportCache) {
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

    $brandReportCache->forget($brand);

    return redirect()
        ->route('brand', $brand)
        ->with('status', 'Updated target.');
})->name('brand.targets.patch');

Route::delete('/brands/{brand}/targets/{target}', function (Brand $brand, Target $target, BrandReportCache $brandReportCache) {
    abort_unless($target->brand_id === $brand->id, 404);

    $target->delete();

    $brandReportCache->forget($brand);

    return redirect()
        ->route('brand', $brand)
        ->with('status', 'Deleted target.');
})->name('brand.targets.delete');

Route::get('/brands/{brand}/settings', function (Brand $brand) {
    $brand->load(['targets']);

    return view('brand-settings', compact('brand'));
})/*->middleware('auth.basic')*/->name('brand.settings');

Route::patch('/brands/{brand}/settings', function (Request $request, Brand $brand, BrandReportCache $brandReportCache) {
    $validated = $request->validate([
        'meta_ad_account_id' => ['required', 'string'],
        'slack_channel_id' => ['required', 'string'],
    ]);

    $brand->update([
        'meta_ad_account_id' => $validated['meta_ad_account_id'],
        'slack_channel_id' => $validated['slack_channel_id'],
    ]);

    $brandReportCache->forget($brand);

    return redirect()
        ->route('brand.settings', $brand)
        ->with('status', 'Updated brand settings.');
})->name('brand.settings.patch');

Route::get('/brands/{brand}', function (
    Request $request,
    Brand $brand,
    BrandReportCache $brandReportCache,
    SlackReportBuilder $slackReportBuilder,
) {
    $brand->load(['targets']);
    $winnerAds = collect();
    $fetchedAds = collect();
    $scalingCampaigns = collect();
    $dailyTotals = collect();
    $developmentCharts = [];
    $strategyInsights = [];
    $winnerAdsError = null;
    $winnerAdsCachedAt = null;
    $winnerAdsExpiresAt = null;

    try {
        if ($request->boolean('refresh')) {
            if ($brandReportCache->markWarming($brand)) {
                WarmBrandReportCacheJob::dispatch($brand->id);
            }
        }

        $cachedWinnerAds = $brandReportCache->cached($brand);

        if ($cachedWinnerAds === null) {
            if ($brandReportCache->markWarming($brand)) {
                WarmBrandReportCacheJob::dispatch($brand->id);
            }

            $winnerAdsError = $brandReportCache->isWarming($brand)
                ? 'Report data is being prepared in the background. Please reload in a moment.'
                : 'Report data is currently unavailable.';

            return view('brand', compact(
                'brand',
                'dailyTotals',
                'developmentCharts',
                'fetchedAds',
                'scalingCampaigns',
                'strategyInsights',
                'winnerAds',
                'winnerAdsCachedAt',
                'winnerAdsError',
                'winnerAdsExpiresAt',
            ));
        }

        if ($request->boolean('refresh')) {
            session()->flash('status', $brandReportCache->isWarming($brand)
                ? 'Queued a background refresh. Reload in a moment to see updated data.'
                : 'Refresh is already in progress.');
        }

        $winnerAds = collect($cachedWinnerAds['winner_ads'] ?? []);
        $fetchedAds = collect($cachedWinnerAds['fetched_ads'] ?? []);
        $scalingCampaigns = collect($cachedWinnerAds['scaling_campaigns'] ?? []);
        $dailyTotals = collect($cachedWinnerAds['daily_totals'] ?? []);
        $strategyInsights = $slackReportBuilder->creativeStrategyInsights($fetchedAds);
        $developmentCharts = collect([
            ['title' => 'Spend', 'metric' => 'spend'],
            ['title' => 'Purchases', 'metric' => 'purchases'],
            ['title' => 'CPA', 'metric' => 'cpa'],
        ])->map(function (array $chart) use ($dailyTotals): array {
            $values = $dailyTotals->pluck($chart['metric'])->map(fn (mixed $value): float => (float) $value)->values();
            $width = 640;
            $height = 180;
            $paddingLeft = 56;
            $paddingRight = 8;
            $paddingTop = 12;
            $paddingBottom = 28;
            $plotWidth = $width - $paddingLeft - $paddingRight;
            $plotHeight = $height - $paddingTop - $paddingBottom;
            $maxValue = max((float) ($values->max() ?? 0), 1.0);
            $minValue = 0.0;
            $xStep = $values->count() > 1 ? $plotWidth / ($values->count() - 1) : 0.0;

            $pointData = $values
                ->map(function (float $value, int $index) use ($paddingLeft, $paddingTop, $plotHeight, $maxValue, $minValue, $xStep): array {
                    $x = $paddingLeft + ($index * $xStep);
                    $y = $paddingTop + $plotHeight;

                    if ($maxValue > $minValue) {
                        $y = $paddingTop + $plotHeight - ((($value - $minValue) / ($maxValue - $minValue)) * $plotHeight);
                    }

                    return [
                        'x' => round($x, 2),
                        'y' => round($y, 2),
                    ];
                })
                ->values();

            return [
                ...$chart,
                'width' => $width,
                'height' => $height,
                'axis_labels' => [
                    [
                        'x' => $paddingLeft - 8,
                        'y' => $paddingTop + 4,
                        'text' => $chart['metric'] === 'spend' || $chart['metric'] === 'cpa'
                            ? number_format($maxValue, 2, ',', '.').'€'
                            : number_format($maxValue, 0, ',', '.'),
                    ],
                    [
                        'x' => $paddingLeft - 8,
                        'y' => $paddingTop + ($plotHeight / 2) + 4,
                        'text' => $chart['metric'] === 'spend' || $chart['metric'] === 'cpa'
                            ? number_format($maxValue / 2, 2, ',', '.').'€'
                            : number_format($maxValue / 2, 0, ',', '.'),
                    ],
                    [
                        'x' => $paddingLeft - 8,
                        'y' => $paddingTop + $plotHeight + 4,
                        'text' => $chart['metric'] === 'spend' || $chart['metric'] === 'cpa'
                            ? number_format(0, 2, ',', '.').'€'
                            : '0',
                    ],
                ],
                'labels' => $dailyTotals->pluck('date')->map(fn (string $date): string => Carbon::parse($date)->format('D'))->values()->all(),
                'points' => $pointData->map(fn (array $point): string => $point['x'].','.$point['y'])->implode(' '),
                'point_data' => $pointData->map(function (array $point, int $index) use ($chart, $values, $dailyTotals): array {
                    $value = (float) ($values[$index] ?? 0);

                    return [
                        ...$point,
                        'label' => Carbon::parse((string) ($dailyTotals[$index]['date'] ?? now()->toDateString()))->format('D'),
                        'value' => $chart['metric'] === 'spend' || $chart['metric'] === 'cpa'
                            ? number_format($value, 2, ',', '.').'€'
                            : number_format($value, 0, ',', '.'),
                    ];
                })->all(),
                'values' => $values->all(),
                'total' => match ($chart['metric']) {
                    'cpa' => (float) (($dailyTotals->sum('purchases') > 0)
                        ? round($dailyTotals->sum('spend') / $dailyTotals->sum('purchases'), 2)
                        : 0),
                    default => (float) $values->sum(),
                },
                'max' => $maxValue,
            ];
        })->all();
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
        'dailyTotals',
        'developmentCharts',
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
