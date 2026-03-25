@extends('layouts.app')

@section('content')
    <section class="settings-shell">
        <header>
            <div>
                <h1>{{ $brand->name }} Settings</h1>
                <p>Configure brand-specific report and duplication settings.</p>
            </div>

            <nav aria-label="Settings actions">
                <a href="{{ route('brand', $brand) }}">Back to report</a>
            </nav>
        </header>

        @if (session('status'))
            <p class="flash">{{ session('status') }}</p>
        @endif

        <section>
            <h2>Brand Settings</h2>

            <form action="{{ route('brand.settings.patch', $brand) }}" method="POST">
                @csrf
                @method('PATCH')

                <fieldset>
                    <legend>Core Identifiers</legend>

                    <label>
                        Meta Ad Account ID
                        <input
                            name="meta_ad_account_id"
                            required
                            type="text"
                            value="{{ old('meta_ad_account_id', $brand->meta_ad_account_id) }}"
                        >
                    </label>

                    <label>
                        Slack Channel ID
                        <input
                            name="slack_channel_id"
                            required
                            type="text"
                            value="{{ old('slack_channel_id', $brand->slack_channel_id) }}"
                        >
                    </label>
                </fieldset>

                <button type="submit">Save Brand Settings</button>
            </form>
        </section>

        <section>
            <h2>Target Settings</h2>

            @if ($brand->targets->isNotEmpty())
                <ul>
                    @foreach ($brand->targets as $target)
                        <li>
                            <form action="{{ route('brand.targets.patch', [$brand, $target]) }}" method="POST">
                                @csrf
                                @method('PATCH')

                                <label>
                                    Source
                                    <select name="platform" required>
                                        @foreach (\App\Enums\AdvertisingPlatform::cases() as $platform)
                                            <option @selected($target->platform === $platform) value="{{ $platform->value }}">{{ ucfirst($platform->value) }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label>
                                    Target
                                    <select name="metric" required>
                                        @foreach (\App\Enums\TargetMetric::cases() as $metric)
                                            <option @selected($target->metric === $metric) value="{{ $metric->value }}">{{ ucfirst($metric->value) }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label>
                                    Value
                                    <input
                                        name="value"
                                        required
                                        step="0.01"
                                        type="number"
                                        value="{{ $target->value }}"
                                    >
                                </label>

                                <button type="submit">Save</button>
                            </form>

                            <form action="{{ route('brand.targets.delete', [$brand, $target]) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="submit">Delete</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form action="{{ route('brand.targets.update', $brand) }}" method="POST">
                @csrf

                <fieldset>
                    <legend>Add Target</legend>

                    <label>
                        Source
                        <select name="platform" required>
                            @foreach (\App\Enums\AdvertisingPlatform::cases() as $platform)
                                <option @selected(old('platform', \App\Enums\AdvertisingPlatform::Meta->value) === $platform->value) value="{{ $platform->value }}">{{ ucfirst($platform->value) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        Target
                        <select name="metric" required>
                            @foreach (\App\Enums\TargetMetric::cases() as $metric)
                                <option @selected(old('metric') === $metric->value) value="{{ $metric->value }}">{{ ucfirst($metric->value) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        Value
                        <input
                            name="value"
                            required
                            step="0.01"
                            type="number"
                            value="{{ old('value', 0) }}"
                        >
                    </label>
                </fieldset>

                <button type="submit">Save Target</button>
            </form>
        </section>
    </section>
@endsection
