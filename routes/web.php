<?php

use App\Enums\AdvertisingPlatform;
use App\Enums\TargetMetric;
use App\Jobs\DuplicateMetaAdToCampaign;
use App\Http\Controllers\Slack\InteractionController;
use App\Models\AdDailyEntity;
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
    $metricValueForPeriod = function (Brand $brand, string $from, string $to, string $metric): float {
        $entities = AdDailyEntity::query()
            ->where('brand_id', $brand->id)
            ->where('platform', AdvertisingPlatform::Meta->value)
            ->whereBetween('date', [$from, $to])
            ->with(['metrics' => fn ($query) => $query
                ->where('source', 'meta')
                ->whereIn('metric', ['spend', 'purchases'])])
            ->get();

        $spend = (float) $entities->sum(fn (AdDailyEntity $entity): float => (float) ($entity->metrics->firstWhere('metric', 'spend')?->value ?? 0));
        $purchases = (float) $entities->sum(fn (AdDailyEntity $entity): float => (float) ($entity->metrics->firstWhere('metric', 'purchases')?->value ?? 0));

        return match ($metric) {
            'spend' => round($spend, 2),
            'purchases' => (int) $purchases,
            'cpa' => $purchases > 0 ? round($spend / $purchases, 2) : 0.0,
            default => 0.0,
        };
    };
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
        $previousSince = Carbon::now('Europe/Berlin')->subDays($timeframeDays * 2)->toDateString();
        $previousUntil = Carbon::now('Europe/Berlin')->subDays($timeframeDays + 1)->toDateString();
        $previousFetchedAds = $storedAdReportService->fetchedAdsForBrandBetween($brand, $previousSince, $previousUntil);
        $previousStrategyByDimension = collect($slackReportBuilder->creativeStrategyInsights($previousFetchedAds))
            ->mapWithKeys(fn (array $dimension): array => [
                $dimension['title'] => collect($dimension['rows'] ?? [])
                    ->mapWithKeys(fn (array $row): array => [$row['value'] => $row]),
            ]);
        $changeData = function (float $current, ?float $previous, bool $inverse = false): array {
            if ($previous === null || $previous <= 0) {
                return ['direction' => 'neutral', 'label' => ''];
            }

            $changePercent = round((($current - $previous) / $previous) * 100, 1);
            $direction = $changePercent == 0.0
                ? 'neutral'
                : ($inverse
                    ? ($changePercent < 0 ? 'up' : 'down')
                    : ($changePercent > 0 ? 'up' : 'down'));

            return [
                'direction' => $direction,
                'label' => match (true) {
                    $changePercent > 0 => '↑'.number_format(abs($changePercent), 1, ',', '.').'%',
                    $changePercent < 0 => '↓'.number_format(abs($changePercent), 1, ',', '.').'%',
                    default => '•0,0%',
                },
            ];
        };
        $strategyInsights = collect($slackReportBuilder->creativeStrategyInsights($fetchedAds))
            ->map(function (array $dimension) use ($changeData, $previousStrategyByDimension, $targetCpa, $targetPurchases): array {
                $previousRows = $previousStrategyByDimension->get($dimension['title'], collect());

                return [
                    ...$dimension,
                    'rows' => collect($dimension['rows'] ?? [])
                        ->map(function (array $row) use ($changeData, $previousRows, $targetCpa, $targetPurchases): array {
                            $previousRow = $previousRows->get($row['value']);

                            return [
                                ...$row,
                                'meets_target' => $targetCpa > 0
                                    && $targetPurchases > 0
                                    && (int) ($row['purchases'] ?? 0) >= $targetPurchases
                                    && (float) ($row['cpa'] ?? 0) <= $targetCpa,
                                'spend_change' => $changeData((float) ($row['spend'] ?? 0), isset($previousRow['spend']) ? (float) $previousRow['spend'] : null),
                                'purchases_change' => $changeData((float) ($row['purchases'] ?? 0), isset($previousRow['purchases']) ? (float) $previousRow['purchases'] : null),
                                'cpa_change' => $changeData((float) ($row['cpa'] ?? 0), isset($previousRow['cpa']) ? (float) $previousRow['cpa'] : null, true),
                            ];
                        })
                        ->all(),
                ];
            })
            ->all();
        $currentUntil = Carbon::now('Europe/Berlin')->subDay()->toDateString();
        $developmentCharts = collect([
            ['title' => 'Spend', 'metric' => 'spend'],
            ['title' => 'Purchases', 'metric' => 'purchases'],
            ['title' => 'CPA', 'metric' => 'cpa'],
        ])->map(function (array $chart) use (
            $brand,
            $currentUntil,
            $dailyTotals,
            $formatChartAxisLabel,
            $metricValueForPeriod,
            $niceChartMax,
            $previousSince,
            $previousUntil,
            $timeframeDays,
        ): array {
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
            $averageValue = (float) $values->avg();
            $xStep = $values->count() > 1 ? $plotWidth / ($values->count() - 1) : 0.0;
            $averageY = $paddingTop + $plotHeight;

            if ($maxValue > $minValue) {
                $averageY = $paddingTop + $plotHeight - ((($averageValue - $minValue) / ($maxValue - $minValue)) * $plotHeight);
            }

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
            $currentTotal = match ($chart['metric']) {
                'cpa' => (float) (($dailyTotals->sum('purchases') > 0)
                    ? round($dailyTotals->sum('spend') / $dailyTotals->sum('purchases'), 2)
                    : 0),
                default => (float) $values->sum(),
            };
            $previousTotal = $metricValueForPeriod($brand, $previousSince, $previousUntil, $chart['metric']);
            $changePercent = $previousTotal > 0
                ? round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1)
                : null;
            $changeDirection = $changePercent === null || $changePercent == 0.0
                ? 'neutral'
                : ($chart['metric'] === 'cpa'
                    ? ($changePercent < 0 ? 'up' : 'down')
                    : ($changePercent > 0 ? 'up' : 'down'));

            return [
                ...$chart,
                'width' => $width,
                'height' => $height,
                'plot_x_start' => $paddingLeft,
                'plot_x_end' => $width - $paddingRight,
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
                'average' => [
                    'value' => $averageValue,
                    'y' => round($averageY, 2),
                ],
                'total' => $currentTotal,
                'change' => [
                    'direction' => $changeDirection,
                    'label' => match (true) {
                        $changePercent === null => "vs prev {$timeframeDays}d",
                        $changePercent > 0 => '↑'.number_format(abs($changePercent), 1, ',', '.').'%',
                        $changePercent < 0 => '↓'.number_format(abs($changePercent), 1, ',', '.').'%',
                        default => '•0,0%',
                    },
                ],
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
