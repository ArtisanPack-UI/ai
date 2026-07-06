<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Agents\AltTextGenerationAgent;
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

it( 'passes an image URL through as a laravel/ai attachment part', function (): void {
    $this->prompter->queue( [
        'alt_text'   => 'Sunset over the Rocky Mountains',
        'confidence' => 0.92,
        'warnings'   => [],
    ] );

    $result = AltTextGenerationAgent::for( 'https://example.com/photo.jpg' )->run();

    expect( $result )->toBe( [
        'alt_text'   => 'Sunset over the Rocky Mountains',
        'confidence' => 0.92,
        'warnings'   => [],
    ] );

    // The prompter should have received a structured message containing
    // the image URL as a typed part alongside the guiding text prompt.
    $call = $this->prompter->calls[0];
    expect( $call['message'] )->toBeArray()->and( $call['message'] )->toHaveCount( 2 );
    expect( $call['message'][1] )->toBe( [
        'type'   => 'image',
        'source' => 'url',
        'value'  => 'https://example.com/photo.jpg',
    ] );
} );

it( 'detects a local filesystem path input', function (): void {
    $tmp = tempnam( sys_get_temp_dir(), 'alt-text-test-' );
    file_put_contents( $tmp, 'not-really-an-image' );

    $this->prompter->queue( [
        'alt_text'   => 'Placeholder image',
        'confidence' => 0.4,
        'warnings'   => [ 'image is very small' ],
    ] );

    AltTextGenerationAgent::for( $tmp )->run();

    expect( $this->prompter->calls[0]['message'][1] )->toBe( [
        'type'   => 'image',
        'source' => 'path',
        'value'  => $tmp,
    ] );

    unlink( $tmp );
} );

it( 'clamps overlong alt text down to 150 characters', function (): void {
    $this->prompter->queue( [
        'alt_text'   => str_repeat( 'x', 200 ),
        'confidence' => 0.5,
        'warnings'   => [],
    ] );

    $result = AltTextGenerationAgent::for( 'https://example.com/x.jpg' )->run();

    expect( strlen( $result['alt_text'] ) )->toBe( 150 );
} );

it( 'clamps confidence into the [0, 1] range', function (): void {
    $this->prompter->queue( [
        'alt_text'   => 'anything',
        'confidence' => 5.0,
        'warnings'   => [],
    ] );

    $result = AltTextGenerationAgent::for( 'https://example.com/x.jpg' )->run();
    expect( $result['confidence'] )->toBe( 1.0 );

    $this->prompter->queue( [
        'alt_text'   => 'anything',
        'confidence' => -3.0,
        'warnings'   => [],
    ] );

    $result = AltTextGenerationAgent::for( 'https://example.com/x.jpg' )->run();
    expect( $result['confidence'] )->toBe( 0.0 );
} );

it( 'rejects an unreadable file path with a FeatureError', function (): void {
    expect(
        fn () => AltTextGenerationAgent::for( '/nope/does/not/exist.jpg' )->run(),
    )->toThrow( FeatureError::class, 'not readable' );
} );

it( 'rejects non-image input types with a FeatureError', function (): void {
    expect( fn () => AltTextGenerationAgent::for( null )->run() )
        ->toThrow( FeatureError::class, 'must be an image' );

    expect( fn () => AltTextGenerationAgent::for( '' )->run() )
        ->toThrow( FeatureError::class, 'must be an image' );

    expect( fn () => AltTextGenerationAgent::for( [ 'source' => 'raw', 'value' => 'x' ] )->run() )
        ->toThrow( FeatureError::class, 'unsupported image source' );
} );

it( 'treats short unqualified filenames as paths, not base64', function (): void {
    // `favicon`, `logo.png`, and other bare filenames pass the "only
    // base64 chars" regex but must not be shipped to the model as
    // pre-decoded image data — they need to hit the unreadable-path
    // rejection instead.
    expect( fn () => AltTextGenerationAgent::for( 'favicon' )->run() )
        ->toThrow( FeatureError::class, 'not readable' );
} );

it( 'accepts an explicit base64 payload via the array form', function (): void {
    $this->prompter->queue( [
        'alt_text'   => 'A cat',
        'confidence' => 0.8,
        'warnings'   => [],
    ] );

    AltTextGenerationAgent::for( [
        'source' => 'base64',
        'value'  => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
    ] )->run();

    expect( $this->prompter->calls[0]['message'][1]['source'] )->toBe( 'base64' );
} );
