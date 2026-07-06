<?php

declare( strict_types=1 );

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.ai.api.middleware', [ 'api', 'auth' ] );

    Gate::define( 'manage_ai_settings', fn ( $user ): bool => true === (bool) ( $user->is_admin ?? false ) );
} );

function usageAdmin(): Authenticatable
{
    $user           = new Authenticatable();
    $user->id       = 1;
    $user->is_admin = true;

    return $user;
}

function insertUsageRow( string $featureKey, int $inputTokens, int $outputTokens, float $cost, ?Carbon $at = null ): void
{
    DB::table( 'ai_usage_events' )->insert( [
        'feature_key'        => $featureKey,
        'package'            => 'artisanpack-ui/ai-fake',
        'provider'           => 'anthropic',
        'model'              => 'haiku',
        'input_tokens'       => $inputTokens,
        'output_tokens'      => $outputTokens,
        'estimated_cost_usd' => $cost,
        'cache_hit'          => false,
        'created_at'         => ( $at ?? Carbon::now() )->toDateTimeString(),
    ] );
}

it( 'returns 401 without authentication', function (): void {
    $this->getJson( '/api/artisanpack-ai/usage' )
        ->assertUnauthorized();
} );

it( 'returns 403 when the caller lacks the ability', function (): void {
    $user           = new Authenticatable();
    $user->id       = 3;
    $user->is_admin = false;

    $this->actingAs( $user )
        ->getJson( '/api/artisanpack-ai/usage' )
        ->assertForbidden();
} );

it( 'returns totals, per-feature breakdown, and daily buckets', function (): void {
    $now = Carbon::now()->startOfDay();

    insertUsageRow( 'seo.meta', 100, 20, 0.005, $now );
    insertUsageRow( 'seo.meta', 200, 30, 0.010, $now );
    insertUsageRow( 'media.alt', 50, 15, 0.002, $now );

    $response = $this->actingAs( usageAdmin() )
        ->getJson( '/api/artisanpack-ai/usage?from=' . $now->toDateString() . '&to=' . $now->toDateString() )
        ->assertOk()
        ->json();

    expect( $response['totals']['input_tokens'] )->toBe( 350 );
    expect( $response['totals']['output_tokens'] )->toBe( 65 );
    expect( $response['totals']['events'] )->toBe( 3 );

    $byFeature = collect( $response['by_feature'] )->keyBy( 'feature_key' );
    expect( $byFeature['seo.meta']['events'] )->toBe( 2 );
    expect( $byFeature['media.alt']['events'] )->toBe( 1 );

    expect( $response['range']['from'] )->toContain( $now->toDateString() );
} );

it( 'returns 422 for a malformed date query param', function (): void {
    $this->actingAs( usageAdmin() )
        ->getJson( '/api/artisanpack-ai/usage?from=not-a-date' )
        ->assertStatus( 422 )
        ->assertJsonValidationErrors( [ 'from' ] );
} );
