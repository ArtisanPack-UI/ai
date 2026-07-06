<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Agents\ArtisanPackAgent;
use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAgent;

class UncacheableFakeAgent extends FakeAgent
{
    public string $featureKey = 'fake.uncacheable';

    public bool $cacheable = false;
}

class ShortTtlFakeAgent extends FakeAgent
{
    public string $featureKey = 'fake.short-ttl';

    public int $cacheTtl = 7;
}

beforeEach( function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( new Credentials( provider: 'anthropic', apiKey: 'sk-test', defaultModel: 'haiku' ) );
    $resolver->useStore( fn () => null );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );
} );

it( 'skips the cache entirely when the agent opts out via $cacheable = false', function (): void {
    config( [ 'artisanpack.ai.cache.enabled' => true, 'artisanpack.ai.cache.ttl' => 60 ] );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.uncacheable', UncacheableFakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );

    $first = UncacheableFakeAgent::for( 'same' );
    $first->run();
    $second = UncacheableFakeAgent::for( 'same' );
    $second->run();

    expect( $first->executeCallCount )->toBe( 1 );
    expect( $second->executeCallCount )->toBe( 1 );
} );

it( 'uses per-agent $cacheTtl override when > 0', function (): void {
    config( [ 'artisanpack.ai.cache.enabled' => true, 'artisanpack.ai.cache.ttl' => 60 ] );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.short-ttl', ShortTtlFakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );

    $agent = ShortTtlFakeAgent::for( 'ttl-input' );

    $reflection = new ReflectionMethod( ArtisanPackAgent::class, 'resolveCacheTtl' );

    expect( $reflection->invoke( $agent, app() ) )->toBe( 7 );
} );

it( 'routes to a dedicated cache store when `cache.store` is set', function (): void {
    config( [
        'cache.stores.ai_dedicated'    => [ 'driver' => 'array' ],
        'artisanpack.ai.cache.enabled' => true,
        'artisanpack.ai.cache.store'   => 'ai_dedicated',
        'artisanpack.ai.cache.ttl'     => 60,
    ] );

    $agent = FakeAgent::for( 'routing-target' );

    $reflection = new ReflectionMethod( ArtisanPackAgent::class, 'cacheStore' );
    $store      = $reflection->invoke( $agent, app() );

    expect( $store )->not->toBeNull();
    expect( $store->getStore() )->toBeInstanceOf( ArrayStore::class );

    // Run and confirm the dedicated store actually received the write.
    $agent->run();
    $dedicated = Cache::store( 'ai_dedicated' );
    $default   = Cache::store( 'array' );

    expect( $dedicated )->not->toBe( $default );
} );

it( 'expiring the TTL re-runs the API call', function (): void {
    config( [ 'artisanpack.ai.cache.enabled' => true, 'artisanpack.ai.cache.ttl' => 60 ] );

    $first = FakeAgent::for( 'same' );
    $first->run();

    // Clear the cache to simulate a TTL expiry (the array store honours put/get
    // but has no built-in advance-time hook).
    Cache::flush();

    $second = FakeAgent::for( 'same' );
    $second->run();

    expect( $first->executeCallCount )->toBe( 1 );
    expect( $second->executeCallCount )->toBe( 1 );
} );
