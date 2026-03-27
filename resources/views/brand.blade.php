@extends('layouts.app')

@section('content')
    <section class="report-shell">
        <header>
            <div>
                <h1>{{ $brand->name }}</h1>
                <p>{{ now('Europe/Berlin')->toDateString() }}</p>
            </div>

            <nav aria-label="Report actions">
                <a @class(['is-active' => $timeframeDays === 7]) href="{{ route('brand', ['brand' => $brand, 'days' => 7]) }}">Last 7 days</a> ·
                <a @class(['is-active' => $timeframeDays === 14]) href="{{ route('brand', ['brand' => $brand, 'days' => 14]) }}">Last 14 days</a> ·
                <a @class(['is-active' => $timeframeDays === 30]) href="{{ route('brand', ['brand' => $brand, 'days' => 30]) }}">Last 30 days</a>
            </nav>

            @if ($winnerAdsCachedAt && $winnerAdsExpiresAt)
                <p>
                    Last updated {{ $winnerAdsCachedAt->diffForHumans() }} · Next update {{ $winnerAdsExpiresAt->diffForHumans() }} ·
                    <a href="{{ route('brand', ['brand' => $brand, 'days' => $timeframeDays, 'refresh' => 1]) }}">Refresh now</a>
                </p>
            @endif
        </header>

        @if (session('status'))
            <p class="flash">{{ session('status') }}</p>
        @endif

        <section class="report-development">
            @if ($dailyTotals->isEmpty())
                <p>No daily development data found for the last {{ $timeframeDays }} days.</p>
            @else
                <section class="development-charts">
                    @foreach ($developmentCharts as $chart)
                        <article>
                            <header>
                                <h3>{{ $chart['title'] }}</h3>
                                <p>
                                    <span class="metric-total">
                                        @if ($chart['metric'] === 'spend' || $chart['metric'] === 'cpa')
                                            {{ number_format($chart['total'], 2, ',', '.') }}€
                                        @else
                                            {{ number_format($chart['total'], 0, ',', '.') }}
                                        @endif
                                    </span>
                                    <small @class(["metric-change", "is-{$chart['change']['direction']}"])>{{ $chart['change']['label'] }}</small>
                                </p>
                            </header>

                            <svg aria-label="{{ $chart['title'] }} over the last 7 days" role="img" viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}">
                                @foreach ($chart['axis_labels'] as $axisLabel)
                                    <line x1="{{ $chart['plot_x_start'] }}" x2="{{ $chart['width'] }}" y1="{{ $axisLabel['y'] - 4 }}" y2="{{ $axisLabel['y'] - 4 }}"></line>
                                    <text class="chart-axis-label" x="{{ $axisLabel['x'] }}" y="{{ $axisLabel['y'] }}">{{ $axisLabel['text'] }}</text>
                                @endforeach
                                <line class="chart-average-line" x1="{{ $chart['plot_x_start'] }}" x2="{{ $chart['plot_x_end'] }}" y1="{{ $chart['average']['y'] }}" y2="{{ $chart['average']['y'] }}"></line>
                                <polyline points="{{ $chart['points'] }}"></polyline>

                                @foreach ($chart['point_data'] as $point)
                                    <g tabindex="0">
                                        <circle class="chart-hit-area" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="12"></circle>
                                        <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4"></circle>
                                        <rect class="chart-tooltip-bg" height="24" rx="4" ry="4" width="92" x="{{ max(4, min($chart['width'] - 96, $point['x'] - 46)) }}" y="{{ max(4, $point['y'] - 38) }}"></rect>
                                        <text class="chart-tooltip" x="{{ $point['x'] }}" y="{{ max(20, $point['y'] - 22) }}">{{ $point['label'] }}: {{ $point['value'] }}</text>
                                    </g>
                                    <text x="{{ $point['x'] }}" y="{{ $chart['height'] - 8 }}">{{ $point['label'] }}</text>
                                @endforeach
                            </svg>
                        </article>
                    @endforeach
                </section>
            @endif
        </section>

        <section class="report-media-buying">
            <h2>Media Buying</h2>
            @if ($winnerAdsError)
                <p class="error">{{ $winnerAdsError }}</p>
            @elseif ($winnerAds->isEmpty())
                <p>✓ All winning creative tests are being scaled.</p>
            @else
                <p>Identified {{ $winnerAds->count() }} creative testing winner{{ $winnerAds->count() === 1 ? '' : 's' }} to scale.</p>

                @foreach ($winnerAds as $winnerAd)
                    <article>
                        <figure>
                            @if ($winnerAd['thumbnail_url'])
                                <img alt="{{ $winnerAd['ad_name'] }}" src="{{ $winnerAd['thumbnail_url'] }}">
                            @endif
                        </figure>

                        <section>
                            <h3>
                                <a href="{{ $winnerAd['ad_link'] }}" rel="noreferrer" target="_blank">{{ $winnerAd['ad_name'] }}</a>
                            </h3>

                            <p>
                                {{ number_format($winnerAd['spend'], 2, ',', '.') }}€ Spend ·
                                {{ $winnerAd['purchases'] }} Purchases · 
                                {{ number_format($winnerAd['cpa'], 2, ',', '.') }}€ CPA
                            </p>

                            <form action="{{ route('brand.duplicate-ad', $brand) }}" method="POST">
                                @csrf
                                <input name="ad_id" type="hidden" value="{{ $winnerAd['ad_id'] }}">
                                <input name="ad_name" type="hidden" value="{{ $winnerAd['ad_name'] }}">

                                @if ($scalingCampaigns->isEmpty())
                                    <p>No scaling campaigns found.</p>
                                @else
                                    <label>
                                        <select name="campaign" required>
                                            @foreach ($scalingCampaigns as $campaign)
                                                <option value='@json(["id" => $campaign["campaign_id"], "name" => $campaign["campaign_name"]])'>{{ $campaign['campaign_name'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <button type="submit">Scale</button>
                                @endif
                            </form>
                        </section>
                    </article>
                @endforeach
            @endif
        </section>

        

        <section class="report-strategy">
            <h2>Creative Strategy</h2>

            @if ($fetchedAds->isEmpty())
                <p>No Meta ad performance data found for the last 7 days.</p>
            @else
                <section>
                    @foreach ($strategyInsights as $dimension)
                        <article>
                            <h3>{{ $dimension['title'] }}</h3>

                            <section>
                                <table>
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ $dimension['label'] }}</th>
                                            <th scope="col">Spend</th>
                                            <th scope="col">Purchases</th>
                                            <th scope="col">CPA</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($dimension['rows'] as $row)
                                            <tr @class(['meets-target' => ! empty($row['meets_target'])])>
                                                <th scope="row">
                                                    {{ $row['value'] }}
                                                    <small>{{ number_format($row['spend_share_percent'] ?? 0, 1, ',', '.') }}%</small>
                                                </th>
                                                <td>
                                                    <span class="kpi-value">{{ number_format($row['spend'], 2, ',', '.') }}€</span>
                                                    @if (! empty($row['spend_change']['label']))
                                                        <small @class(["metric-change", "is-{$row['spend_change']['direction']}"])>{{ $row['spend_change']['label'] }}</small>
                                                    @endif
                                                </td>
                                                <td>
                                                    <span class="kpi-value">{{ $row['purchases'] }}</span>
                                                    @if (! empty($row['purchases_change']['label']))
                                                        <small @class(["metric-change", "is-{$row['purchases_change']['direction']}"])>{{ $row['purchases_change']['label'] }}</small>
                                                    @endif
                                                </td>
                                                <td>
                                                    <span class="kpi-value">{{ number_format($row['cpa'], 2, ',', '.') }}€</span>
                                                    @if (! empty($row['cpa_change']['label']))
                                                        <small @class(["metric-change", "is-{$row['cpa_change']['direction']}"])>{{ $row['cpa_change']['label'] }}</small>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </section>
                        </article>
                    @endforeach
                </section>
            @endif
        </section>

        <p>
            <a href="{{ route('brand.settings', $brand) }}">Settings</a> · Data coverage:
            @if ($dataCoverage)
                {{ $dataCoverage['from'] }} to {{ $dataCoverage['to'] }}
                @if (! empty($dataCoverage['missing_ranges']))
                    Missing: {{ implode(', ', $dataCoverage['missing_ranges']) }}.
                @endif
            @else
                None
            @endif
        </p>
    </section>
@endsection
