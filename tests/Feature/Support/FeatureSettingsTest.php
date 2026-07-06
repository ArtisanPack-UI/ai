<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Support\FeatureSettings;
use Illuminate\Support\Facades\DB;

beforeEach( function (): void {
    $this->createSettingsTable();

    /** @var FeatureSettings $settings */
    $settings = app( FeatureSettings::class );
    $settings->resetSettingsTableProbe();
} );

it( 'round-trips model + instructions overrides', function (): void {
    /** @var FeatureSettings $settings */
    $settings = app( FeatureSettings::class );

    $settings->setModel( 'seo.summary', 'sonnet' );
    $settings->setInstructions( 'seo.summary', 'Summarise briefly.' );

    expect( $settings->model( 'seo.summary' ) )->toBe( 'sonnet' );
    expect( $settings->instructions( 'seo.summary' ) )->toBe( 'Summarise briefly.' );
} );

it( 'clears a value when null is passed to the setter', function (): void {
    /** @var FeatureSettings $settings */
    $settings = app( FeatureSettings::class );

    $settings->setModel( 'seo.summary', 'sonnet' );
    $settings->setModel( 'seo.summary', null );

    expect( $settings->model( 'seo.summary' ) )->toBeNull();
} );

it( 'all() returns every stored feature override in one call', function (): void {
    /** @var FeatureSettings $settings */
    $settings = app( FeatureSettings::class );

    $settings->setModel( 'a.x', 'haiku' );
    $settings->setInstructions( 'a.x', 'foo' );
    $settings->setModel( 'b.y', 'sonnet' );

    $all = $settings->all();

    expect( $all )->toHaveKey( 'a.x' );
    expect( $all['a.x']['model'] )->toBe( 'haiku' );
    expect( $all['a.x']['instructions'] )->toBe( 'foo' );
    expect( $all )->toHaveKey( 'b.y' );
    expect( $all['b.y']['model'] )->toBe( 'sonnet' );
} );

it( 'all() does not match sibling namespaces despite the underscore in the prefix', function (): void {
    /** @var FeatureSettings $settings */
    $settings = app( FeatureSettings::class );

    // Legitimate override under the real prefix.
    $settings->setModel( 'legit.feature', 'sonnet' );

    // Collision candidates. `_` is a LIKE single-char wildcard, so without
    // ESCAPE these would all be matched.
    DB::table( 'settings' )->insert( [
        [ 'key' => 'aiXfeaturesY.foo.model', 'value' => 'DO-NOT-RETURN', 'type' => 'string' ],
        [ 'key' => 'ai.features.foo.model',  'value' => 'DO-NOT-RETURN', 'type' => 'string' ],
        [ 'key' => 'ai-features.foo.model',  'value' => 'DO-NOT-RETURN', 'type' => 'string' ],
    ] );

    $all = $settings->all();

    expect( array_keys( $all ) )->toBe( [ 'legit.feature' ] );
    foreach ( $all as $override ) {
        expect( $override['model'] )->not->toBe( 'DO-NOT-RETURN' );
    }
} );
