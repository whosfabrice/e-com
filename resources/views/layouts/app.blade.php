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
            <label for="hamburger">Menu</label>
            <aside>
                <section>
                    <h2>Agency</h2>
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                </section>
                <section>
                    <h2>Brands</h2>
                    <ul>
                        @foreach ($brands as $brand)
                            <li>
                                <a href="{{ route('brand', $brand) }}">{{ $brand->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
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
