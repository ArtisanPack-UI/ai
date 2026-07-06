<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Livewire\Admin\FeatureToggles;
use Livewire\Livewire;
use Tests\Support\FakeAgent;

beforeEach( function (): void {
    if ( ! class_exists( Livewire::class ) ) {
        $this->markTestSkipped( 'livewire/livewire is not installed.' );
    }

    $this->createSettingsTable();
    $this->clearFeatureRegistry();

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [
        'package'     => 'artisanpack-ui/ai-fake',
        'label'       => 'Echo agent',
        'description' => 'Bounces the input back.',
    ] );
    $registry->register( 'fake.reverse', FakeAgent::class, [
        'package' => 'artisanpack-ui/ai-fake',
        'label'   => 'Reverse agent',
    ] );
    $registry->register( 'other.summarize', FakeAgent::class, [
        'package' => 'artisanpack-ui/other',
        'label'   => 'Summariser',
    ] );
} );

it( 'lists every registered feature grouped by package', function (): void {
    $component = Livewire::test( FeatureToggles::class );

    $groups = $component->instance()->groupedFeatures;

    expect( $groups )->toHaveCount( 2 );

    $packages = array_column( $groups, 'package' );
    expect( $packages )->toBe( [ 'artisanpack-ui/ai-fake', 'artisanpack-ui/other' ] );

    $fakeGroup = $groups[0];
    expect( $fakeGroup['total_count'] )->toBe( 2 );
    expect( array_column( $fakeGroup['features'], 'key' ) )
        ->toBe( [ 'fake.echo', 'fake.reverse' ] );
} );

it( 'toggles a feature off and persists the state across reloads', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    // Registered features default to enabled; disable to establish a
    // known starting state, then flip via the component.
    $registry->disable( 'fake.echo' );

    expect( $registry->isToggleOn( 'fake.echo' ) )->toBeFalse();

    Livewire::test( FeatureToggles::class )
        ->call( 'toggle', 'fake.echo' )
        ->assertHasNoErrors();

    expect( $registry->isToggleOn( 'fake.echo' ) )->toBeTrue();

    // Simulate a page reload — the toggle store reads from the settings
    // table, so a fresh component mount should see the persisted value.
    $reloaded = Livewire::test( FeatureToggles::class )->instance()->groupedFeatures;
    $echo     = collect( $reloaded[0]['features'] )->firstWhere( 'key', 'fake.echo' );

    expect( $echo['enabled'] )->toBeTrue();
} );

it( 'toggles a feature off when it was already enabled', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->enable( 'fake.echo' );

    Livewire::test( FeatureToggles::class )
        ->call( 'toggle', 'fake.echo' );

    expect( $registry->isToggleOn( 'fake.echo' ) )->toBeFalse();
} );

it( 'reports an error when toggling an unknown feature', function (): void {
    Livewire::test( FeatureToggles::class )
        ->call( 'toggle', 'nope.gone' )
        ->assertSet( 'toast.type', 'error' );
} );

it( 'bulk-enables every feature in a package without touching other packages', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->disable( 'fake.echo' );
    $registry->disable( 'fake.reverse' );
    $registry->disable( 'other.summarize' );

    Livewire::test( FeatureToggles::class )
        ->call( 'enablePackage', 'artisanpack-ui/ai-fake' );

    expect( $registry->isToggleOn( 'fake.echo' ) )->toBeTrue();
    expect( $registry->isToggleOn( 'fake.reverse' ) )->toBeTrue();
    expect( $registry->isToggleOn( 'other.summarize' ) )->toBeFalse();
} );

it( 'bulk-disables every feature in a package', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->enable( 'fake.echo' );
    $registry->enable( 'fake.reverse' );

    Livewire::test( FeatureToggles::class )
        ->call( 'disablePackage', 'artisanpack-ui/ai-fake' );

    expect( $registry->isToggleOn( 'fake.echo' ) )->toBeFalse();
    expect( $registry->isToggleOn( 'fake.reverse' ) )->toBeFalse();
} );

it( 'filters features by search string', function (): void {
    $component = Livewire::test( FeatureToggles::class )
        ->set( 'search', 'summar' );

    $groups = $component->instance()->groupedFeatures;

    expect( $groups )->toHaveCount( 1 );
    expect( $groups[0]['package'] )->toBe( 'artisanpack-ui/other' );
    expect( array_column( $groups[0]['features'], 'key' ) )->toBe( [ 'other.summarize' ] );
} );

it( 'renders the empty state when no features are registered', function (): void {
    // Re-bind an empty registry so we can assert on the empty-state view.
    app()->forgetInstance( FeatureRegistry::class );
    app()->singleton( FeatureRegistry::class, function ( $app ) {
        return new ArtisanPackUI\Ai\Registry\ArrayFeatureRegistry(
            $app,
            $app->make( Illuminate\Contracts\Config\Repository::class ),
            $app->make( ArtisanPackUI\Ai\Contracts\CredentialResolver::class ),
        );
    } );

    Livewire::test( FeatureToggles::class )
        ->assertSee( __( 'No AI features are registered yet. Install a package that ships an agent to see it listed here.' ) );
} );
