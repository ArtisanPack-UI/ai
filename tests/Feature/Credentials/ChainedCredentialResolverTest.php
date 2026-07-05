<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;

beforeEach( function (): void {
    foreach ( [
        'ARTISANPACK_AI_PROVIDER',
        'ARTISANPACK_AI_API_KEY',
        'ARTISANPACK_AI_DEFAULT_MODEL',
        'ARTISANPACK_AI_BASE_URL',
        'ARTISANPACK_AI_SEO_SUGGEST_META_DESCRIPTION_MODEL',
    ] as $key ) {
        putenv( $key );
        unset( $_ENV[ $key ], $_SERVER[ $key ] );
    }

    config( [
        'artisanpack.ai.default'       => null,
        'artisanpack.ai.api_key'       => null,
        'artisanpack.ai.default_model' => null,
        'artisanpack.ai.base_url'      => null,
    ] );
} );

it( 'returns null when nothing is configured', function (): void {
    $resolver = app( CredentialResolver::class );

    expect( $resolver->resolve() )->toBeNull();
    expect( $resolver->hasAny() )->toBeFalse();
} );

it( 'resolves from env when provider and key are set', function (): void {
    putenv( 'ARTISANPACK_AI_PROVIDER=anthropic' );
    putenv( 'ARTISANPACK_AI_API_KEY=sk-test-123' );
    putenv( 'ARTISANPACK_AI_DEFAULT_MODEL=haiku' );

    $resolver = app( CredentialResolver::class );

    $creds = $resolver->resolve();

    expect( $creds )->toBeInstanceOf( Credentials::class );
    expect( $creds->provider )->toBe( 'anthropic' );
    expect( $creds->apiKey )->toBe( 'sk-test-123' );
    expect( $creds->defaultModel )->toBe( 'haiku' );
} );

it( 'never returns partial credentials from env', function (): void {
    putenv( 'ARTISANPACK_AI_PROVIDER=anthropic' );

    $resolver = app( CredentialResolver::class );

    expect( $resolver->resolve() )->toBeNull();
} );

it( 'applies per-feature model overrides from env', function (): void {
    putenv( 'ARTISANPACK_AI_PROVIDER=anthropic' );
    putenv( 'ARTISANPACK_AI_API_KEY=sk-test-123' );
    putenv( 'ARTISANPACK_AI_DEFAULT_MODEL=haiku' );
    putenv( 'ARTISANPACK_AI_SEO_SUGGEST_META_DESCRIPTION_MODEL=sonnet' );

    $resolver = app( CredentialResolver::class );

    expect( $resolver->forFeature( 'seo.suggest_meta_description' )->defaultModel )->toBe( 'sonnet' );
    expect( $resolver->resolve()->defaultModel )->toBe( 'haiku' );
} );

it( 'prefers the runtime override over env and store', function (): void {
    putenv( 'ARTISANPACK_AI_PROVIDER=anthropic' );
    putenv( 'ARTISANPACK_AI_API_KEY=env-key' );

    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $override = new Credentials( provider: 'openai', apiKey: 'runtime-key' );

    $resolver->setOverride( $override );

    expect( $resolver->resolve() )->toBe( $override );
} );

it( 'restores the previous override after withOverride() completes', function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );

    $original = new Credentials( provider: 'anthropic', apiKey: 'original' );
    $scoped   = new Credentials( provider: 'openai', apiKey: 'scoped' );

    $resolver->setOverride( $original );

    $inside = $resolver->withOverride( $scoped, fn () => $resolver->resolve()?->apiKey );

    expect( $inside )->toBe( 'scoped' );
    expect( $resolver->resolve()?->apiKey )->toBe( 'original' );
} );

it( 'restores the previous override even when the callback throws', function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );

    $original = new Credentials( provider: 'anthropic', apiKey: 'original' );
    $resolver->setOverride( $original );

    try {
        $resolver->withOverride(
            new Credentials( provider: 'openai', apiKey: 'scoped' ),
            function (): never {
                throw new RuntimeException( 'boom' );
            },
        );
    } catch ( RuntimeException $exception ) {
        // expected
    }

    expect( $resolver->resolve()?->apiKey )->toBe( 'original' );
} );

it( 'prefers the store over env when both are set', function (): void {
    putenv( 'ARTISANPACK_AI_PROVIDER=anthropic' );
    putenv( 'ARTISANPACK_AI_API_KEY=env-key' );

    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $stored   = new Credentials( provider: 'anthropic', apiKey: 'store-key' );

    $resolver->useStore( fn ( ?string $key ) => $stored );

    expect( $resolver->resolve()->apiKey )->toBe( 'store-key' );
} );
