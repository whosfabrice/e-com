@extends('layouts.app')

@section('content')
    <section class="report-shell">
        <header>
            <div>
                <h1>{{ $brand->name }} Overview</h1>
                <h2>{{ now('Europe/Berlin')->toDateString() }}</h2>
                <p>Based on Meta ad performance from the last 7 days.</p>
            </div>

            <nav aria-label="Report actions">
                <a href="{{ route('brand.settings', $brand) }}">Brand settings</a>
            </nav>

            @if ($winnerAdsCachedAt && $winnerAdsExpiresAt)
                <p>
                    Last updated {{ $winnerAdsCachedAt->diffForHumans() }} · Next update {{ $winnerAdsExpiresAt->diffForHumans() }} ·
                    <a href="{{ route('brand', ['brand' => $brand, 'refresh' => 1]) }}">Refresh now</a>
                </p>
            @endif
        </header>

        @if (session('status'))
            <p class="flash">{{ session('status') }}</p>
        @endif

        <section>
            <h2>Media Buying</h2>
            @if ($winnerAdsError)
                <p class="error">{{ $winnerAdsError }}</p>
            @elseif ($winnerAds->isEmpty())
                <p>✓ All winning creative tests have been added to a scaling campaign.</p>
            @else
                <p>Identified {{ $winnerAds->count() }} creative testing winner{{ $winnerAds->count() === 1 ? '' : 's' }} that should be added to a scaling campaign.</p>

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
                                Spend: {{ number_format($winnerAd['spend'], 2, ',', '.') }}€ ·
                                Purchases: {{ $winnerAd['purchases'] }} ·
                                CPA: {{ number_format($winnerAd['cpa'], 2, ',', '.') }}€
                            </p>

                            <form action="{{ route('brand.duplicate-ad', $brand) }}" method="POST">
                                @csrf
                                <input name="ad_id" type="hidden" value="{{ $winnerAd['ad_id'] }}">
                                <input name="ad_name" type="hidden" value="{{ $winnerAd['ad_name'] }}">

                                @if ($scalingCampaigns->isEmpty())
                                    <p>No scaling campaigns found.</p>
                                @else
                                    <label>
                                        Scale into
                                        <select name="campaign" required>
                                            @foreach ($scalingCampaigns as $campaign)
                                                <option value='@json(["id" => $campaign["campaign_id"], "name" => $campaign["campaign_name"]])'>{{ $campaign['campaign_name'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <button type="submit">Add</button>
                                @endif
                            </form>
                        </section>
                    </article>
                @endforeach
            @endif
        </section>

        <section>
            <h2>Creative Strategy</h2>

            @if ($fetchedAds->isEmpty())
                <p>No Meta ad performance data found for the last 7 days.</p>
            @else
                <section>
                    @foreach ($strategyInsights as $dimension)
                        <article>
                            <h3>{{ $dimension['title'] }}</h3>

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
                                        <tr>
                                            <th scope="row">{{ $row['value'] }}</th>
                                            <td>{{ number_format($row['spend'], 2, ',', '.') }}€</td>
                                            <td>{{ $row['purchases'] }}</td>
                                            <td>{{ number_format($row['cpa'], 2, ',', '.') }}€</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </article>
                    @endforeach
                </section>
            @endif
        </section>
    </section>
@endsection
