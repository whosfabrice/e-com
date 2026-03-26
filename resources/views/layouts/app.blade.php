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
            <h1><span>{{ config('app.name', 'Laravel') }}</span><i aria-hidden="true"></i></h1>
            <input id="hamburger" type="checkbox">
            <label for="hamburger" aria-label="Toggle navigation">
                <svg aria-hidden="true" fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3 6H21" stroke="currentColor" stroke-linecap="square" stroke-width="2.25"/>
                    <path d="M3 12H21" stroke="currentColor" stroke-linecap="square" stroke-width="2.25"/>
                    <path d="M3 18H21" stroke="currentColor" stroke-linecap="square" stroke-width="2.25"/>
                </svg>
            </label>
            <button aria-label="Toggle color theme" id="theme-toggle" type="button">
                <svg class="theme-icon theme-icon-moon" aria-hidden="true" fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z" stroke="currentColor" stroke-width="1.8"/>
                </svg>
                <svg class="theme-icon theme-icon-sun" aria-hidden="true" fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="12" cy="12" r="4.25" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M12 2.5V5.25M12 18.75V21.5M21.5 12H18.75M5.25 12H2.5M18.72 5.28 16.77 7.23M7.23 16.77 5.28 18.72M18.72 18.72 16.77 16.77M7.23 7.23 5.28 5.28" stroke="currentColor" stroke-linecap="square" stroke-width="1.8"/>
                </svg>
            </button>
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
