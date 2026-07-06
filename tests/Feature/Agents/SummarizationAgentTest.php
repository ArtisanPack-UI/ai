<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Agents\SummarizationAgent;
use ArtisanPackUI\Ai\Contracts\AgentPrompter;
use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Exceptions\FeatureError;
use Tests\Support\FakeAgentPrompter;

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

it( 'short-circuits on empty input without calling the prompter', function (): void {
    $result = SummarizationAgent::for( [ 'items' => [] ] )->run();

    expect( $result )->toBe( [
        'summary'    => 'No items to summarize.',
        'key_points' => [],
        'caveats'    => [ 'input list was empty' ],
    ] );

    expect( $this->prompter->calls )->toBeEmpty();
} );

it( 'runs end-to-end and returns the shaped output for a non-empty input', function (): void {
    $this->prompter->queue( [
        'summary'    => 'Errors trended upward across the release window.',
        'key_points' => [ 'Error rate doubled after v2 deploy', '2 incidents resolved via rollback' ],
        'caveats'    => [ 'sample size was small' ],
    ] );

    $result = SummarizationAgent::for( [
        'items' => [
            [ 'ts' => '2026-07-01', 'msg' => 'deploy v2' ],
            [ 'ts' => '2026-07-02', 'msg' => 'error spike' ],
            [ 'ts' => '2026-07-03', 'msg' => 'rollback' ],
        ],
    ] )->run();

    expect( $result['summary'] )->toBe( 'Errors trended upward across the release window.' );
    expect( $result['key_points'] )->toHaveCount( 2 );
    expect( $result['caveats'] )->toBe( [ 'sample size was small' ] );
} );

it( 'clamps key_points to 3 entries for length=brief', function (): void {
    $this->prompter->queue( [
        'summary'    => 'x',
        'key_points' => [ 'a', 'b', 'c', 'd', 'e' ],
        'caveats'    => [],
    ] );

    $result = SummarizationAgent::for( [
        'items'  => [ 'a', 'b', 'c' ],
        'length' => 'brief',
    ] )->run();

    expect( $result['key_points'] )->toBe( [ 'a', 'b', 'c' ] );
} );

it( 'allows up to 7 key_points for length=detailed', function (): void {
    $this->prompter->queue( [
        'summary'    => 'x',
        'key_points' => [ 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i' ],
        'caveats'    => [],
    ] );

    $result = SummarizationAgent::for( [
        'items'  => [ 'a' ],
        'length' => 'detailed',
    ] )->run();

    expect( $result['key_points'] )->toHaveCount( 7 );
} );

it( 'forwards focus into the prompter message when supplied', function (): void {
    $this->prompter->queue( [
        'summary'    => 'ok',
        'key_points' => [],
        'caveats'    => [],
    ] );

    SummarizationAgent::for( [
        'items' => [ 'x' ],
        'focus' => 'user impact',
    ] )->run();

    $message   = $this->prompter->calls[0]['message'];
    $focusPart = collect( $message )->firstWhere(
        fn ( array $part ): bool => str_starts_with( (string) ( $part['text'] ?? '' ), 'Focus:' ),
    );

    expect( $focusPart )->not->toBeNull();
    expect( $focusPart['text'] )->toContain( 'user impact' );
} );

it( 'is subclassable — subclass overrides declare their own feature key + package', function (): void {
    $subclass = new class extends SummarizationAgent {
        public string $featureKey = 'analytics.weekly_digest';

        public string $package    = 'artisanpack-ui/analytics';
    };

    expect( $subclass->featureKey )->toBe( 'analytics.weekly_digest' );
    expect( $subclass->package )->toBe( 'artisanpack-ui/analytics' );
    // Inherited surface stays intact.
    expect( $subclass->outputSchema() )->toHaveKey( 'properties.summary' );
} );

it( 'rejects a non-array input with a FeatureError', function (): void {
    expect( fn () => SummarizationAgent::for( 'raw' )->run() )
        ->toThrow( FeatureError::class, 'must be an array' );
} );

it( 'rejects unserialisable items rather than shipping null values to the model', function (): void {
    // A resource can't be JSON-encoded — before this rejection the run
    // used JSON_PARTIAL_OUTPUT_ON_ERROR and would silently ship `null`
    // to the model.
    $resource = fopen( 'php://temp', 'r' );

    try {
        expect( fn () => SummarizationAgent::for( [ 'items' => [ $resource ] ] )->run() )
            ->toThrow( FeatureError::class, 'items could not be serialized' );
    } finally {
        fclose( $resource );
    }
} );

it( 'rejects missing items key with a FeatureError', function (): void {
    expect( fn () => SummarizationAgent::for( [ 'focus' => 'x' ] )->run() )
        ->toThrow( FeatureError::class, '`items` must be an array' );
} );
