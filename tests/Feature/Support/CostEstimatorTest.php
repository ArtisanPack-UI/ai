<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Support\CostEstimator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

it( 'prices the current-generation anthropic default models', function ( string $model, float $expected ): void {
    $estimator = new CostEstimator( app( ConfigRepository::class ) );

    // 1,000 input + 1,000 output tokens = one unit of each per-1k rate.
    expect( $estimator->estimate( 'anthropic', $model, 1000, 1000 ) )->toBe( $expected );
} )->with( [
    'claude-haiku-4-5' => [ 'claude-haiku-4-5', 0.006 ],
    'claude-sonnet-5'  => [ 'claude-sonnet-5', 0.012 ],
    'claude-opus-5'    => [ 'claude-opus-5', 0.03 ],
] );

it( 'prices a known model without falling back or warning', function (): void {
    Log::spy();

    $estimator = new CostEstimator( app( ConfigRepository::class ) );

    expect( $estimator->estimate( 'anthropic', 'claude-sonnet-5', 1000, 1000 ) )->toBe( 0.012 );

    Log::shouldNotHaveReceived( 'warning' );
} );

it( 'falls back to the provider\'s highest known rate for a retired or unmapped model', function ( string $model ): void {
    Log::spy();

    $estimator = new CostEstimator( app( ConfigRepository::class ) );

    // No explicit entry, so it should conservatively price at anthropic's
    // highest known rate (opus: 0.005 input + 0.025 output per 1k) rather
    // than silently estimating $0 and under-counting spend.
    expect( $estimator->estimate( 'anthropic', $model, 1000, 1000 ) )->toBe( 0.03 );

    Log::shouldHaveReceived( 'warning' )
        ->once()
        ->withArgs( fn ( string $message, array $context ): bool => 'anthropic' === $context['provider']
            && $context['model'] === $model );
} )->with( [
    'claude-3-5-haiku'  => [ 'claude-3-5-haiku' ],
    'claude-3-5-sonnet' => [ 'claude-3-5-sonnet' ],
    'claude-3-opus'     => [ 'claude-3-opus' ],
] );

it( 'estimates $0 without a warning for a provider that has no priced entries', function (): void {
    Log::spy();

    $estimator = new CostEstimator( app( ConfigRepository::class ) );

    // Ollama models run locally and are priced at $0; an unmapped one must
    // not warn or invent a non-zero rate.
    expect( $estimator->estimate( 'ollama', 'mystery-model:latest', 1000, 1000 ) )->toBe( 0.0 );

    Log::shouldNotHaveReceived( 'warning' );
} );

it( 'estimates $0 without a warning for an entirely unknown provider', function (): void {
    Log::spy();

    $estimator = new CostEstimator( app( ConfigRepository::class ) );

    expect( $estimator->estimate( 'not-a-provider', 'some-model', 1000, 1000 ) )->toBe( 0.0 );

    Log::shouldNotHaveReceived( 'warning' );
} );
