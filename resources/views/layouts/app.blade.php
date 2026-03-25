<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Laravel') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <header>
            <h1>{{ config('app.name', 'Laravel') }}</h1>
            <input id="hamburger" type="checkbox">
            <label for="hamburger" aria-label="Toggle navigation">
                <svg aria-hidden="true" fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3 6H21" stroke="currentColor" stroke-linecap="square" stroke-width="2.25"/>
                    <path d="M3 12H21" stroke="currentColor" stroke-linecap="square" stroke-width="2.25"/>
                    <path d="M3 18H21" stroke="currentColor" stroke-linecap="square" stroke-width="2.25"/>
                </svg>
            </label>
            <aside>
                <nav aria-label="Primary">
                    <h2>Agency</h2>
                    <ul>
                        <li><a href="{{ route('dashboard') }}">Overview</a></li>
                    </ul>
                </nav>
                <nav aria-label="Brands">
                    <h2>Brands</h2>
                    <ul>
                        @foreach ($brands as $brand)
                            <li>
                                <a href="{{ route('brand', $brand) }}">{{ $brand->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </aside>
        </header>
        <main>
            @yield('content')
        </main>
        <footer>
            <p>&copy; {{ date('Y') }} {{ config('app.name', 'Coredrive') }}</p>
        </footer>
    </body>
</html>
