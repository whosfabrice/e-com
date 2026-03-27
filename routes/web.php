<?php

use App\Enums\AdvertisingPlatform;
use App\Enums\TargetMetric;
use App\Jobs\DuplicateMetaAdToCampaign;
use App\Http\Controllers\Slack\InteractionController;
use App\Models\Brand;
use App\Models\Target;
use App\Services\BrandReportCache;
use App\Services\StoredAdReportService;
use App\Services\Slack\SlackReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
})->middleware('auth.basic')->name('brand.settings');

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
    StoredAdReportService $storedAdReportService,
    SlackReportBuilder $slackReportBuilder,
) {
    $timeframeDays = (int) $request->integer('days', 7);
    $timeframeDays = in_array($timeframeDays, [7, 14, 30], true) ? $timeframeDays : 7;
    $brand->load(['targets']);
    $winnerAds = collect();
    $fetchedAds = collect();
    $scalingCampaigns = collect();
    $dailyTotals = collect();
    $dataCoverage = null;
    $developmentCharts = [];
    $strategyInsights = [];
    $winnerAdsError = null;
    $winnerAdsCachedAt = null;
    $winnerAdsExpiresAt = null;
    $niceChartMax = function (float $value): float {
        if ($value <= 0) {
            return 1.0;
        }

        $magnitude = 10 ** floor(log10($value));
        $normalized = $value / $magnitude;

        $niceNormalized = match (true) {
            $normalized <= 1 => 1,
            $normalized <= 2 => 2,
            $normalized <= 2.5 => 2.5,
            $normalized <= 5 => 5,
            default => 10,
        };

        return $niceNormalized * $magnitude;
    };
    $formatChartAxisLabel = function (float $value, string $metric): string {
        $suffix = $metric === 'spend' || $metric === 'cpa' ? '€' : '';

        if ($value >= 1000) {
            $compact = $value / 1000;
            $decimals = fmod($compact, 1.0) === 0.0 ? 0 : 1;

            return number_format($compact, $decimals, ',', '.').'k'.$suffix;
        }

        if ($metric === 'spend' || $metric === 'cpa') {
            $decimals = $value >= 100 ? 0 : ($value >= 10 ? 1 : 2);

            return number_format($value, $decimals, ',', '.').$suffix;
        }

        return number_format($value, 0, ',', '.');
    };

    try {
        $reportData = $storedAdReportService->reportDataForBrand($brand, $timeframeDays);

        $winnerAds = collect($reportData['winner_ads'] ?? []);
        $fetchedAds = collect($reportData['fetched_ads'] ?? []);
        $scalingCampaigns = collect($reportData['scaling_campaigns'] ?? []);
        $dailyTotals = collect($reportData['daily_totals'] ?? []);
        $dataCoverage = $reportData['coverage'] ?? null;
        $metaTargets = $brand->targets->where('platform', AdvertisingPlatform::Meta->value);
        $targetCpa = (float) ($metaTargets->firstWhere('metric', TargetMetric::Cpa->value)?->value ?? 0);
        $targetPurchases = (int) ($metaTargets->firstWhere('metric', TargetMetric::Purchases->value)?->value ?? 0);
        $strategyInsights = collect($slackReportBuilder->creativeStrategyInsights($fetchedAds))
            ->map(function (array $dimension) use ($targetCpa, $targetPurchases): array {
                return [
                    ...$dimension,
                    'rows' => collect($dimension['rows'] ?? [])
                        ->map(fn (array $row): array => [
                            ...$row,
                            'meets_target' => $targetCpa > 0
                                && $targetPurchases > 0
                                && (int) ($row['purchases'] ?? 0) >= $targetPurchases
                                && (float) ($row['cpa'] ?? 0) <= $targetCpa,
                        ])
                        ->all(),
                ];
            })
            ->all();
        $developmentCharts = collect([
            ['title' => 'Spend', 'metric' => 'spend'],
            ['title' => 'Purchases', 'metric' => 'purchases'],
            ['title' => 'CPA', 'metric' => 'cpa'],
        ])->map(function (array $chart) use ($dailyTotals, $niceChartMax, $formatChartAxisLabel): array {
            $values = $dailyTotals->pluck($chart['metric'])->map(fn (mixed $value): float => (float) $value)->values();
            $width = 640;
            $height = 180;
            $paddingLeft = 80;
            $axisLabelX = 56;
            $paddingRight = 8;
            $paddingTop = 12;
            $paddingBottom = 28;
            $plotWidth = $width - $paddingLeft - $paddingRight;
            $plotHeight = $height - $paddingTop - $paddingBottom;
            $rawMaxValue = max((float) ($values->max() ?? 0), 1.0);
            $maxValue = $niceChartMax($rawMaxValue);
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
                'plot_x_start' => $paddingLeft,
                'axis_labels' => [
                    [
                        'x' => $axisLabelX,
                        'y' => $paddingTop + 4,
                        'text' => $formatChartAxisLabel($maxValue, $chart['metric']),
                    ],
                    [
                        'x' => $axisLabelX,
                        'y' => $paddingTop + ($plotHeight / 2) + 4,
                        'text' => $formatChartAxisLabel($maxValue / 2, $chart['metric']),
                    ],
                    [
                        'x' => $axisLabelX,
                        'y' => $paddingTop + $plotHeight + 4,
                        'text' => $formatChartAxisLabel(0, $chart['metric']),
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
    } catch (\Throwable $throwable) {
        $winnerAdsError = $throwable->getMessage();
    }

    return view('brand', compact(
        'brand',
        'timeframeDays',
        'dailyTotals',
        'dataCoverage',
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
