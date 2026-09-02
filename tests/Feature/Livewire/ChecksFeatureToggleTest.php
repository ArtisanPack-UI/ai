<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\Support\FeatureToggleComponent;

beforeEach( function (): void {
    if ( ! class_exists( Livewire::class ) ) {
        $this->markTestSkipped( 'livewire/livewire is not installed.' );
    }

    $this->createSettingsTable();
    $this->clearFeatureRegistry();
} );

it( 'fails closed when the feature key was never registered', function (): void {
    // Registry intentionally left empty for `fake.echo`.
    $component = Livewire::test( FeatureToggleComponent::class );

    expect( $component->instance()->isEnabled )->toBeFalse();
    $component->assertSee( 'feature-off' );
} );

it( 'reports disabled when the feature is registered but toggled off', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [
        'package' => 'artisanpack-ui/ai-fake',
    ] );
    $registry->disable( 'fake.echo' );

    $component = Livewire::test( FeatureToggleComponent::class );

    expect( $component->instance()->isEnabled )->toBeFalse();
    $component->assertSee( 'feature-off' );
} );

it( 'reports enabled when the feature is registered and toggled on', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [
        'package' => 'artisanpack-ui/ai-fake',
    ] );
    $registry->enable( 'fake.echo' );

    $component = Livewire::test( FeatureToggleComponent::class );

    expect( $component->instance()->isEnabled )->toBeTrue();
    $component->assertSee( 'feature-on' );
} );
