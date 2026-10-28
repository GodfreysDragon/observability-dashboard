<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Ledgerline Observability') }}</title>

        <script>
            try {
                const theme = localStorage.getItem('theme');
                if (theme === 'light' || theme === 'dark') document.documentElement.dataset.theme = theme;
            } catch (e) {}
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full bg-page text-ink antialiased">
        <div id="app" class="h-full"></div>
    </body>
</html>
