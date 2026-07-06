<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Livewire\Admin\UsageDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    if ( ! class_exists( Livewire::class ) ) {
        $this->markTestSkipped( 'livewire/livewire is not installed.' );
    }

    Carbon::setTestNow( '2026-07-15 12:00:00' );

    // Seed a spread of events that spans the current month plus one row
    // that falls outside the default range so we can verify filtering.
    DB::table( 'ai_usage_events' )->insert( [
        [
            'feature_key'        => 'seo.summary',
            'package'            => 'artisanpack-ui/seo',
            'provider'           => 'anthropic',
            'model'              => 'haiku',
            'input_tokens'       => 100,
            'output_tokens'      => 40,
            'estimated_cost_usd' => 0.12,
            'cache_hit'          => false,
            'created_at'         => '2026-07-05 09:00:00',
        ],
        [
            'feature_key'        => 'seo.summary',
            'package'            => 'artisanpack-ui/seo',
            'provider'           => 'anthropic',
            'model'              => 'haiku',
            'input_tokens'       => 200,
            'output_tokens'      => 80,
            'estimated_cost_usd' => 0.24,
            'cache_hit'          => false,
            'created_at'         => '2026-07-10 09:00:00',
        ],
        [
            'feature_key'        => 'content.digest',
            'package'            => 'artisanpack-ui/content',
            'provider'           => 'anthropic',
            'model'              => 'sonnet',
            'input_tokens'       => 500,
            'output_tokens'      => 200,
            'estimated_cost_usd' => 3.60,
            'cache_hit'          => false,
            'created_at'         => '2026-07-14 09:00:00',
        ],
        [
            // Outside the current month — should not appear in default range.
            'feature_key'        => 'seo.summary',
            'package'            => 'artisanpack-ui/seo',
            'provider'           => 'anthropic',
            'model'              => 'haiku',
            'input_tokens'       => 999,
            'output_tokens'      => 999,
            'estimated_cost_usd' => 9.99,
            'cache_hit'          => false,
            'created_at'         => '2026-05-01 09:00:00',
        ],
    ] );
} );

afterEach( function (): void {
    Carbon::setTestNow();
} );

it( 'initialises the range to the current calendar month', function (): void {
    Livewire::test( UsageDashboard::class )
        ->assertSet( 'from', '2026-07-01' )
        ->assertSet( 'to', '2026-07-31' );
} );

it( 'renders totals that match the seeded events inside the default range', function (): void {
    $component = Livewire::test( UsageDashboard::class );
    $totals    = $component->instance()->totals();

    expect( $totals['input_tokens'] )->toBe( 800 );  // 100 + 200 + 500
    expect( $totals['output_tokens'] )->toBe( 320 ); // 40 + 80 + 200
    expect( $totals['events'] )->toBe( 3 );          // May 1 row excluded
    expect( $totals['cost_usd'] )->toEqualWithDelta( 3.96, 0.0001 ); // 0.12 + 0.24 + 3.60
} );

it( 'shrinks totals when the range excludes some events', function (): void {
    // Narrow the range to July 6-11 → only the second seo.summary row (July 10).
    $component = Livewire::test( UsageDashboard::class )
        ->set( 'from', '2026-07-06' )
        ->set( 'to', '2026-07-11' );

    $totals = $component->instance()->totals();

    expect( $totals['input_tokens'] )->toBe( 200 );
    expect( $totals['output_tokens'] )->toBe( 80 );
    expect( $totals['events'] )->toBe( 1 );
} );

it( 'renders the empty state when no events fall inside the range', function (): void {
    Livewire::test( UsageDashboard::class )
        ->set( 'from', '2026-01-01' )
        ->set( 'to', '2026-01-31' )
        ->assertSee( 'No usage recorded for this range.' );
} );

it( 'opens and closes a per-feature drilldown', function (): void {
    $component = Livewire::test( UsageDashboard::class )
        ->call( 'openDrilldown', 'seo.summary' )
        ->assertSet( 'drilldownFeature', 'seo.summary' );

    // Both seeded seo.summary rows in the default July range, only haiku
    // model (sonnet rows belong to content.digest).
    $rows = $component->instance()->drilldownRows();

    expect( $rows )->toHaveCount( 2 );
    expect( collect( $rows )->every( fn ( $row ) => 'haiku' === $row['model'] ) )->toBeTrue();

    $component
        ->call( 'closeDrilldown' )
        ->assertSet( 'drilldownFeature', null );
} );

it( 'resets the drilldown when the range changes', function (): void {
    Livewire::test( UsageDashboard::class )
        ->call( 'openDrilldown', 'seo.summary' )
        ->assertSet( 'drilldownFeature', 'seo.summary' )
        ->set( 'from', '2026-07-01' )
        ->assertSet( 'drilldownFeature', null );
} );
