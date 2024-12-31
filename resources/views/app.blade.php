<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-app-env="{{ config('app.env') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <link rel="apple-touch-icon" sizes="180x180" href="{{ secure_url('/apple-touch-icon.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ secure_url('/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ secure_url('/favicon-16x16.png') }}">
    <link rel="manifest" href="{{ secure_url('/manifest.json')  }}">
    <link rel="mask-icon" href="{{ secure_url('/safari-pinned-tab.svg') }}" color="#414141">
    <meta name="msapplication-TileColor" content="#414141">
    <meta name="theme-color" content="#ffffff">
    <meta property="csp-nonce" content="{{ Vite::cspNonce() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if(app()->isLocal())
        @viteReactRefresh
    @endif
    @vite("resources/app/index.tsx")
    @routes(nonce: Vite::cspNonce())
    <script nonce="{{ Vite::cspNonce() }}">
      Ziggy.url = '{{ config('app.url') }}'
    </script>
    <script nonce="{{ Vite::cspNonce() }}">
      window.BaanderAppInfo = @js($appInfo)
    </script>
    <style>
        .clockwork-toolbar {
            display: none !important;
        }
    </style>
</head>
<body>
<div id="baanderapproot"></div>
</body>
</html>
