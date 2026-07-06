<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;
use Tests\Support\FakeAgent;

beforeEach( function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( new Credentials( provider: 'anthropic', apiKey: 'sk-test', defaultModel: 'haiku' ) );
    $resolver->useStore( fn () => null );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );
} );

it( 'resets streamCallback, credentialOverride, and modelOverride on every for() call', function (): void {
    // Bind as a singleton so the same instance is reused — the case that
    // used to leak state between calls.
    app()->singleton( FakeAgent::class, fn () => new FakeAgent() );

    $runOneChunks = [];
    $agent        = FakeAgent::for( 'first' )
        ->withCredentials( new Credentials( provider: 'openai', apiKey: 'sk-runtime', defaultModel: 'gpt-4o' ) )
        ->withModel( 'sonnet' )
        ->streamTo( function ( string $chunk ) use ( &$runOneChunks ): void {
            $runOneChunks[] = $chunk;
        } );

    $agent->run();

    // Second call reuses the singleton — all runtime overrides must reset.
    $reused = FakeAgent::for( 'second' );

    expect( $reused->streamCallback() )->toBeNull();
    expect( $reused->isStreaming() )->toBeFalse();

    // Threading through: the resolved credentials / model on the second run
    // should come from the resolver, not the first call's overrides.
    // We assert this indirectly via the reflection accessors, since the
    // agent's internal properties are protected.
    $reflection = new ReflectionClass( $reused );

    expect( $reflection->getProperty( 'credentialOverride' )->getValue( $reused ) )->toBeNull();
    expect( $reflection->getProperty( 'modelOverride' )->getValue( $reused ) )->toBeNull();
} );
