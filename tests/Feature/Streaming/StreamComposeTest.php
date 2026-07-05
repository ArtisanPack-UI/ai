<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Streaming\AgentStreamResponse;
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

it( 'preserves a caller-registered streamTo() callback when wrapped by AgentStreamResponse', function (): void {
    $auditChunks = [];

    $agent = FakeStreamingAgent::for( 'hi' )
        ->streamTo( function ( string $chunk ) use ( &$auditChunks ): void {
            $auditChunks[] = $chunk;
        } );

    $response = AgentStreamResponse::forAgent( $agent );

    ob_start();
    ob_start();
    $response->sendContent();
    ob_end_clean();
    $body = ob_get_clean();

    // SSE payload landed as before.
    expect( $body )->toContain( '"chunk":"h"' );
    expect( $body )->toContain( '"chunk":"i"' );

    // AND the caller's original callback still received each chunk.
    expect( $auditChunks )->toBe( [ 'h', 'i' ] );
} );
