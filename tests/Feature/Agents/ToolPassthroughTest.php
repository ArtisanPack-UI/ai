<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Agents\SummarizationAgent;
use ArtisanPackUI\Ai\Contracts\AgentPrompter;
use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Testing\FakeAgentPrompter;
use Laravel\Ai\Tools\SimilaritySearch;

/**
 * Guards the tool-passthrough seam added for Keystone's read-tool registry
 * (#52): tools registered on an agent via `withTools()` must reach the
 * prompter, and must reset between runs so a container-singleton agent can't
 * leak run N-1's tools into run N.
 *
 * The final case pins the RAG retrieval path Keystone's Phase 2 depends on
 * (§8.6, #54): laravel/ai's `SimilaritySearch` tool is a plain laravel/ai
 * tool, so it rides the same `withTools()` seam through to the model without
 * the wrapper needing a bespoke embeddings/vector-store surface.
 */

beforeEach( function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride(
        new Credentials( provider: 'anthropic', apiKey: 'sk-test', defaultModel: 'claude-haiku-4-5' ),
    );
    $resolver->useStore( fn () => null );

    $this->prompter = new FakeAgentPrompter();
    $this->app->instance( AgentPrompter::class, $this->prompter );
} );

it( 'forwards tools registered via withTools() through to the prompter', function (): void {
    $this->prompter->queue( [ 'summary' => 'x', 'key_points' => [], 'caveats' => [] ] );

    SummarizationAgent::for( [ 'items' => [ 'a' ] ] )
        ->withTools( [ 'App\\Tools\\ReadPost', 'App\\Tools\\ReadPage' ] )
        ->run();

    expect( $this->prompter->calls )->toHaveCount( 1 );
    expect( $this->prompter->calls[0]['tools'] )->toBe( [ 'App\\Tools\\ReadPost', 'App\\Tools\\ReadPage' ] );
} );

it( 'defaults to an empty tool list when withTools() is never called', function (): void {
    $this->prompter->queue( [ 'summary' => 'x', 'key_points' => [], 'caveats' => [] ] );

    SummarizationAgent::for( [ 'items' => [ 'a' ] ] )->run();

    expect( $this->prompter->calls[0]['tools'] )->toBe( [] );
} );

it( 're-indexes a sparse tool array so laravel/ai receives a flat list', function (): void {
    $this->prompter->queue( [ 'summary' => 'x', 'key_points' => [], 'caveats' => [] ] );

    SummarizationAgent::for( [ 'items' => [ 'a' ] ] )
        ->withTools( [ 5 => 'App\\Tools\\ReadPost', 9 => 'App\\Tools\\ReadPage' ] )
        ->run();

    expect( $this->prompter->calls[0]['tools'] )->toBe( [ 'App\\Tools\\ReadPost', 'App\\Tools\\ReadPage' ] );
} );

it( 'resets tools between runs so a reused agent binding cannot leak them', function (): void {
    $this->prompter->queue( [ 'summary' => 'x', 'key_points' => [], 'caveats' => [] ] );
    $this->prompter->queue( [ 'summary' => 'y', 'key_points' => [], 'caveats' => [] ] );

    // Bind the agent as a singleton so both runs share one instance — the
    // documented override path where a stale field would otherwise leak.
    $this->app->singleton( SummarizationAgent::class );

    SummarizationAgent::for( [ 'items' => [ 'a' ] ] )
        ->withTools( [ 'App\\Tools\\ReadPost' ] )
        ->run();

    SummarizationAgent::for( [ 'items' => [ 'b' ] ] )->run();

    expect( $this->prompter->calls[0]['tools'] )->toBe( [ 'App\\Tools\\ReadPost' ] );
    expect( $this->prompter->calls[1]['tools'] )->toBe( [] );
} );

it( 'forwards a laravel/ai SimilaritySearch tool through the seam for RAG retrieval', function (): void {
    $this->prompter->queue( [ 'summary' => 'x', 'key_points' => [], 'caveats' => [] ] );

    $retrieval = SimilaritySearch::usingModel( 'App\\Models\\SiteContent', 'embedding' );

    SummarizationAgent::for( [ 'items' => [ 'a' ] ] )
        ->withTools( [ $retrieval ] )
        ->run();

    expect( $this->prompter->calls[0]['tools'] )->toBe( [ $retrieval ] );
    expect( $this->prompter->calls[0]['tools'][0] )->toBeInstanceOf( SimilaritySearch::class );
} );
