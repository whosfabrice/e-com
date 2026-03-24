@extends('layouts.app')

@section('content')
    <h1>{{ $brand->name }}</h1>

    <h2>Targets</h2>
    <ul>
        @foreach ($brand->targets as $target)
            <li>
                {{ $target->platform->name }} |
                {{ $target->metric->name }} |
                {{ $target->value }}
            </li>
        @endforeach
    </ul>

    <h2>Testing Winners</h2>
    @if ($winnerAdsCachedAt && $winnerAdsExpiresAt)
        <p>Last updated {{ $winnerAdsCachedAt->diffForHumans() }} | Next update {{ $winnerAdsExpiresAt->diffForHumans() }} | <a href="{{ route('brand', ['brand' => $brand, 'refresh' => 1]) }}">Update now</a></p>
    @endif
    <p>
        
    </p>
    @if ($winnerAdsError)
        <p>{{ $winnerAdsError }}</p>
    @elseif ($winnerAds->isEmpty())
        <p>No winner ads found.</p>
    @else
        <ul>
            @foreach ($winnerAds as $winnerAd)
                <li>
                    @if ($winnerAd['thumbnail_url'])
                        <img alt="{{ $winnerAd['ad_name'] }}" src="{{ $winnerAd['thumbnail_url'] }}"><br>
                    @endif
                    <a href="{{ $winnerAd['ad_link'] }}" rel="noreferrer" target="_blank">{{ $winnerAd['ad_name'] }}</a><br>
                    Spend: {{ $winnerAd['spend'] }} | Purchases: {{ $winnerAd['purchases'] }} | CPA: {{ $winnerAd['cpa'] }}
                </li>
            @endforeach
        </ul>
    @endif

    <h3>Active Scaling Campaigns</h3>
    <ul>
        @foreach ($brand->campaigns as $campaign)
            <li>
                <a href="{{ $campaign->metaAdsManagerUrl() }}" rel="noreferrer" target="_blank">
                    {{ $campaign->name }}
                </a>
            </li>
        @endforeach
    </ul>
@endsection
