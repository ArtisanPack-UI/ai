<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Support\CostEstimator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

it( 'prices the current-generation anthropic default models', function ( string $model, float $expected ): void {
    $estimator = new CostEstimator( app( ConfigRepository::class ) );

    // 1,000 input + 1,000 output tokens = one unit of each per-1k rate.
    expect( $estimator->estimate( 'anthropic', $model, 1000, 1000 ) )->toBe( $expected );
} )->with( [
    'claude-haiku-4-5' => [ 'claude-haiku-4-5', 0.006 ],
    'claude-sonnet-5'  => [ 'claude-sonnet-5', 0.018 ],
    'claude-opus-5'    => [ 'claude-opus-5', 0.09 ],
] );

it( 'no longer ships pricing for the retired 3.x-family models', function ( string $model ): void {
    $estimator = new CostEstimator( app( ConfigRepository::class ) );

    expect( $estimator->estimate( 'anthropic', $model, 1000, 1000 ) )->toBe( 0.0 );
} )->with( [
    'claude-3-5-haiku'  => [ 'claude-3-5-haiku' ],
    'claude-3-5-sonnet' => [ 'claude-3-5-sonnet' ],
    'claude-3-opus'     => [ 'claude-3-opus' ],
] );
