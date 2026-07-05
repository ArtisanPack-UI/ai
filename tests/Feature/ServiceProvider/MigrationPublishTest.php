<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\AiServiceProvider;

it( 'does NOT expose a vendor:publish tag for migrations to avoid duplicate-table failures', function (): void {
    $groups = AiServiceProvider::publishableGroups();

    // The migration group would have collided with `loadMigrationsFrom` and
    // caused `Table 'ai_usage_events' already exists` after publish.
    expect( $groups )->not->toContain( 'artisanpack-ai-migrations' );

    // Views and package config remain publishable as intended.
    expect( $groups )->toContain( 'artisanpack-ai-views' );
    expect( $groups )->toContain( 'artisanpack-package-config' );
} );
