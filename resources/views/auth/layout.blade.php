<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-app-env="{{ config('app.env') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.2fa.confirm') }} | {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ mix('blade-app/app.css') }}">
</head>
<body>

<header class="container">
    <nav>
        <ul>
            <img src="{{ secure_url('/baander-logo.svg') }}" style="width: 72px" alt="Logo" />
            <li><strong>{{config('app.name')}}</strong></li>
        </ul>
        <ul>
            <li><a href="{{ $baanderLinks['github'] }}"><x-github-icon /></a></li>
            <li><a href="{{ $baanderLinks['website'] }}">baander.app</a></li>
        </ul>
    </nav>
</header>

<main class="container">
    @yield('content')
</main>

<footer class="container">
    <nav style="display: flex; justify-content: center;">
        <ul>
            <li>
                <details class="dropdown">
                    <summary role="button" class="secondary">Theme</summary>
                    <ul>
                        <li><a href="#" data-theme-switcher="auto">Auto</a></li>
                        <li><a href="#" data-theme-switcher="light">Light</a></li>
                        <li><a href="#" data-theme-switcher="dark">Dark</a></li>
                    </ul>
                </details>
            </li>
        </ul>
    </nav>
</footer>

<script src="{{ mix('blade-app/app.js') }}"></script>
</body>
</html>