<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Agents\ContentRewriteAgent;
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

it( 'shortens content and reports a non-zero changed_ratio', function (): void {
    $this->prompter->queue( [
        'rewrite'       => 'Cats sleep a lot.',
        'changed_ratio' => 0.7,
        'rationale'     => 'Reduced two sentences to one.',
    ] );

    $result = ContentRewriteAgent::for( [
        'content' => 'Cats spend a very large portion of their day sleeping. They can nap for up to 16 hours.',
        'intent'  => 'make this shorter',
    ] )->run();

    expect( $result['rewrite'] )->toBe( 'Cats sleep a lot.' );
    expect( $result['changed_ratio'] )->toBeGreaterThan( 0.0 );
    expect( $result['rationale'] )->toBe( 'Reduced two sentences to one.' );
} );

it( 'shifts tone with the requested intent', function (): void {
    $this->prompter->queue( [
        'rewrite'       => 'We are delighted to inform you that your order has been dispatched.',
        'changed_ratio' => 0.45,
        'rationale'     => 'Elevated register to formal business tone.',
    ] );

    $call = ContentRewriteAgent::for( [
        'content' => "Hey! Your stuff's on its way.",
        'intent'  => 'more formal',
    ] )->run();

    expect( $call['rewrite'] )->toContain( 'delighted' );
} );

it( 'returns the input unchanged when the intent does not apply', function (): void {
    $original = 'Cats sleep.';

    $this->prompter->queue( [
        'rewrite'       => $original,
        'changed_ratio' => 0.0,
        'rationale'     => 'Already at the requested reading level.',
    ] );

    $result = ContentRewriteAgent::for( [
        'content' => $original,
        'intent'  => 'reading level 6',
    ] )->run();

    expect( $result['rewrite'] )->toBe( $original );
    expect( $result['changed_ratio'] )->toBe( 0.0 );
} );

it( 'overrides a lying changed_ratio when the rewrite actually differs', function (): void {
    // Model claims 0 change but the rewrite is clearly different — the
    // agent should recompute against the original rather than trust the
    // hallucinated number.
    $this->prompter->queue( [
        'rewrite'       => 'A completely different sentence.',
        'changed_ratio' => 0.0,
        'rationale'     => 'no change',
    ] );

    $result = ContentRewriteAgent::for( [
        'content' => 'The original sentence had nothing in common.',
        'intent'  => 'rewrite this',
    ] )->run();

    expect( $result['changed_ratio'] )->toBeGreaterThan( 0.0 );
} );

it( 'forwards constraints as a distinct message part', function (): void {
    $this->prompter->queue( [
        'rewrite'       => 'Short.',
        'changed_ratio' => 0.9,
        'rationale'     => 'Trimmed as requested.',
    ] );

    ContentRewriteAgent::for( [
        'content'     => 'Something to rewrite here that will be trimmed down aggressively.',
        'intent'      => 'shorten',
        'constraints' => [ 'no more than 3 words', 'preserve the noun' ],
    ] )->run();

    $message        = $this->prompter->calls[0]['message'];
    $constraintPart = collect( $message )->firstWhere(
        fn ( array $part ): bool => str_starts_with( (string) ( $part['text'] ?? '' ), 'Constraints:' ),
    );

    expect( $constraintPart )->not->toBeNull();
    expect( $constraintPart['text'] )->toContain( 'no more than 3 words' );
    expect( $constraintPart['text'] )->toContain( 'preserve the noun' );
} );

it( 'rejects missing content with a FeatureError', function (): void {
    expect( fn () => ContentRewriteAgent::for( [ 'intent' => 'shorten' ] )->run() )
        ->toThrow( FeatureError::class, '`content`' );
} );

it( 'rejects missing intent with a FeatureError', function (): void {
    expect( fn () => ContentRewriteAgent::for( [ 'content' => 'hello' ] )->run() )
        ->toThrow( FeatureError::class, '`intent`' );
} );

it( 'rejects a non-array input with a FeatureError', function (): void {
    expect( fn () => ContentRewriteAgent::for( 'raw string' )->run() )
        ->toThrow( FeatureError::class, 'must be an array' );
} );
