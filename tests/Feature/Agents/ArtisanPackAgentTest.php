<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Events\AgentUsageRecorded;
use ArtisanPackUI\Ai\Exceptions\FeatureDisabledException;
use ArtisanPackUI\Ai\Exceptions\MissingCredentialsException;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeAgent;

beforeEach( function (): void {
    foreach ( [
        'ARTISANPACK_AI_PROVIDER',
        'ARTISANPACK_AI_API_KEY',
        'ARTISANPACK_AI_DEFAULT_MODEL',
        'ARTISANPACK_AI_BASE_URL',
    ] as $key ) {
        putenv( $key );
        unset( $_ENV[ $key ], $_SERVER[ $key ] );
    }

    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( new Credentials( provider: 'anthropic', apiKey: 'sk-test', defaultModel: 'haiku' ) );
    $resolver->useStore( fn () => null );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );
} );

it( 'runs end-to-end and returns validated output shape', function (): void {
    $result = FakeAgent::for( 'hello world' )->run();

    expect( $result )->toBe( [ 'echo' => 'hello world' ] );
} );

it( 'dispatches AgentUsageRecorded after a successful run', function (): void {
    Event::fake( [ AgentUsageRecorded::class ] );

    FakeAgent::for( 'hi' )->run();

    Event::assertDispatched(
        AgentUsageRecorded::class,
        function ( AgentUsageRecorded $event ): bool {
            return 'fake.echo' === $event->featureKey
                && 'artisanpack-ui/ai-fake' === $event->package
                && 'haiku' === $event->model
                && 42 === $event->inputTokens
                && 7 === $event->outputTokens
                && false === $event->cacheHit;
        },
    );
} );

it( 'throws FeatureDisabledException when the feature is toggled off', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->disable( 'fake.echo' );

    expect( fn () => FakeAgent::for( 'hi' )->run() )
        ->toThrow( FeatureDisabledException::class );
} );

it( 'throws MissingCredentialsException when the toggle is on but creds resolve to null', function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( null );
    $resolver->useStore( fn () => null );

    $agent = FakeAgent::for( 'hi' );

    expect( fn () => $agent->run() )
        ->toThrow( MissingCredentialsException::class );
} );

it( 'resolves the model from runtime override → config → credentials → default', function (): void {
    Event::fake( [ AgentUsageRecorded::class ] );

    FakeAgent::for( 'runtime' )->withModel( 'sonnet' )->run();

    Event::assertDispatched(
        AgentUsageRecorded::class,
        fn ( AgentUsageRecorded $event ): bool => 'sonnet' === $event->model,
    );

    Event::fake( [ AgentUsageRecorded::class ] );

    // Dot-notation feature keys must NOT be treated as nested config paths.
    config( [ 'artisanpack.ai.features' => [ 'fake.echo' => [ 'model' => 'opus' ] ] ] );

    FakeAgent::for( 'from-config' )->run();

    Event::assertDispatched(
        AgentUsageRecorded::class,
        fn ( AgentUsageRecorded $event ): bool => 'opus' === $event->model,
    );

    config( [ 'artisanpack.ai.features' => [] ] );
    Event::fake( [ AgentUsageRecorded::class ] );

    // Credentials-supplied default (e.g. from ARTISANPACK_AI_FAKE_ECHO_MODEL)
    // wins over the agent's fallback $defaultModel.
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( new Credentials(
        provider: 'anthropic',
        apiKey: 'sk-test',
        defaultModel: 'gemini-flash',
    ) );

    FakeAgent::for( 'from-credentials' )->run();

    Event::assertDispatched(
        AgentUsageRecorded::class,
        fn ( AgentUsageRecorded $event ): bool => 'gemini-flash' === $event->model,
    );

    Event::fake( [ AgentUsageRecorded::class ] );
    $resolver->setOverride( new Credentials( provider: 'anthropic', apiKey: 'sk-test' ) );

    FakeAgent::for( 'from-default' )->run();

    Event::assertDispatched(
        AgentUsageRecorded::class,
        fn ( AgentUsageRecorded $event ): bool => 'haiku' === $event->model,
    );
} );

it( 'serves a cache hit without calling execute() when cache is enabled', function (): void {
    config( [ 'artisanpack.ai.cache.enabled' => true, 'artisanpack.ai.cache.ttl' => 60 ] );

    $first = FakeAgent::for( 'same-input' );
    $first->run();

    expect( $first->executeCallCount )->toBe( 1 );

    Event::fake( [ AgentUsageRecorded::class ] );

    $second = FakeAgent::for( 'same-input' );
    $result = $second->run();

    expect( $second->executeCallCount )->toBe( 0 );
    expect( $result )->toBe( [ 'echo' => 'same-input' ] );

    Event::assertDispatched(
        AgentUsageRecorded::class,
        fn ( AgentUsageRecorded $event ): bool => true === $event->cacheHit,
    );
} );

it( 'toggles streaming via ->withStreaming()', function (): void {
    $agent = FakeAgent::for( 'hi' );

    expect( $agent->isStreaming() )->toBeFalse();

    $agent->withStreaming();

    expect( $agent->isStreaming() )->toBeTrue();
} );

