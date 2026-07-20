<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use Tests\Support\FakeAgent;

it( 'fires both old-name and new-name subscribers when features are registered', function (): void {
    $calls = [];

    addFilter(
        'ap.ai.register-features',
        function ( FeatureRegistry $registry ) use ( &$calls ) {
            $calls[] = 'old';
            $registry->register(
                'legacy.feature',
                FakeAgent::class,
                [ 'package' => 'artisanpack-ui/legacy' ],
            );

            return $registry;
        },
    );

    addFilter(
        'ap.ai.registerFeatures',
        function ( FeatureRegistry $registry ) use ( &$calls ) {
            $calls[] = 'new';
            $registry->register(
                'modern.feature',
                FakeAgent::class,
                [ 'package' => 'artisanpack-ui/modern' ],
            );

            return $registry;
        },
    );

    // Re-run boot so the filter fires against the actual registry.
    ( new ArtisanPackUI\Ai\AiServiceProvider( app() ) )->boot();

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    expect( $calls )->toContain( 'old' );
    expect( $calls )->toContain( 'new' );
    expect( $registry->get( 'legacy.feature' ) )->not->toBeNull();
    expect( $registry->get( 'modern.feature' ) )->not->toBeNull();
} );
