<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>Laravel Vue SPA</title>
        <meta name="base-url" content="{{ url('/') }}">
        <!-- Styles -->
        <link href="css/app.css?v={{ filemtime(public_path('css/app.css')) }}" rel="stylesheet">
    </head>
    <body>
        <div id="app"></div>

        <!-- Scripts -->
        <script src="js/app.js?v={{ filemtime(public_path('js/app.js')) }}"></script>
    </body>
</html>