it( 'threads ARTISANPACK_AI_{FEATURE}_MODEL through resolver into the run', function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( null );
    $resolver->useStore( fn () => null );

    putenv( 'ARTISANPACK_AI_PROVIDER=anthropic' );
    putenv( 'ARTISANPACK_AI_API_KEY=sk-test' );
    putenv( 'ARTISANPACK_AI_DEFAULT_MODEL=haiku' );
    putenv( 'ARTISANPACK_AI_FAKE_ECHO_MODEL=sonnet' );

    Event::fake( [ AgentUsageRecorded::class ] );

    FakeAgent::for( 'hi' )->run();

    Event::assertDispatched(
        AgentUsageRecorded::class,
        fn ( AgentUsageRecorded $event ): bool => 'sonnet' === $event->model,
    );

    putenv( 'ARTISANPACK_AI_PROVIDER' );
    putenv( 'ARTISANPACK_AI_API_KEY' );
    putenv( 'ARTISANPACK_AI_DEFAULT_MODEL' );
    putenv( 'ARTISANPACK_AI_FAKE_ECHO_MODEL' );
} );

it( 'raises when cacheFingerprint cannot fingerprint the input and cache is on', function (): void {
    config( [ 'artisanpack.ai.cache.enabled' => true ] );

    $agent = FakeAgent::for( new stdClass() );

    expect( fn () => $agent->run() )->toThrow( InvalidArgumentException::class );
} );

it( 'produces stable cache keys for scalar and array-of-scalar inputs', function (): void {
    config( [ 'artisanpack.ai.cache.enabled' => true, 'artisanpack.ai.cache.ttl' => 60 ] );

    // Same input, second call → cache hit.
    $first = FakeAgent::for( [ 'a' => 1, 'b' => 'two' ] );
    $first->run();
    expect( $first->executeCallCount )->toBe( 1 );

    // Reordered keys must still hit (normalised via ksort).
    $second = FakeAgent::for( [ 'b' => 'two', 'a' => 1 ] );
    $second->run();
    expect( $second->executeCallCount )->toBe( 0 );
} );

it( 'uses the runtime credential override in preference to the resolver', function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( null );
    $resolver->useStore( fn () => null );

    $override = new Credentials( provider: 'openai', apiKey: 'sk-runtime', defaultModel: 'gpt-4o' );

    $result = FakeAgent::for( 'hi' )
        ->withCredentials( $override )
        ->run();

    expect( $result )->toBe( [ 'echo' => 'hi' ] );
} );

it( 'resolvedInstructions() returns the class default when no override is set', function (): void {
    $agent = FakeAgent::for( 'hi' );

    expect( $agent->resolvedInstructions() )->toBe( 'Echo the input.' );
} );

it( 'resolvedInstructions() prefers config over the class default', function (): void {
    config( [ 'artisanpack.ai.features' => [ 'fake.echo' => [ 'instructions' => 'Config prompt.' ] ] ] );

    $agent = FakeAgent::for( 'hi' );

    expect( $agent->resolvedInstructions() )->toBe( 'Config prompt.' );
} );

it( 'resolvedInstructions() falls back to class default when the config override is empty', function (): void {
    config( [ 'artisanpack.ai.features' => [ 'fake.echo' => [ 'instructions' => '' ] ] ] );

    $agent = FakeAgent::for( 'hi' );

    expect( $agent->resolvedInstructions() )->toBe( 'Echo the input.' );
} );

it( 'resolvedInstructions() prefers FeatureSettings overrides above config and class default', function (): void {
    $this->createSettingsTable();

    config( [ 'artisanpack.ai.features' => [ 'fake.echo' => [ 'instructions' => 'Config prompt.' ] ] ] );

    /** @var ArtisanPackUI\Ai\Support\FeatureSettings $settings */
    $settings = app( ArtisanPackUI\Ai\Support\FeatureSettings::class );
    $settings->resetSettingsTableProbe();
    $settings->setInstructions( 'fake.echo', 'Persisted prompt.' );

    $agent = FakeAgent::for( 'hi' );

    expect( $agent->resolvedInstructions() )->toBe( 'Persisted prompt.' );
} );

it( 'resolveModel() prefers FeatureSettings overrides above config and credentials', function (): void {
    Event::fake( [ AgentUsageRecorded::class ] );

    $this->createSettingsTable();

    /** @var ArtisanPackUI\Ai\Support\FeatureSettings $settings */
    $settings = app( ArtisanPackUI\Ai\Support\FeatureSettings::class );
    $settings->resetSettingsTableProbe();
    $settings->setModel( 'fake.echo', 'settings-model' );

    // Config would otherwise win — verify FeatureSettings takes precedence.
    config( [ 'artisanpack.ai.features' => [ 'fake.echo' => [ 'model' => 'config-model' ] ] ] );

    FakeAgent::for( 'from-settings' )->run();

    Event::assertDispatched(
        AgentUsageRecorded::class,
        fn ( AgentUsageRecorded $event ): bool => 'settings-model' === $event->model,
    );
} );

it( 'FeatureSettings model override is bypassed by an explicit withModel()', function (): void {
    Event::fake( [ AgentUsageRecorded::class ] );

    $this->createSettingsTable();

    /** @var ArtisanPackUI\Ai\Support\FeatureSettings $settings */
    $settings = app( ArtisanPackUI\Ai\Support\FeatureSettings::class );
    $settings->resetSettingsTableProbe();
    $settings->setModel( 'fake.echo', 'settings-model' );

    FakeAgent::for( 'runtime' )->withModel( 'runtime-model' )->run();

    Event::assertDispatched(
        AgentUsageRecorded::class,
        fn ( AgentUsageRecorded $event ): bool => 'runtime-model' === $event->model,
    );
} );
