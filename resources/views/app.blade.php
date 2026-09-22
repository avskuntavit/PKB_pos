<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title inertia>{{ config('app.name', 'PKB POS') }}</title>

    {{-- ไอคอนแท็บเบราว์เซอร์ — เส้นทาง relative ตั้งใจ ดูเหตุผลใน config/pos.php --}}
    <link rel="icon" type="image/x-icon" href="{{ config('pos.brand.favicon') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ config('pos.brand.logo') }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=noto-sans-thai:400,500,600,700" rel="stylesheet">

    @routes
    @vite(['resources/js/app.ts'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
