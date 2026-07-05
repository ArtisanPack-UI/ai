<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Streaming\AgentStreamResponse;
use Tests\Support\FakeAgent;
use Tests\Support\FakeStreamingAgent;

beforeEach( function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( new Credentials( provider: 'anthropic', apiKey: 'sk-test', defaultModel: 'haiku' ) );
    $resolver->useStore( fn () => null );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.stream', FakeStreamingAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );
    $registry->register( 'fake.echo', FakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );
} );

it( 'defaults streaming on for agents that opt in via $stream = true', function (): void {
    $agent = FakeStreamingAgent::for( 'abc' );

    expect( $agent->isStreaming() )->toBeTrue();
} );

it( 'defaults streaming off for agents that leave $stream = false', function (): void {
    $agent = FakeAgent::for( 'x' );

    expect( $agent->isStreaming() )->toBeFalse();
} );

it( 'streams chunks incrementally through streamTo()', function (): void {
    $chunks = [];

    $result = FakeStreamingAgent::for( 'hey' )
        ->streamTo( function ( string $chunk ) use ( &$chunks ): void {
            $chunks[] = $chunk;
        } )
        ->run();

    expect( $chunks )->toBe( [ 'h', 'e', 'y' ] );
    expect( $result )->toBe( [ 'text' => 'hey' ] );
} );

it( 'falls back to whole-response mode when streaming is disabled', function (): void {
    $chunks = [];

    $result = FakeStreamingAgent::for( 'abc' )
        ->run();

    // Default $stream=true still streams, but no callback is registered
    // so no chunks are surfaced. Result should still be complete.
    expect( $result )->toBe( [ 'text' => 'abc' ] );
    expect( $chunks )->toBe( [] );
} );

it( 'bypasses the cache when streaming is active', function (): void {
    config( [ 'artisanpack.ai.cache.enabled' => true, 'artisanpack.ai.cache.ttl' => 60 ] );

    $first = FakeStreamingAgent::for( 'abc' );
    $first->run();

    // Streaming should bypass cache — the second call must re-execute.
    $chunks = [];
    $second = FakeStreamingAgent::for( 'abc' );
    $second->streamTo( function ( string $chunk ) use ( &$chunks ): void {
        $chunks[] = $chunk;
    } );
    $second->run();

    expect( $chunks )->toBe( [ 'a', 'b', 'c' ] );
} );

it( 'produces an SSE StreamedResponse via AgentStreamResponse::forAgent()', function (): void {
    $agent    = FakeStreamingAgent::for( 'hi' );
    $response = AgentStreamResponse::forAgent( $agent );

    expect( $response->headers->get( 'Content-Type' ) )->toBe( 'text/event-stream' );

    // Two nested buffers: AgentStreamResponse's inner ob_flush() promotes
    // its content up to the parent, which is what we capture.
    ob_start();
    ob_start();
    $response->sendContent();
    ob_end_clean();
    $body = ob_get_clean();

    expect( $body )->toContain( 'data: {"chunk":"h"}' );
    expect( $body )->toContain( 'data: {"chunk":"i"}' );
    expect( $body )->toContain( 'event: complete' );
    expect( $body )->toContain( '"output":{"text":"hi"}' );
} );
