@extends( 'cms-framework::admin.layouts.app' )

@section( 'title', __( 'AI' ) )

@section( 'content' )
    <div class="space-y-4 p-6">
        <h1 class="text-2xl font-bold">{{ __( 'AI' ) }}</h1>

        <p class="opacity-80">
            {{ __( 'Configure the shared AI foundation and inspect usage. Pick a section from the sub-menu.' ) }}
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
        </ul>
    </div>
@endsection
