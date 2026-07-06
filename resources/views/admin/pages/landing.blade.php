<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __( 'AI' ) }}</title>
    @vite( [ 'resources/css/app.css', 'resources/js/app.js' ] )
</head>
<body class="bg-base-200 min-h-screen">
    <div class="container mx-auto max-w-3xl space-y-4 p-6">
        <nav class="text-sm opacity-70">
            <a href="{{ url( '/admin/dashboard' ) }}" class="link">{{ __( 'Admin' ) }}</a>
            &rsaquo;
            <span>{{ __( 'AI' ) }}</span>
        </nav>

        <h1 class="text-2xl font-bold">{{ __( 'AI' ) }}</h1>

        <p class="opacity-80">
            {{ __( 'Configure the shared AI foundation and inspect usage. Pick a section from below.' ) }}
        </p>

        <ul class="list-disc space-y-1 pl-6">
            <li>
                <a href="{{ route( 'admin.packages.ai.settings' ) }}" class="link link-primary">
                    {{ __( 'Settings — provider, credentials, per-feature overrides' ) }}
                </a>
            </li>
            <li>
                <a href="{{ route( 'admin.packages.ai.usage' ) }}" class="link link-primary">
                    {{ __( 'Usage dashboard — tokens, cost, per-feature breakdown' ) }}
                </a>
            </li>
            <li>
                <a href="{{ route( 'admin.packages.ai.features' ) }}" class="link link-primary">
                    {{ __( 'Features — toggle each registered agent on or off' ) }}
                </a>
            </li>
        </ul>
    </div>
</body>
</html>
