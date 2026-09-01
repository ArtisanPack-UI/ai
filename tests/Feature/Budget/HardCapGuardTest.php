<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Exceptions\BudgetExceededException;
use ArtisanPackUI\Ai\Support\BudgetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeAgent;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->createSettingsTable();

    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( new Credentials( provider: 'anthropic', apiKey: 'sk-test', defaultModel: 'haiku' ) );
    $resolver->useStore( fn () => null );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );

    config( [ 'artisanpack.ai.budget.enforce_hard_cap' => true ] );
    app( BudgetSettings::class )->setMonthlyCap( 100.0 );
} );

/**
 * Seed the ai_usage_events table with a single row of `$cost` USD dated now.
 */
function seedGuardCost( float $cost ): void
{
    DB::table( 'ai_usage_events' )->insert( [
        'feature_key'        => 'fake.echo',
        'package'            => 'artisanpack-ui/ai-fake',
        'provider'           => 'anthropic',
        'model'              => 'haiku',
        'input_tokens'       => 0,
        'output_tokens'      => 0,
        'estimated_cost_usd' => $cost,
        'cache_hit'          => false,
        'created_at'         => now()->toDateTimeString(),
    ] );
}

it( 'runs normally when month-to-date spend is under the cap', function (): void {
    seedGuardCost( 42.0 );

    $result = FakeAgent::for( 'hello' )->run();

    expect( $result )->toBe( [ 'echo' => 'hello' ] );
} );

it( 'throws BudgetExceededException when a non-critical agent hits the cap', function (): void {
    seedGuardCost( 100.0 );

    expect( fn () => FakeAgent::for( 'hello' )->run() )
        ->toThrow( BudgetExceededException::class );
} );

it( 'lets a critical agent bypass the cap and logs a warning', function (): void {
    Log::spy();

    seedGuardCost( 150.0 );

    $agent            = FakeAgent::for( 'hello' );
    $agent->critical  = true;

    $result = $agent->run();

    expect( $result )->toBe( [ 'echo' => 'hello' ] )
        ->and( $agent->executeCallCount )->toBe( 1 );

    Log::shouldHaveReceived( 'warning' )
        ->once()
        ->withArgs( function ( string $message, array $context ): bool {
            return str_contains( $message, 'critical agent bypassing hard cap' )
                && 'fake.echo' === $context['feature_key']
                && 150.0 === $context['spent_usd']
                && 100.0 === $context['cap_usd'];
        } );
} );

it( 'does not enforce the cap when enforce_hard_cap is disabled', function (): void {
    config( [ 'artisanpack.ai.budget.enforce_hard_cap' => false ] );
    seedGuardCost( 500.0 );

    $result = FakeAgent::for( 'hello' )->run();

    expect( $result )->toBe( [ 'echo' => 'hello' ] );
} );

it( 'does not enforce the cap when no monthly cap is configured', function (): void {
    app( BudgetSettings::class )->setMonthlyCap( null );
    seedGuardCost( 500.0 );

    $result = FakeAgent::for( 'hello' )->run();

    expect( $result )->toBe( [ 'echo' => 'hello' ] );
} );

it( 'serves a cache hit even after the cap is reached', function (): void {
    config( [ 'artisanpack.ai.cache.enabled' => true ] );

    // Warm the cache while under budget.
    $warm = FakeAgent::for( 'cached' );
    $warm->run();

    expect( $warm->executeCallCount )->toBe( 1 );

    // Now push month-to-date spend over the cap; the cached input must
    // still return without calling execute() or throwing.
    seedGuardCost( 500.0 );

    $second = FakeAgent::for( 'cached' );
    $result = $second->run();

    expect( $result )->toBe( [ 'echo' => 'cached' ] )
        ->and( $second->executeCallCount )->toBe( 0 );
} );
