@extends('layouts.app')

@section('content')
    <h1>{{ $brand->name }}</h1>

    @if (session('status'))
        <p>{{ session('status') }}</p>
    @endif

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
                </li>
            @endforeach
        </ul>
    @endif

    <h3>Active Scaling Campaigns</h3>
    <ul>
        @foreach ($scalingCampaigns as $campaign)
            <li>
                <a href="https://www.facebook.com/adsmanager/manage/campaigns?act={{ $brand->meta_ad_account_id }}&selected_campaign_ids={{ $campaign['campaign_id'] }}" rel="noreferrer" target="_blank">
                    {{ $campaign['campaign_name'] }}
                </a>
            </li>
        @endforeach
    </ul>
@endsection
