<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __( 'AI Features' ) }}</title>
    @vite( [ 'resources/css/app.css', 'resources/js/app.js' ] )
    @livewireStyles
</head>
<body class="bg-base-200 min-h-screen">
    <div class="container mx-auto max-w-5xl p-6">
        <nav class="mb-4 text-sm opacity-70">
            <a href="{{ url( '/admin/dashboard' ) }}" class="link">{{ __( 'Admin' ) }}</a>
            &rsaquo;
            <a href="{{ url( '/admin/packages/ai' ) }}" class="link">{{ __( 'AI' ) }}</a>
            &rsaquo;
            <span>{{ __( 'Features' ) }}</span>
        </nav>

        <h1 class="mb-4 text-2xl font-bold">{{ __( 'AI Features' ) }}</h1>

        <livewire:artisanpack-ai.admin.features />
    </div>

    @livewireScripts
</body>
</html>
