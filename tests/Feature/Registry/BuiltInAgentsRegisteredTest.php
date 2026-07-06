<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Agents\AltTextGenerationAgent;
use ArtisanPackUI\Ai\Agents\ContentRewriteAgent;
use ArtisanPackUI\Ai\Agents\SummarizationAgent;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;

/**
 * Guards {@see ArtisanPackUI\Ai\AiServiceProvider::aiFeatures()} — the
 * three shipped cross-cutting agents must show up in the registry the same
 * way third-party agents do, so downstream packages (media-library,
 * visual-editor, cms-framework, analytics) can consume them by feature key
 * without needing to know they live in the ai package.
 *
 * Runs against the real service-provider boot, not a clearFeatureRegistry()
 * beforeEach — the whole point of this test is that the boot registers
 * them.
 */

it( 'registers ai.alt_text pointing at AltTextGenerationAgent', function (): void {
    $registry = app( FeatureRegistry::class );

    $definition = $registry->get( 'ai.alt_text' );

    expect( $definition )->not->toBeNull();
    expect( $definition->agentClass )->toBe( AltTextGenerationAgent::class );
    expect( $definition->package )->toBe( 'artisanpack-ui/ai' );
} );

it( 'registers ai.content_rewrite pointing at ContentRewriteAgent', function (): void {
    $registry = app( FeatureRegistry::class );

    $definition = $registry->get( 'ai.content_rewrite' );

    expect( $definition )->not->toBeNull();
    expect( $definition->agentClass )->toBe( ContentRewriteAgent::class );
    expect( $definition->package )->toBe( 'artisanpack-ui/ai' );
} );

it( 'registers ai.summarize pointing at SummarizationAgent', function (): void {
    $registry = app( FeatureRegistry::class );

    $definition = $registry->get( 'ai.summarize' );

    expect( $definition )->not->toBeNull();
    expect( $definition->agentClass )->toBe( SummarizationAgent::class );
    expect( $definition->package )->toBe( 'artisanpack-ui/ai' );
} );
