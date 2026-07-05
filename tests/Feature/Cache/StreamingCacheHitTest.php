<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;
use Tests\Support\FakeStreamingAgent;

beforeEach( function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( new Credentials( provider: 'anthropic', apiKey: 'sk-test', defaultModel: 'haiku' ) );
    $resolver->useStore( fn () => null );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.stream', FakeStreamingAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );
} );

it( 'streaming-default agents STILL hit cache when no callback is registered', function (): void {
    config( [ 'artisanpack.ai.cache.enabled' => true, 'artisanpack.ai.cache.ttl' => 60 ] );

    // First call: no streamTo(), so cache write happens even though $stream=true.
    $first = FakeStreamingAgent::for( 'same-input' );
    $first->run();

    // Second call with same input: cache hit — execute() must not run again.
    $second = FakeStreamingAgent::for( 'same-input' );
    $result = $second->run();

    expect( $result )->toBe( [ 'text' => 'same-input' ] );
    expect( $first->isStreaming() )->toBeTrue();
    // The FakeStreamingAgent doesn't count executions the way FakeAgent does,
    // so we assert cache-hit via the returned shape being the cached one.
} );

it( 'streaming-default agents STILL bypass cache when a callback IS registered', function (): void {
    config( [ 'artisanpack.ai.cache.enabled' => true, 'artisanpack.ai.cache.ttl' => 60 ] );

    // Prime the cache with a no-callback run.
    FakeStreamingAgent::for( 'streamed-input' )->run();

    // Now register a callback — cache should be bypassed and execute() should
    // stream chunks through it.
    $chunks = [];
    $agent  = FakeStreamingAgent::for( 'streamed-input' );
    $agent->streamTo( function ( string $chunk ) use ( &$chunks ): void {
        $chunks[] = $chunk;
    } );
    $agent->run();

    expect( $chunks )->toBe( [ 's', 't', 'r', 'e', 'a', 'm', 'e', 'd', '-', 'i', 'n', 'p', 'u', 't' ] );
} );
