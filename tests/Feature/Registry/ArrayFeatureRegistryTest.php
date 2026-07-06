<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Events\FeatureDisabled;
use ArtisanPackUI\Ai\Events\FeatureEnabled;
use ArtisanPackUI\Ai\Events\FeatureRegistered;
use ArtisanPackUI\Ai\Registry\FeatureDefinition;
use Illuminate\Support\Facades\Event;

beforeEach( function (): void {
    foreach ( [
        'ARTISANPACK_AI_PROVIDER',
        'ARTISANPACK_AI_API_KEY',
        'ARTISANPACK_AI_DEFAULT_MODEL',
        'ARTISANPACK_AI_BASE_URL',
    ] as $key ) {
        putenv( $key );
        unset( $_ENV[ $key ], $_SERVER[ $key ] );
    }

    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( new Credentials( provider: 'anthropic', apiKey: 'sk-test' ) );
} );

it( 'registers and retrieves features', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    $registry->register( 'seo.suggest_meta', SomeAgentStub::class, [ 'package' => 'artisanpack-ui/seo' ] );

    $definition = $registry->get( 'seo.suggest_meta' );

    expect( $definition )->toBeInstanceOf( FeatureDefinition::class );
    expect( $definition->agentClass )->toBe( SomeAgentStub::class );
    expect( $definition->package )->toBe( 'artisanpack-ui/seo' );
} );

it( 'orders features deterministically by package then key', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    $registry->register( 'z.feature', SomeAgentStub::class, [ 'package' => 'artisanpack-ui/z' ] );
    $registry->register( 'a.feature', SomeAgentStub::class, [ 'package' => 'artisanpack-ui/a' ] );
    $registry->register( 'a.other', SomeAgentStub::class, [ 'package' => 'artisanpack-ui/a' ] );

    // Filter to the features this test registered — the ai package
    // auto-registers its own cross-cutting agents (ai.alt_text, etc.) via
    // `aiFeatures()` so `->all()` includes them too. This test only cares
    // that the sort key is `[package, featureKey]`, exercised on features
    // this test controls.
    $ownKeys = [ 'a.feature', 'a.other', 'z.feature' ];
    $order   = $registry->all()
        ->map( fn ( FeatureDefinition $d ): string => $d->featureKey )
        ->filter( fn ( string $key ): bool => in_array( $key, $ownKeys, true ) )
        ->values()
        ->all();

    expect( $order )->toBe( [ 'a.feature', 'a.other', 'z.feature' ] );
} );

it( 'emits events on register, enable, and disable', function (): void {
    Event::fake( [ FeatureRegistered::class, FeatureEnabled::class, FeatureDisabled::class ] );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    $registry->register( 'foo.bar', SomeAgentStub::class );
    $registry->enable( 'foo.bar' );
    $registry->disable( 'foo.bar' );

    Event::assertDispatched( FeatureRegistered::class );
    Event::assertDispatched( FeatureEnabled::class );
    Event::assertDispatched( FeatureDisabled::class );
} );

it( 'short-circuits isEnabled when credentials are missing', function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( null );
    $resolver->useStore( fn () => null );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    $registry->register( 'foo.bar', SomeAgentStub::class );

    expect( $registry->isEnabled( 'foo.bar' ) )->toBeFalse();
} );

it( 'reports isEnabled=false for unregistered features', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    expect( $registry->isEnabled( 'never.registered' ) )->toBeFalse();
} );

it( 'persists toggle state via the bound store', function (): void {
    $storage = [];

    /** @var ArtisanPackUI\Ai\Registry\ArrayFeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'foo.bar', SomeAgentStub::class );

    $registry->useToggleStore(
        function ( string $key ) use ( &$storage ) {
            return $storage[ $key ] ?? null;
        },
        function ( string $key, bool $enabled ) use ( &$storage ): void {
            $storage[ $key ] = $enabled;
        },
    );

    $registry->disable( 'foo.bar' );
    expect( $storage['foo.bar'] )->toBeFalse();
    expect( $registry->isEnabled( 'foo.bar' ) )->toBeFalse();

    $registry->enable( 'foo.bar' );
    expect( $storage['foo.bar'] )->toBeTrue();
    expect( $registry->isEnabled( 'foo.bar' ) )->toBeTrue();
} );

it( 'silently ignores re-registering the same agent under the same key', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    $registry->register( 'foo.bar', SomeAgentStub::class );
    $registry->register( 'foo.bar', SomeAgentStub::class );

    expect( $registry->get( 'foo.bar' )->agentClass )->toBe( SomeAgentStub::class );
} );

it( 'throws when a duplicate feature key is claimed by a different agent class', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    $registry->register( 'foo.bar', SomeAgentStub::class );

    expect( fn () => $registry->register( 'foo.bar', OtherAgentStub::class ) )
        ->toThrow( LogicException::class );
} );

it( 'reads dot-notation feature toggles from config with a literal-key lookup', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    $registry->register( 'seo.suggest_meta_description', SomeAgentStub::class );

    config( [ 'artisanpack.ai.features' => [ 'seo.suggest_meta_description' => [ 'enabled' => false ] ] ] );

    expect( $registry->isToggleOn( 'seo.suggest_meta_description' ) )->toBeFalse();

    config( [ 'artisanpack.ai.features' => [ 'seo.suggest_meta_description' => [ 'enabled' => true ] ] ] );

    expect( $registry->isToggleOn( 'seo.suggest_meta_description' ) )->toBeTrue();
} );

class SomeAgentStub
{
}

class OtherAgentStub
{
}
